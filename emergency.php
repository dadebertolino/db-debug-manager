<?php
/**
 * DB Debug Manager — Emergency Standalone Access
 *
 * Accesso al debug del sito quando WordPress non si avvia.
 * Questo file NON carica wp-load.php: si connette al DB direttamente via PDO.
 *
 * URL: https://<sito>/wp-content/plugins/db-debug-manager/emergency.php
 *
 * Sicurezza:
 * - Password hash (password_hash) letta dalla tabella wp_options
 * - Rate limit 5 tentativi / 15 min per IP
 * - Log di tutti i tentativi
 * - Sessione 30 minuti, cookie HttpOnly+SameSite
 * - Deve essere esplicitamente abilitato dall'admin (OPTION_ENABLED=true)
 *
 * @author Davide Bertolino
 */

// ========= HARDENING =========
// Niente output se chiamato da CLI o da include sbagliato.
if (php_sapi_name() === 'cli') { die('CLI not allowed.'); }

// Errori silenziati nel response ma loggati.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Session con scope ristretto al path del plugin.
// 1.4.0: modalità stretta (PHP rifiuta ID di sessione scelti dal client),
// solo cookie, durata lato server non inferiore a quella dichiarata.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if ((int) ini_get('session.gc_maxlifetime') < 1800) {
    ini_set('session.gc_maxlifetime', '1800');
}
session_name('dbdm_emergency');
session_set_cookie_params(array(
    'lifetime' => 1800,
    'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
));
session_start();

// ========= COSTANTI =========
define('DBDM_EMERGENCY_SESSION_TTL', 1800);  // 30 min
define('DBDM_EMERGENCY_PLUGIN_DIR', __DIR__ . '/');
// wp-content: due livelli sopra la cartella del plugin.
define('DBDM_EMERGENCY_CONTENT_DIR', dirname(dirname(__DIR__)));

require_once __DIR__ . '/inc/class-standalone-config.php';
require_once __DIR__ . '/inc/class-emergency-guard.php';

// ========= CARTELLA PRIVATA =========
// Risolta dopo la connessione al DB, dal percorso e dal token salvati in
// wp_options (vedi VERIFICA ATTIVAZIONE). 1.4.0: nessun ripiego su una
// cartella dal nome prevedibile: senza cartella privata l'accesso è negato.
$GLOBALS['dbdm_em_private_dir'] = '';

function dbdm_em_private_dir() {
    return $GLOBALS['dbdm_em_private_dir'];
}

function dbdm_em_private_path($file) {
    return dbdm_em_private_dir() . $file;
}

/**
 * Cartella privata creata dal pannello: dal percorso salvato, o dal token
 * nella posizione standard (wp-content/dbdm-private-{token}/). Il nome deve
 * avere la forma attesa: un valore manomesso nel DB non può indicare
 * un'altra cartella.
 *
 * @return string Percorso con slash finale, '' se non trovata.
 */
function dbdm_em_resolve_private_dir($path, $token) {
    $candidates = array();
    if (is_string($path) && $path !== '') $candidates[] = rtrim($path, '/');
    if (is_string($token) && preg_match('/^[a-f0-9]{16}$/', $token)) {
        $candidates[] = DBDM_EMERGENCY_CONTENT_DIR . '/dbdm-private-' . $token;
    }
    foreach ($candidates as $dir) {
        if (preg_match('/^dbdm-private-[a-f0-9]{16}$/', basename($dir)) && is_dir($dir)) {
            return $dir . '/';
        }
    }
    return '';
}

// ========= HELPER =========
// Flag impostato dopo la lettura delle opzioni dal DB (vedi VERIFICA ATTIVAZIONE).
$GLOBALS['dbdm_em_trust_proxy'] = false;

function dbdm_em_ip() {
    return DBDM_Emergency_Guard::client_ip($_SERVER, !empty($GLOBALS['dbdm_em_trust_proxy']));
}

function dbdm_em_log($event, $detail = '') {
    $line = sprintf(
        "[%s] %s | IP=%s | UA=%s | %s\n",
        gmdate('Y-m-d H:i:s'),
        $event,
        dbdm_em_ip(),
        substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '-', 0, 120),
        $detail
    );
    @file_put_contents(dbdm_em_private_path('emergency-access.log'), $line, FILE_APPEND | LOCK_EX);
}

function dbdm_em_csrf_token() {
    if (empty($_SESSION['dbdm_csrf'])) {
        $_SESSION['dbdm_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['dbdm_csrf'];
}

function dbdm_em_csrf_check() {
    $posted = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    return !empty($_SESSION['dbdm_csrf']) && is_string($_SESSION['dbdm_csrf']) && hash_equals($_SESSION['dbdm_csrf'], $posted);
}

function dbdm_em_is_authed() {
    if (empty($_SESSION['dbdm_authed'])) return false;
    if (empty($_SESSION['dbdm_auth_time'])) return false;
    // 1.4.0: la sessione decade se password, abilitazione o stato del
    // plugin sono cambiati dopo il login (impronta legata all'epoca).
    if (empty($_SESSION['dbdm_fingerprint']) || !hash_equals((string) $GLOBALS['dbdm_em_fingerprint'], (string) $_SESSION['dbdm_fingerprint'])) {
        $_SESSION = array();
        return false;
    }
    if (time() - $_SESSION['dbdm_auth_time'] > DBDM_EMERGENCY_SESSION_TTL) {
        $_SESSION = array();
        return false;
    }
    return true;
}

// ========= LOAD CONFIG =========
$config_path = DBDM_Standalone_Config::find_wp_config(__DIR__);
if (!$config_path) {
    dbdm_em_render_error('wp-config.php non trovato.');
    exit;
}
$creds = DBDM_Standalone_Config::parse_credentials($config_path);
if (!$creds) {
    dbdm_em_render_error('Impossibile leggere le credenziali da wp-config.php.');
    exit;
}
$pdo = DBDM_Standalone_Config::connect($creds);
if (!$pdo) {
    dbdm_em_render_error('Connessione al database fallita.');
    exit;
}
$prefix = $creds['prefix'];

// ========= VERIFICA ATTIVAZIONE =========
function dbdm_em_get_option($pdo, $prefix, $name, $default = null) {
    $stmt = $pdo->prepare("SELECT option_value FROM `{$prefix}options` WHERE option_name = :n LIMIT 1");
    $stmt->execute(array(':n' => $name));
    $v = $stmt->fetchColumn();
    if ($v === false) return $default;
    // Valore serializzato: decodificato senza istanziare oggetti.
    return DBDM_Emergency_Guard::maybe_unserialize($v);
}

$enabled = dbdm_em_get_option($pdo, $prefix, 'dbdm_emergency_enabled');
if (!$enabled || $enabled === '0' || $enabled === false) {
    dbdm_em_render_error('L\'accesso emergency è disattivato. Abilitalo dalla dashboard di WordPress: Strumenti → Debug Manager → Emergency.');
    exit;
}

$stored_hash = dbdm_em_get_option($pdo, $prefix, 'dbdm_emergency_hash');
if (empty($stored_hash)) {
    dbdm_em_render_error('Nessuna password emergency configurata.');
    exit;
}

// Modalità proxy fidato: da qui in poi dbdm_em_ip() può usare gli header proxy.
$GLOBALS['dbdm_em_trust_proxy'] = (bool) dbdm_em_get_option($pdo, $prefix, 'dbdm_emergency_trust_proxy', false);

// Sessioni legate a password ed epoca (vedi dbdm_em_is_authed()).
$GLOBALS['dbdm_em_fingerprint'] = DBDM_Emergency_Guard::session_fingerprint(
    $stored_hash,
    dbdm_em_get_option($pdo, $prefix, 'dbdm_emergency_epoch', '')
);

// Cartella privata (log accessi, limite tentativi, snapshot, backup).
$GLOBALS['dbdm_em_private_dir'] = dbdm_em_resolve_private_dir(
    dbdm_em_get_option($pdo, $prefix, 'dbdm_private_dir_path', ''),
    dbdm_em_get_option($pdo, $prefix, 'dbdm_private_dir_token', '')
);
if (dbdm_em_private_dir() === '') {
    dbdm_em_render_error('Cartella privata del plugin non trovata. Apri una volta il pannello Debug Manager da WordPress per crearla.');
    exit;
}
if (!is_writable(dbdm_em_private_dir())) {
    // Senza cartella scrivibile non c'è limite ai tentativi: accesso negato.
    dbdm_em_render_error('La cartella privata del plugin non è scrivibile dal server web: accesso negato.');
    exit;
}
$GLOBALS['dbdm_em_rl_file'] = dbdm_em_private_path('emergency-ratelimit.json');

// ========= ROUTING =========
$ip = dbdm_em_ip();
$rl_key = DBDM_Emergency_Guard::rate_key($ip);
$action = isset($_REQUEST['a']) && is_string($_REQUEST['a']) ? preg_replace('/[^a-z_]/', '', $_REQUEST['a']) : '';

// Logout.
if ($action === 'logout') {
    $_SESSION = array();
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Login POST.
if (!dbdm_em_is_authed() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!dbdm_em_csrf_check()) {
        dbdm_em_log('LOGIN_CSRF_FAIL');
        dbdm_em_render_login('Token di sessione non valido. Ricarica la pagina.');
        exit;
    }
    // 1.4.0: il tentativo viene contato PRIMA della verifica, sotto lock:
    // anche con richieste parallele non si superano i 5 tentativi.
    $attempt = DBDM_Emergency_Guard::reserve_attempt($GLOBALS['dbdm_em_rl_file'], $rl_key);
    if ($attempt['error'] !== '') {
        dbdm_em_log('LOGIN_REFUSED', 'rate limit non disponibile');
        dbdm_em_render_login('Limite dei tentativi non disponibile: accesso negato.');
        exit;
    }
    if (!$attempt['allowed']) {
        dbdm_em_log('LOGIN_BLOCKED', 'IP locked, ' . $attempt['retry_after'] . 's remaining');
        dbdm_em_render_login('Troppi tentativi. Riprova tra ' . ceil($attempt['retry_after'] / 60) . ' minuti.');
        exit;
    }
    $password = is_string($_POST['password']) ? $_POST['password'] : '';
    if ($password !== '' && password_verify($password, $stored_hash)) {
        // 1.4.0: nuovo ID di sessione al login (niente session fixation).
        session_regenerate_id(true);
        $_SESSION['dbdm_authed'] = true;
        $_SESSION['dbdm_auth_time'] = time();
        $_SESSION['dbdm_fingerprint'] = $GLOBALS['dbdm_em_fingerprint'];
        unset($_SESSION['dbdm_csrf']);
        DBDM_Emergency_Guard::reset($GLOBALS['dbdm_em_rl_file'], $rl_key);
        dbdm_em_log('LOGIN_SUCCESS');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        dbdm_em_log('LOGIN_FAIL');
        dbdm_em_render_login('Password errata.');
        exit;
    }
}

if (!dbdm_em_is_authed()) {
    $lock = DBDM_Emergency_Guard::locked_for($GLOBALS['dbdm_em_rl_file'], $rl_key);
    dbdm_em_render_login($lock > 0 ? 'IP bloccato. Riprova tra ' . ceil($lock / 60) . ' minuti.' : '');
    exit;
}

// ========= AZIONI AUTENTICATE =========

// Tutte le azioni che modificano stato richiedono CSRF + POST.
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$notices = array();

if ($is_post && dbdm_em_csrf_check()) {
    try {
        switch ($action) {
            case 'disable_all_plugins':
                $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'active_plugins'")
                    ->execute(array(':v' => serialize(array())));
                dbdm_em_log('ACTION', 'disable_all_plugins');
                $notices[] = array('ok', 'Tutti i plugin sono stati disattivati.');
                break;

            case 'disable_plugin':
                $slug = isset($_POST['plugin']) ? $_POST['plugin'] : '';
                if ($slug) {
                    $stmt = $pdo->prepare("SELECT option_value FROM `{$prefix}options` WHERE option_name = 'active_plugins'");
                    $stmt->execute();
                    $current = DBDM_Emergency_Guard::maybe_unserialize($stmt->fetchColumn());
                    if (is_array($current)) {
                        $new = array_values(array_filter($current, function ($p) use ($slug) {
 return $p !== $slug;
}));
                        $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'active_plugins'")
                            ->execute(array(':v' => serialize($new)));
                        dbdm_em_log('ACTION', 'disable_plugin: ' . $slug);
                        $notices[] = array('ok', 'Plugin disattivato: ' . $slug);
                    }
                }
                break;

            case 'switch_to_default_theme':
                // Trova il tema default più recente disponibile.
                $themes_dir = dirname(dirname(__DIR__)) . '/themes';
                $candidates = array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty');
                $target = null;
                foreach ($candidates as $t) {
                    if (is_dir($themes_dir . '/' . $t)) { $target = $t;
break; }
                }
                if (!$target) {
                    // Fallback: primo tema trovato.
                    $theme_entries = @scandir($themes_dir);
                    if (!is_array($theme_entries)) {
                        $theme_entries = array();
                    }
                    foreach ($theme_entries as $entry) {
                        if ($entry[0] !== '.' && is_dir($themes_dir . '/' . $entry) && file_exists($themes_dir . '/' . $entry . '/style.css')) {
                            $target = $entry;
break;
                        }
                    }
                }
                if ($target) {
                    $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'template'")->execute(array(':v' => $target));
                    $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'stylesheet'")->execute(array(':v' => $target));
                    dbdm_em_log('ACTION', 'switch_theme: ' . $target);
                    $notices[] = array('ok', 'Tema cambiato a: ' . $target);
                } else {
                    $notices[] = array('err', 'Nessun tema alternativo trovato in /wp-content/themes.');
                }
                break;

            case 'clear_transients':
                $deleted = $pdo->exec("DELETE FROM `{$prefix}options` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'");
                dbdm_em_log('ACTION', 'clear_transients: ' . (int) $deleted);
                $notices[] = array('ok', (int) $deleted . ' transient eliminati.');
                break;

            case 'toggle_const':
                $name = isset($_POST['const']) ? $_POST['const'] : '';
                $value = !empty($_POST['enable']);
                $managed = array('WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES');
                if (in_array($name, $managed, true)) {
                    $r = dbdm_em_toggle_constant($config_path, $name, $value);
                    if ($r === true) {
                        dbdm_em_log('ACTION', 'toggle_const: ' . $name . ' = ' . ($value ? 'true' : 'false'));
                        $notices[] = array('ok', $name . ' impostata a ' . ($value ? 'true' : 'false'));
                    } else {
                        $notices[] = array('err', 'Errore: ' . $r);
                    }
                }
                break;

            case 'clear_log':
                $log_path = dbdm_em_debug_log_path($config_path);
                if (file_exists($log_path) && is_writable($log_path)) {
                    file_put_contents($log_path, '');
                    dbdm_em_log('ACTION', 'clear_debug_log');
                    $notices[] = array('ok', 'debug.log svuotato.');
                } else {
                    $notices[] = array('err', 'debug.log non scrivibile o assente.');
                }
                break;

            case 'restore_snapshot':
                $snap_id = isset($_POST['snap_id']) ? $_POST['snap_id'] : '';
                $restore_plugins = !empty($_POST['restore_plugins']);
                $restore_theme   = !empty($_POST['restore_theme']);
                $snaps_file = dbdm_em_private_path('snapshots.json');

                if (!$snap_id || (!$restore_plugins && !$restore_theme)) {
                    $notices[] = array('err', 'Parametri ripristino incompleti.');
                    break;
                }
                if (!file_exists($snaps_file)) {
                    $notices[] = array('err', 'Nessuno snapshot disponibile.');
                    break;
                }
                $all_snaps = json_decode(@file_get_contents($snaps_file), true);
                $target = null;
                if (is_array($all_snaps)) {
                    foreach ($all_snaps as $s) {
                        if (isset($s['id']) && $s['id'] === $snap_id) { $target = $s;
break; }
                    }
                }
                if (!$target) {
                    $notices[] = array('err', 'Snapshot non trovato.');
                    break;
                }

                // Ripristina plugin attivi. 1.4.0: solo percorsi validi di
                // plugin ancora installati (niente cartelle, risalite, non-stringhe).
                if ($restore_plugins && isset($target['active_plugins'])) {
                    list($valid_plugins, $missing) = DBDM_Emergency_Guard::restorable_plugins($target['active_plugins'], dirname(__DIR__));
                    $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'active_plugins'")
                        ->execute(array(':v' => serialize($valid_plugins)));
                    dbdm_em_log('ACTION', 'restore_snapshot plugins: ' . $snap_id . ', ' . count($valid_plugins) . ' plugins');
                    $notices[] = array('ok', 'Plugin attivi ripristinati: ' . count($valid_plugins) . ' plugin.');
                    if (!empty($missing)) {
                        $notices[] = array('err', 'Non ripristinati (non più presenti o non validi): ' . implode(', ', $missing));
                    }
                }

                // Ripristina tema. 1.4.0: il tema padre viene letto dal tema e
                // deve essere installato, altrimenti il sito resterebbe bianco.
                if ($restore_theme && isset($target['stylesheet'])) {
                    $theme = DBDM_Emergency_Guard::restorable_theme($target['stylesheet'], DBDM_EMERGENCY_CONTENT_DIR . '/themes');
                    if ($theme['ok']) {
                        $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'stylesheet'")->execute(array(':v' => $theme['stylesheet']));
                        $pdo->prepare("UPDATE `{$prefix}options` SET option_value = :v WHERE option_name = 'template'")->execute(array(':v' => $theme['template']));
                        dbdm_em_log('ACTION', 'restore_snapshot theme: ' . $theme['stylesheet']);
                        $notices[] = array('ok', 'Tema ripristinato: ' . $theme['stylesheet']);
                    } else {
                        $notices[] = array('err', 'Tema non ripristinato (' . $theme['error'] . '): ' . $theme['stylesheet']);
                    }
                }
                break;
        }
    } catch (Exception $e) {
        $notices[] = array('err', 'Errore: ' . $e->getMessage());
        dbdm_em_log('ACTION_ERR', $action . ': ' . $e->getMessage());
    }
}

// Toggle costante: delega alla logica condivisa in DBDM_Standalone_Config,
// che include la validazione sintattica PHP pre-scrittura (in emergency un
// wp-config rotto sarebbe il caso peggiore possibile).
function dbdm_em_toggle_constant($config_path, $name, $value) {
    global $pdo, $prefix;
    $source = $value ? 'true' : 'false';
    // 1.4.0: WP_DEBUG_LOG attivato va nella cartella privata (o sul percorso
    // personalizzato ricordato dal pannello), non in wp-content/debug.log
    // raggiungibile da chiunque.
    if ($name === 'WP_DEBUG_LOG' && $value) {
        $saved = dbdm_em_get_option($pdo, $prefix, 'dbdm_debug_log_path', '');
        $path  = is_string($saved) && $saved !== '' && !in_array(strtolower($saved), array('1', 'true'), true)
            ? $saved : dbdm_em_private_path('debug.log');
        $source = var_export($path, true);
    }
    $result = DBDM_Standalone_Config::set_constants(
        $config_path,
        array($name => $source),
        dbdm_em_private_path('wp-config.dbdm-bak')
    );
    // Rimuove l'eventuale backup legacy esposto (versioni <= 1.2.0).
    if (file_exists($config_path . '.dbdm-bak')) {
        @unlink($config_path . '.dbdm-bak');
    }
    return $result;
}

/**
 * Percorso del debug.log come lo usa WordPress con il wp-config.php
 * attuale: un percorso in WP_DEBUG_LOG, altrimenti wp-content/debug.log.
 */
function dbdm_em_debug_log_path($config_path) {
    $content = @file_get_contents($config_path);
    $defines = $content ? DBDM_Standalone_Config::effective_defines($content) : array();
    $value   = isset($defines['WP_DEBUG_LOG']) ? $defines['WP_DEBUG_LOG'] : null;
    if (is_string($value) && $value !== '' && !in_array(strtolower($value), array('1', 'true'), true)) {
        return $value;
    }
    return DBDM_EMERGENCY_CONTENT_DIR . '/debug.log';
}

// ========= RACCOLTA DATI PER VISTA =========
$debug_log_path = dbdm_em_debug_log_path($config_path);
$debug_log_content = '';
$debug_log_size = 0;
if (file_exists($debug_log_path)) {
    $debug_log_size = filesize($debug_log_path);
    $fp = @fopen($debug_log_path, 'rb');
    if ($fp) {
        $chunk_size = 65536;
        $seek = max(0, $debug_log_size - $chunk_size);
        fseek($fp, $seek);
        $debug_log_content = fread($fp, $chunk_size);
        fclose($fp);
    }
}

// Plugin attivi.
$active_plugins = array();
$stmt = $pdo->prepare("SELECT option_value FROM `{$prefix}options` WHERE option_name = 'active_plugins'");
$stmt->execute();
$ap = DBDM_Emergency_Guard::maybe_unserialize($stmt->fetchColumn());
if (is_array($ap)) $active_plugins = $ap;

// Tema attuale.
$cur_theme = dbdm_em_get_option($pdo, $prefix, 'stylesheet', '-');

// Costanti da wp-config corrente.
$wp_config_content = @file_get_contents($config_path);
$consts_status = array();
foreach (array('WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES') as $c) {
    // Cattura bool ma anche stringhe (WP_DEBUG_LOG può essere un path custom).
    if (preg_match('/^[ \t]*define\s*\(\s*[\'"]' . preg_quote($c, '/') . '[\'"]\s*,\s*(true|false|\'[^\']*\'|"[^"]*")\s*\)\s*;/mi', $wp_config_content, $m)) {
        $raw = strtolower(trim($m[1]));
        if ($raw === 'true') {
            $consts_status[$c] = true;
        } elseif ($raw === 'false') {
            $consts_status[$c] = false;
        } else {
            // Stringa: non vuota = attiva (path custom). Vuota = false.
            $consts_status[$c] = trim($m[1], '\'"') !== '';
        }
    } else {
        $consts_status[$c] = null;
    }
}

// Snapshots disponibili.
$snapshots = array();
$snaps_file = dbdm_em_private_path('snapshots.json');
if (file_exists($snaps_file)) {
    $raw = @file_get_contents($snaps_file);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $snapshots = array_reverse($decoded);
    }
}

// PHP error log del server.
$php_error_log = ini_get('error_log');
$php_log_content = '';
if ($php_error_log && file_exists($php_error_log) && is_readable($php_error_log)) {
    $s = filesize($php_error_log);
    $fp = @fopen($php_error_log, 'rb');
    if ($fp) {
        fseek($fp, max(0, $s - 32768));
        $php_log_content = fread($fp, 32768);
        fclose($fp);
    }
}

// ========= RENDER =========
dbdm_em_render_dashboard($notices, $debug_log_content, $debug_log_size, $active_plugins, $cur_theme, $consts_status, $php_error_log, $php_log_content, $snapshots);

// ================================================================
// RENDER FUNCTIONS
// ================================================================

function dbdm_em_header($title) {
    $csrf = dbdm_em_csrf_token();
    ?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($title, ENT_QUOTES); ?></title>
<style>
:root {
    --bg:#0f1419; --panel:#1a1f2e; --panel-b:#2a3142;
    --text:#e8e8e8; --muted:#8b95a7; --border:#2a3142;
    --primary:#4a9eff; --danger:#ff5c5c; --warn:#ffb74a; --ok:#4ade80;
    --radius:8px;
}
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
.wrap { max-width:1100px; margin:0 auto; padding:24px 20px; }
h1 { margin:0 0 4px; font-size:20px; font-weight:600; }
h2 { margin:0 0 12px; font-size:15px; font-weight:600; color:var(--text); }
.header { display:flex; justify-content:space-between; align-items:center; padding-bottom:20px; border-bottom:1px solid var(--border); margin-bottom:20px; }
.header small { color:var(--muted); font-size:12px; }
.panel { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px; margin-bottom:16px; }
.panel-danger { border-color:var(--danger); }
.grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:720px){.grid{grid-template-columns:1fr;}}
.btn { display:inline-block; background:var(--panel-b); color:var(--text); border:1px solid var(--border); padding:7px 14px; border-radius:6px; font-size:13px; cursor:pointer; text-decoration:none; font-family:inherit; }
.btn:hover { background:#343c52; }
.btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; }
.btn-primary:hover { background:#3a8fe0; }
.btn-danger { background:var(--danger); border-color:var(--danger); color:#fff; }
.btn-danger:hover { background:#e04040; }
.btn-warn { background:var(--warn); border-color:var(--warn); color:#000; }
.btn-sm { padding:3px 10px; font-size:12px; }
.row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; }
.log { background:#0a0d14; color:#b8c1d1; border:1px solid var(--border); border-radius:6px; padding:12px; font:12px/1.5 'Menlo',monospace; max-height:400px; overflow:auto; white-space:pre-wrap; word-break:break-word; margin:0; }
.tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; }
.tag-ok { background:rgba(74,222,128,0.15); color:var(--ok); }
.tag-warn { background:rgba(255,183,74,0.15); color:var(--warn); }
.tag-off { background:rgba(139,149,167,0.15); color:var(--muted); }
.notice { padding:10px 14px; border-radius:6px; margin-bottom:12px; }
.notice-ok { background:rgba(74,222,128,0.1); border:1px solid var(--ok); color:var(--ok); }
.notice-err { background:rgba(255,92,92,0.1); border:1px solid var(--danger); color:var(--danger); }
.notice-warn { background:rgba(255,183,74,0.1); border:1px solid var(--warn); color:var(--warn); }
table { width:100%; border-collapse:collapse; font-size:13px; }
td,th { padding:7px 10px; border-bottom:1px solid var(--border); text-align:left; }
th { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.3px; font-weight:600; }
tr:last-child td { border-bottom:0; }
code { background:rgba(255,255,255,0.08); padding:1px 6px; border-radius:4px; font:12px 'Menlo',monospace; }
input[type="password"],input[type="text"] { width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border); border-radius:6px; color:var(--text); font:14px inherit; }
input:focus { outline:2px solid var(--primary); outline-offset:-1px; border-color:var(--primary); }
.login-wrap { max-width:380px; margin:80px auto; padding:30px; background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); }
.login-wrap h1 { text-align:center; margin-bottom:20px; font-size:18px; }
.logo { text-align:center; color:var(--primary); font-size:32px; margin-bottom:6px; }
.footer { text-align:center; color:var(--muted); font-size:11px; padding:20px 0; }
</style>
</head>
<body>
<?php
}

function dbdm_em_footer() {
    ?>
<div class="footer">DB Debug Manager — Emergency Standalone · <a href="?a=logout" style="color:var(--muted);">Esci</a></div>
</body></html>
<?php
}

function dbdm_em_render_error($msg) {
    dbdm_em_header('Debug Manager — Errore');
    ?>
<div class="login-wrap">
    <div class="logo">⚠️</div>
    <h1>Accesso non disponibile</h1>
    <p style="color:var(--muted); text-align:center;"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></p>
</div>
<?php
    dbdm_em_footer();
}

function dbdm_em_render_login($error_msg = '') {
    $csrf = dbdm_em_csrf_token();
    dbdm_em_header('Debug Manager — Emergency Login');
    ?>
<div class="login-wrap">
    <div class="logo">🔒</div>
    <h1>Emergency Access</h1>
    <?php if ($error_msg): ?>
        <div class="notice notice-err"><?php echo htmlspecialchars($error_msg, ENT_QUOTES); ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
        <div style="margin-bottom:14px;">
            <input type="password" name="password" placeholder="Password emergency" autofocus required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Accedi</button>
    </form>
    <p style="color:var(--muted); font-size:11px; text-align:center; margin-top:16px;">
        5 tentativi per IP prima del blocco di 15 minuti. Ogni tentativo viene loggato.
    </p>
</div>
<?php
    dbdm_em_footer();
}

function dbdm_em_render_dashboard($notices, $log_content, $log_size, $active_plugins, $cur_theme, $consts_status, $php_error_log, $php_log_content, $snapshots = array()) {
    $csrf = dbdm_em_csrf_token();
    dbdm_em_header('Debug Manager — Emergency Dashboard');
    ?>
<div class="wrap">
    <div class="header">
        <div>
            <h1>🚨 Emergency Dashboard</h1>
            <small>Accesso diretto al sito senza WordPress · Sessione valida 30 min</small>
        </div>
        <div>
            <a class="btn btn-sm" href="?a=logout">Logout</a>
        </div>
    </div>

    <?php foreach ($notices as $n): ?>
        <div class="notice notice-<?php echo $n[0] === 'ok' ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars($n[1], ENT_QUOTES); ?></div>
    <?php endforeach; ?>

    <div class="panel panel-danger">
        <h2>⚡ Azioni rapide di ripristino</h2>
        <p style="color:var(--muted); font-size:12px; margin:0 0 12px;">Usa queste azioni se il sito è down. Ogni operazione è irreversibile senza un backup.</p>
        <div class="row">
            <form method="post" style="display:inline;" onsubmit="return confirm('Disattivare TUTTI i plugin?');">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="disable_all_plugins">
                <button type="submit" class="btn btn-danger btn-sm">🔌 Disattiva tutti i plugin</button>
            </form>

            <form method="post" style="display:inline;" onsubmit="return confirm('Cambiare al tema default? Lo stylesheet e template attuali verranno sostituiti.');">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="switch_to_default_theme">
                <button type="submit" class="btn btn-warn btn-sm">🎨 Cambia a tema default</button>
            </form>

            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="clear_transients">
                <button type="submit" class="btn btn-sm">🧹 Svuota transient</button>
            </form>

            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="clear_log">
                <button type="submit" class="btn btn-sm">🗑 Svuota debug.log</button>
            </form>
        </div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>🔧 Costanti Debug</h2>
            <table>
                <?php foreach ($consts_status as $name => $val): ?>
                    <tr>
                        <td><code><?php echo $name; ?></code></td>
                        <td>
                            <?php if ($val === true): ?><span class="tag tag-ok">ON</span>
                            <?php elseif ($val === false): ?><span class="tag tag-off">OFF</span>
                            <?php else: ?><span class="tag tag-off">non definita</span><?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="a" value="toggle_const">
                                <input type="hidden" name="const" value="<?php echo $name; ?>">
                                <input type="hidden" name="enable" value="<?php echo $val === true ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-sm"><?php echo $val === true ? 'Off' : 'On'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="panel">
            <h2>🎨 Tema attuale</h2>
            <p style="margin:0;">Stylesheet: <code><?php echo htmlspecialchars($cur_theme, ENT_QUOTES); ?></code></p>
        </div>
    </div>

    <?php if (!empty($snapshots)): ?>
    <div class="panel" style="border-color:var(--primary);">
        <h2>📸 Snapshot disponibili (<?php echo count($snapshots); ?>)</h2>
        <p style="color:var(--muted); font-size:12px; margin:0 0 12px;">
            Ripristina lo stato dei plugin attivi e/o del tema a uno snapshot precedente.
            I file dei plugin/tema non vengono toccati: cambia solo ciò che è attivo nel DB.
        </p>

        <?php foreach ($snapshots as $snap): ?>
            <?php
            $trigger_map = array(
                'manual'            => array('Manuale', 'var(--primary)'),
                'emergency_enabled' => array('Pre-emergency', 'var(--warn)'),
                'wp_upgrade'        => array('Post-aggiornamento', 'var(--ok)'),
            );
            $trig = $trigger_map[$snap['trigger'] ?? 'manual'] ?? array($snap['trigger'] ?? '—', 'var(--muted)');
            $date_fmt = !empty($snap['timestamp']) ? gmdate('j/m/Y H:i', $snap['timestamp']) . ' UTC' : '—';
            $plugin_count = isset($snap['active_plugins']) ? count($snap['active_plugins']) : 0;
            $snap_theme = $snap['stylesheet'] ?? '—';
            $note = $snap['note'] ?? '';
            ?>
            <div style="border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:10px; background:var(--bg);">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                    <div>
                        <strong><?php echo htmlspecialchars($date_fmt, ENT_QUOTES); ?></strong>
                        <span class="tag" style="background:rgba(74,158,255,0.15); color:<?php echo $trig[1]; ?>; margin-left:6px;"><?php echo htmlspecialchars($trig[0], ENT_QUOTES); ?></span>
                    </div>
                    <code style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars(substr($snap['id'] ?? '', 0, 14), ENT_QUOTES); ?></code>
                </div>

                <?php if ($note): ?>
                    <div style="font-size:12px; color:var(--muted); margin-bottom:8px; padding:6px 10px; background:rgba(255,255,255,0.04); border-radius:4px;">
                        📝 <?php echo htmlspecialchars($note, ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div style="font-size:12px; color:var(--muted); margin-bottom:10px;">
                    📦 <?php echo $plugin_count; ?> plugin attivi · 🎨 <code><?php echo htmlspecialchars($snap_theme, ENT_QUOTES); ?></code> · WP <?php echo htmlspecialchars($snap['wp_version'] ?? '—', ENT_QUOTES); ?>
                </div>

                <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;" onsubmit="return confirm('Ripristinare questo snapshot? Verranno modificati i plugin attivi/tema nel database.');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <input type="hidden" name="a" value="restore_snapshot">
                    <input type="hidden" name="snap_id" value="<?php echo htmlspecialchars($snap['id'] ?? '', ENT_QUOTES); ?>">
                    <label style="font-size:12px; color:var(--text);">
                        <input type="checkbox" name="restore_plugins" value="1" checked> Plugin attivi
                    </label>
                    <label style="font-size:12px; color:var(--text);">
                        <input type="checkbox" name="restore_theme" value="1" checked> Tema
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm">⏪ Ripristina</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h2>🔌 Plugin attivi (<?php echo count($active_plugins); ?>)</h2>
        <?php if (empty($active_plugins)): ?>
            <p style="color:var(--muted);">Nessun plugin attivo.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Slug</th><th style="width:100px; text-align:right;">Azione</th></tr></thead>
                <tbody>
                    <?php foreach ($active_plugins as $p): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($p, ENT_QUOTES); ?></code></td>
                            <td style="text-align:right;">
                                <form method="post" style="display:inline;" onsubmit="return confirm('Disattivare <?php echo htmlspecialchars($p, ENT_QUOTES); ?>?');">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                    <input type="hidden" name="a" value="disable_plugin">
                                    <input type="hidden" name="plugin" value="<?php echo htmlspecialchars($p, ENT_QUOTES); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Disattiva</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>📋 debug.log <small style="color:var(--muted); font-weight:normal;"><?php echo number_format($log_size / 1024, 1); ?> KB</small></h2>
        <?php if (!$log_content): ?>
            <p style="color:var(--muted);">Nessun debug.log trovato o file vuoto.</p>
        <?php else: ?>
            <pre class="log"><?php echo htmlspecialchars($log_content, ENT_QUOTES); ?></pre>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>🖥 PHP error log del server</h2>
        <?php if (!$php_error_log): ?>
            <p style="color:var(--muted);">error_log di PHP non configurato.</p>
        <?php elseif (!file_exists($php_error_log)): ?>
            <p style="color:var(--muted);">Path: <code><?php echo htmlspecialchars($php_error_log, ENT_QUOTES); ?></code> (non esistente).</p>
        <?php elseif (!$php_log_content): ?>
            <p style="color:var(--muted);">Path: <code><?php echo htmlspecialchars($php_error_log, ENT_QUOTES); ?></code> (vuoto o non leggibile).</p>
        <?php else: ?>
            <p style="color:var(--muted); font-size:11px; margin:0 0 8px;">Path: <code><?php echo htmlspecialchars($php_error_log, ENT_QUOTES); ?></code></p>
            <pre class="log"><?php echo htmlspecialchars($php_log_content, ENT_QUOTES); ?></pre>
        <?php endif; ?>
    </div>
</div>
<?php
    dbdm_em_footer();
}
