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
     * Stato delle costanti di debug lette da wp-config.php: true, false,
     * null se non definite.
     *
     * @return array<string,bool|null>
     */
    public static function constants_status($wp_config_content) {
        $status = array();
        foreach (self::MANAGED_CONSTANTS as $c) {
            // Cattura bool ma anche stringhe (WP_DEBUG_LOG può essere un path custom).
            if (preg_match('/^[ \t]*define\s*\(\s*[\'"]' . preg_quote($c, '/') . '[\'"]\s*,\s*(true|false|\'[^\']*\'|"[^"]*")\s*\)\s*;/mi', (string) $wp_config_content, $m)) {
                $raw = strtolower(trim($m[1]));
                if ($raw === 'true') {
                    $status[$c] = true;
                } elseif ($raw === 'false') {
                    $status[$c] = false;
                } else {
                    // Stringa: non vuota = attiva (path custom). Vuota = false.
                    $status[$c] = trim($m[1], '\'"') !== '';
                }
            } else {
                $status[$c] = null;
            }
        }
        return $status;
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
     * @return array
     */
    public static function collect(DBDM_Em_Repository $repo, $config_path, $content_dir, $private_dir) {
        $log_path      = self::debug_log_path($config_path, $content_dir);
        $php_error_log = ini_get('error_log');
        $php_log       = '';
        if ($php_error_log && file_exists($php_error_log) && is_readable($php_error_log)) {
            $php_log = self::tail_bytes($php_error_log, self::PHP_LOG_TAIL);
        }
        $active = $repo->active_plugins();

        return array(
            'log_content'     => self::tail_bytes($log_path, self::DEBUG_LOG_TAIL),
            'log_size'        => file_exists($log_path) ? (int) filesize($log_path) : 0,
            'active_plugins'  => $active === null ? array() : $active,
            'cur_theme'       => $repo->get_option('stylesheet', '-'),
            'consts_status'   => self::constants_status(@file_get_contents($config_path)),
            'php_error_log'   => $php_error_log,
            'php_log_content' => $php_log,
            'snapshots'       => self::snapshots($private_dir . 'snapshots.json'),
        );
    }
}
