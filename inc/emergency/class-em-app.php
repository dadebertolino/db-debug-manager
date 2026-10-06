<?php
/**
 * DB Debug Manager — Emergency: flusso di una richiesta.
 *
 * Configurazione e database, controlli di attivazione, cartella privata,
 * logout, login con limite dei tentativi, azioni, dashboard. È l'unica
 * classe dell'emergency che invia header o avvia la sessione.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_App')) return;

class DBDM_Em_App {

    /** @var string Cartella del plugin, senza slash finale. */
    private $plugin_dir;

    /** @var callable function(array $creds): PDO|false */
    private $connect;

    /**
     * @param string        $plugin_dir
     * @param callable|null $connect    Connessione al database (nei test);
     *                                  di default DBDM_Standalone_Config::connect().
     */
    public function __construct($plugin_dir, $connect = null) {
        $this->plugin_dir = rtrim($plugin_dir, '/');
        $this->connect    = $connect ? $connect : array('DBDM_Standalone_Config', 'connect');
    }

    /**
     * wp-content, plugin e temi del sito. 2.0.0 (bug 21): dai percorsi
     * salvati da WordPress (DBDM_Emergency::site_paths(), che conosce
     * WP_CONTENT_DIR, WP_PLUGIN_DIR e la cartella dei temi), ciascuno solo
     * se è una cartella esistente; altrimenti dalla posizione del plugin
     * (wp-content/plugins/db-debug-manager).
     *
     * @param mixed  $saved      Opzione dbdm_site_paths.
     * @param string $plugin_dir
     * @return array{content_dir:string,plugins_dir:string,themes_dir:string}
     */
    public static function site_paths($saved, $plugin_dir) {
        $plugins = dirname(rtrim($plugin_dir, '/'));
        $content = dirname($plugins);
        $paths   = array(
            'content_dir' => $content,
            'plugins_dir' => $plugins,
            'themes_dir'  => $content . '/themes',
        );
        foreach ($paths as $key => $default) {
            $value = is_array($saved) && isset($saved[$key]) ? $saved[$key] : null;
            if (is_string($value) && $value !== '' && $value[0] === '/' && is_dir($value)) {
                $paths[$key] = rtrim($value, '/');
            }
        }
        return $paths;
    }

    /**
     * Cartella privata creata dal pannello: dal percorso salvato, o dal token
     * nella posizione standard (wp-content/dbdm-private-{token}/). Il nome deve
     * avere la forma attesa: un valore manomesso nel DB non può indicare
     * un'altra cartella. 1.4.0: nessun ripiego su una cartella dal nome
     * prevedibile: senza cartella privata l'accesso è negato.
     *
     * @return string Percorso con slash finale, '' se non trovata.
     */
    public static function resolve_private_dir($path, $token, $content_dir) {
        $candidates = array();
        if (is_string($path) && $path !== '') $candidates[] = rtrim($path, '/');
        if (is_string($token) && preg_match('/^[a-f0-9]{16}$/', $token)) {
            $candidates[] = $content_dir . '/dbdm-private-' . $token;
        }
        foreach ($candidates as $dir) {
            if (preg_match('/^dbdm-private-[a-f0-9]{16}$/', basename($dir)) && is_dir($dir)) {
                return $dir . '/';
            }
        }
        return '';
    }

    public function run(DBDM_Em_Request $request) {
        DBDM_Em_Session::start($request);
        // Dopo session_start(), che invia i propri header di cache.
        foreach (self::security_headers() as $header) {
            header($header);
        }
        $this->dispatch($request, new DBDM_Em_Session($_SESSION));
    }

    /**
     * Rete del multisite secondo wp-config.php (2.0.0, bug 26): MULTISITE
     * vero e SITE_ID_CURRENT_SITE (1 se manca); null su un sito singolo.
     */
    public static function network_site_id($wp_config_content) {
        $defines = DBDM_Standalone_Config::effective_defines((string) $wp_config_content);
        if (empty($defines['MULTISITE'])) return null;
        return isset($defines['SITE_ID_CURRENT_SITE']) && is_numeric($defines['SITE_ID_CURRENT_SITE'])
            ? (int) $defines['SITE_ID_CURRENT_SITE'] : 1;
    }

    /**
     * Header di ogni risposta (2.0.0, bug 35): niente framing
     * (clickjacking sulle azioni distruttive), niente indicizzazione, niente
     * cache (pagine con log e dati del sito), niente referrer.
     *
     * @return string[]
     */
    public static function security_headers() {
        return array(
            'X-Frame-Options: DENY',
            "Content-Security-Policy: frame-ancestors 'none'",
            'X-Robots-Tag: noindex, nofollow',
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
            'Pragma: no-cache',
            'Referrer-Policy: no-referrer',
            'X-Content-Type-Options: nosniff',
        );
    }

    /**
     * Risponde a una richiesta con la sessione già avviata.
     * 2.0.0 (bug 34): un errore del database (tipicamente $table_prefix che
     * non corrisponde alle tabelle) dà la pagina "non disponibile" invece di
     * una risposta 500 vuota; il dettaglio va solo nel log degli errori di PHP.
     */
    public function dispatch(DBDM_Em_Request $request, DBDM_Em_Session $session) {
        $view = new DBDM_Em_View($session);
        try {
            $this->handle($request, $session, $view);
        } catch (PDOException $e) {
            self::unavailable($view, 'errore del database (controlla $table_prefix in wp-config.php): ' . $e->getMessage());
        }
    }

    /**
     * Accesso non disponibile. 2.0.0 (bug 37): prima del login la pagina non
     * dice perché (stato dell'emergency, password, database, cartella); il
     * motivo va nel log degli errori di PHP, il pannello WordPress mostra lo
     * stato all'amministratore.
     */
    private static function unavailable(DBDM_Em_View $view, $reason) {
        error_log('DB Debug Manager emergency: ' . $reason); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- unico canale per il motivo, fuori dalla pagina.
        $view->error('Accesso d\'emergenza non disponibile.');
    }

    private function handle(DBDM_Em_Request $request, DBDM_Em_Session $session, DBDM_Em_View $view) {

        // Configurazione e database.
        $config_path = DBDM_Standalone_Config::find_wp_config($this->plugin_dir);
        if (!$config_path) {
            self::unavailable($view, 'wp-config.php non trovato');
            return;
        }
        $creds = DBDM_Standalone_Config::parse_credentials($config_path);
        if (!$creds) {
            self::unavailable($view, 'credenziali del database non leggibili da wp-config.php');
            return;
        }
        $pdo = call_user_func($this->connect, $creds);
        if (!$pdo) {
            self::unavailable($view, 'connessione al database fallita');
            return;
        }
        $repo = new DBDM_Em_Repository($pdo, $creds['prefix'], self::network_site_id((string) @file_get_contents($config_path)));

        // Attivazione.
        $enabled = $repo->get_option('dbdm_emergency_enabled');
        if (!$enabled || $enabled === '0') {
            self::unavailable($view, 'accesso disattivato dal pannello');
            return;
        }
        $stored_hash = $repo->get_option('dbdm_emergency_hash');
        if (empty($stored_hash)) {
            self::unavailable($view, 'nessuna password configurata');
            return;
        }

        // Modalità proxy fidato: da qui in poi l'IP può venire dagli header proxy.
        $trust_proxy = (bool) $repo->get_option('dbdm_emergency_trust_proxy', false);

        // Sessioni legate a password ed epoca (vedi DBDM_Em_Session::is_authed()).
        $fingerprint = DBDM_Emergency_Guard::session_fingerprint(
            $stored_hash,
            $repo->get_option('dbdm_emergency_epoch', '')
        );

        $paths = self::site_paths($repo->get_option('dbdm_site_paths'), $this->plugin_dir);

        // Cartella privata (log accessi, limite tentativi, snapshot, backup).
        $private_dir = self::resolve_private_dir(
            $repo->get_option('dbdm_private_dir_path', ''),
            $repo->get_option('dbdm_private_dir_token', ''),
            $paths['content_dir']
        );
        if ($private_dir === '') {
            self::unavailable($view, 'cartella privata non trovata: aprire una volta il pannello Debug Manager in WordPress');
            return;
        }
        if (!is_writable($private_dir)) {
            // Senza cartella scrivibile non c'è limite ai tentativi: accesso negato.
            self::unavailable($view, 'cartella privata non scrivibile dal server web');
            return;
        }
        $rl_file = $private_dir . 'emergency-ratelimit.json';
        $ip      = $request->ip($trust_proxy);
        $rl_key  = DBDM_Emergency_Guard::rate_key($ip);
        $logger  = new DBDM_Em_Logger($private_dir . 'emergency-access.log', $ip, $request->user_agent());

        // Logout: solo con il modulo della dashboard (bug 38).
        if (self::is_logout($request, $session)) {
            $session->clear();
            session_destroy();
            $this->redirect($request);
            return;
        }

        // Login.
        if (!$session->is_authed($fingerprint) && $request->is_post() && $request->has_post('password')) {
            if (!$session->csrf_check($request->post_string('csrf'))) {
                $logger->log('LOGIN_CSRF_FAIL');
                $view->login('Token di sessione non valido. Ricarica la pagina.');
                return;
            }
            // 1.4.0: il tentativo viene contato PRIMA della verifica, sotto lock:
            // anche con richieste parallele non si superano i 5 tentativi.
            $attempt = DBDM_Emergency_Guard::reserve_attempt($rl_file, $rl_key);
            if ($attempt['error'] !== '') {
                $logger->log('LOGIN_REFUSED', 'rate limit non disponibile');
                $view->login('Limite dei tentativi non disponibile: accesso negato.');
                return;
            }
            if (!$attempt['allowed']) {
                $logger->log('LOGIN_BLOCKED', 'IP locked, ' . $attempt['retry_after'] . 's remaining');
                $view->login('Troppi tentativi. Riprova tra ' . ceil($attempt['retry_after'] / 60) . ' minuti.');
                return;
            }
            $password = $request->post_string('password');
            if ($password !== '' && password_verify($password, $stored_hash)) {
                // 1.4.0: nuovo ID di sessione al login (niente session fixation).
                session_regenerate_id(true);
                $session->login($fingerprint);
                DBDM_Emergency_Guard::reset($rl_file, $rl_key);
                $logger->log('LOGIN_SUCCESS');
                $this->redirect($request);
                return;
            }
            $logger->log('LOGIN_FAIL');
            $view->login('Password errata.');
            return;
        }

        if (!$session->is_authed($fingerprint)) {
            $lock = DBDM_Emergency_Guard::locked_for($rl_file, $rl_key);
            $view->login($lock > 0 ? 'IP bloccato. Riprova tra ' . ceil($lock / 60) . ' minuti.' : '');
            return;
        }

        $actions = new DBDM_Em_Actions($repo, $logger, $paths + array(
            'config_path' => $config_path,
            'private_dir' => $private_dir,
        ));
        $notices = self::handle_actions($request, $session, $actions, $logger);

        $view->dashboard($notices, DBDM_Em_Status::collect($repo, $config_path, $paths['content_dir'], $private_dir, array_merge(array(dirname($config_path)), array_values($paths))));
    }

    /**
     * Richiesta di logout valida: POST con token CSRF. 2.0.0 (bug 38): un
     * link o un'immagine su un altro sito non chiude più la sessione.
     */
    public static function is_logout(DBDM_Em_Request $request, DBDM_Em_Session $session) {
        return $request->action() === 'logout' && $request->is_post()
            && $session->csrf_check($request->post_string('csrf'));
    }

    /**
     * Azioni della dashboard: solo in POST e con token CSRF valido. 2.0.0
     * (bug 30): un token scaduto o mancante non passa più in silenzio.
     *
     * @return array[] Avvisi.
     */
    public static function handle_actions(DBDM_Em_Request $request, DBDM_Em_Session $session, DBDM_Em_Actions $actions, DBDM_Em_Logger $logger) {
        $action = $request->action();
        if (!$request->is_post() || $action === '') return array();
        if (!$session->csrf_check($request->post_string('csrf'))) {
            $logger->log('ACTION_CSRF_FAIL', $action);
            return array(array('err', 'Modulo scaduto: azione non eseguita. Riprova.'));
        }
        return $actions->run($action, $request);
    }

    private function redirect(DBDM_Em_Request $request) {
        header('Location: ' . $request->path());
    }
}
