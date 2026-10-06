<?php
/**
 * DB Debug Manager — Emergency: stato del sito mostrato nella dashboard.
 *
 * Coda del debug.log e dell'error log di PHP, plugin attivi, tema, costanti
 * di debug in wp-config.php, snapshot. Sola lettura.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Status')) return;

class DBDM_Em_Status {

    const MANAGED_CONSTANTS = array('WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES');

    const DEBUG_LOG_TAIL = 65536;
    const PHP_LOG_TAIL   = 32768;
    /** Byte letti dall'error log di PHP prima di tenere solo le voci del sito. */
    const PHP_LOG_SCAN   = 262144;

    /**
     * File in cui WordPress scrive il log con questo valore di WP_DEBUG_LOG,
     * con la regola di wp_debug_mode(): true, 1, 'true', '1' →
     * wp-content/debug.log; un'altra stringa è il percorso; altrimenti
     * nessun log ('').
     */
    public static function resolve_log_path($value, $content_dir) {
        if (!$value || !is_scalar($value)) return '';
        if (in_array(strtolower((string) $value), array('true', '1'), true)) {
            return $content_dir . '/debug.log';
        }
        return is_string($value) ? $value : '';
    }

    /**
     * Valore effettivo di WP_DEBUG_LOG in wp-config.php (null se assente).
     */
    public static function debug_log_value($config_path) {
        $content = @file_get_contents($config_path);
        $defines = $content ? DBDM_Standalone_Config::effective_defines($content) : array();
        return isset($defines['WP_DEBUG_LOG']) ? $defines['WP_DEBUG_LOG'] : null;
    }

    /**
     * Percorso del debug.log come lo usa WordPress con il wp-config.php
     * attuale: un percorso in WP_DEBUG_LOG, altrimenti wp-content/debug.log
     * (anche a log spento, per mostrarlo o svuotarlo).
     */
    public static function debug_log_path($config_path, $content_dir) {
        $path = self::resolve_log_path(self::debug_log_value($config_path), $content_dir);
        return $path !== '' ? $path : $content_dir . '/debug.log';
    }

    /**
     * Ultimi $bytes byte di un file ('' se assente o illeggibile).
     */
    public static function tail_bytes($path, $bytes) {
        if (!file_exists($path)) return '';
        $size = filesize($path);
        $fp   = @fopen($path, 'rb');
        if (!$fp) return '';
        fseek($fp, max(0, $size - $bytes));
        $content = fread($fp, $bytes);
        fclose($fp);
        return (string) $content;
    }

    /**
     * Stato delle costanti di debug in wp-config.php come le vede PHP
     * (2.0.0, bug 31: prima una regex che ignorava 1, getenv() e le define
     * condizionali e leggeva anche quelle nei commenti): true/false secondo
     * il valore, 'unknown' se definita con un'espressione non valutabile,
     * null se non definita.
     *
     * @return array<string,bool|string|null>
     */
    public static function constants_status($wp_config_content) {
        $content = is_string($wp_config_content) ? $wp_config_content : '';
        $values  = $content !== '' ? DBDM_Standalone_Config::effective_defines($content) : array();
        $defined = $content !== '' ? array_column(DBDM_Standalone_Config::find_defines($content), 'name') : array();
        $status  = array();
        foreach (self::MANAGED_CONSTANTS as $c) {
            if (array_key_exists($c, $values)) {
                $status[$c] = (bool) $values[$c];
            } elseif (in_array($c, $defined, true)) {
                $status[$c] = 'unknown';
            } else {
                $status[$c] = null;
            }
        }
        return $status;
    }

    /**
     * Voci di un error log che riguardano questo sito: quelle in cui
     * compare uno dei percorsi in $roots. Una voce comincia con una riga
     * "[data]" e comprende le righe che seguono (stack trace). 2.0.0 (bug
     * 37): su un hosting condiviso l'error log di PHP può essere quello di
     * tutto il server.
     *
     * @param string   $content
     * @param string[] $roots
     * @return string
     */
    public static function site_entries($content, array $roots) {
        $needles = array();
        foreach ($roots as $root) {
            if (is_string($root) && trim($root, '/') !== '') $needles[] = rtrim($root, '/') . '/';
        }
        if (!$needles) return '';

        $entries = array();
        foreach (preg_split('/(?<=\n)/', (string) $content) as $line) {
            if ($line === '') continue;
            if (!$entries || $line[0] === '[') {
                $entries[] = $line;
            } else {
                $entries[count($entries) - 1] .= $line;
            }
        }
        $out = '';
        foreach ($entries as $entry) {
            foreach ($needles as $needle) {
                if (strpos($entry, $needle) !== false) {
                    $out .= $entry;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Object cache persistente (drop-in wp-content/object-cache.php: Redis,
     * Memcached...). 2.0.0 (bug 27): WordPress legge plugin attivi e tema
     * dalla cache, quindi le modifiche fatte qui nel database possono non
     * avere effetto finché la cache non viene svuotata.
     */
    public static function has_object_cache($content_dir) {
        return file_exists($content_dir . '/object-cache.php');
    }

    /**
     * Snapshot salvati dal pannello, dal più recente.
     */
    public static function snapshots($file) {
        if (!file_exists($file)) return array();
        $raw = @file_get_contents($file);
        if (!$raw) return array();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_reverse($decoded) : array();
    }

    /**
     * Dati per DBDM_Em_View::dashboard().
     *
     * @param DBDM_Em_Repository $repo
     * @param string             $config_path
     * @param string             $content_dir
     * @param string             $private_dir Con slash finale.
     * @param string[]           $roots       Percorsi del sito, per filtrare
     *                                        l'error log di PHP.
     * @return array
     */
    public static function collect(DBDM_Em_Repository $repo, $config_path, $content_dir, $private_dir, array $roots = array()) {
        $log_path      = self::debug_log_path($config_path, $content_dir);
        $php_error_log = ini_get('error_log');
        $php_log       = '';
        if ($php_error_log && file_exists($php_error_log) && is_readable($php_error_log)) {
            $php_log = self::site_entries(self::tail_bytes($php_error_log, self::PHP_LOG_SCAN), $roots);
            if (strlen($php_log) > self::PHP_LOG_TAIL) {
                $php_log = substr($php_log, -self::PHP_LOG_TAIL);
            }
        }
        $active = $repo->active_plugins();

        return array(
            'log_content'     => self::tail_bytes($log_path, self::DEBUG_LOG_TAIL),
            'log_size'        => file_exists($log_path) ? (int) filesize($log_path) : 0,
            'active_plugins'  => $active === null ? array() : $active,
            'network_plugins' => (array) $repo->network_plugins(),
            'multisite'       => $repo->is_network(),
            'cur_theme'       => $repo->get_option('stylesheet', '-'),
            'consts_status'   => self::constants_status(@file_get_contents($config_path)),
            'php_error_log'   => $php_error_log,
            'php_log_content' => $php_log,
            'snapshots'       => self::snapshots($private_dir . 'snapshots.json'),
            'object_cache'    => self::has_object_cache($content_dir),
        );
    }
}
