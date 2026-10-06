<?php
/**
 * DB Debug Manager — Log handler
 * Legge, svuota e scarica debug.log.
 */

if (!defined('ABSPATH')) exit;

class DBDM_Log {

    /**
     * Path del debug.log.
     * Rispetta WP_DEBUG_LOG se è un path esplicito, altrimenti default a wp-content/debug.log.
     */
    public static function get_path() {
        return self::resolve_path(defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null);
    }

    /**
     * Percorso del log per un valore di WP_DEBUG_LOG, come lo interpreta
     * wp_debug_mode(): true, '1', 'true' (e qualunque valore non stringa)
     * indicano wp-content/debug.log, le altre stringhe un percorso.
     *
     * @since 1.4.0
     */
    public static function resolve_path($value) {
        if (is_string($value) && $value !== '' && !in_array(strtolower($value), array('1', 'true'), true)) {
            return $value;
        }
        return self::public_path();
    }

    /**
     * Posizione predefinita di WordPress: dentro wp-content, raggiungibile
     * da chiunque via HTTP.
     *
     * @since 1.4.0
     */
    public static function public_path() {
        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Posizione usata dal plugin (1.4.0): nella cartella privata.
     */
    public static function private_path() {
        return DBDM_Emergency::private_dir() . 'debug.log';
    }

    /**
     * True se il log attivo è quello pubblico di wp-content.
     */
    public static function is_public() {
        return defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && wp_normalize_path(self::get_path()) === wp_normalize_path(self::public_path());
    }

    public static function exists() {
        return file_exists(self::get_path());
    }

    public static function size() {
        $path = self::get_path();
        return self::exists() ? filesize($path) : 0;
    }

    public static function size_human() {
        return size_format(self::size(), 2);
    }

    /**
     * Legge le ultime N righe del log in modo efficiente.
     */
    public static function tail($lines = 500) {
        $path = self::get_path();
        if (!self::exists()) {
            return '';
        }

        $lines = max(10, min(10000, (int) $lines));
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return '';
        }

        $buffer  = '';
        $chunk   = 8192;
        $size    = filesize($path);
        $pos     = $size;
        $read    = '';
        $count   = 0;

        while ($pos > 0 && $count <= $lines) {
            $seek = min($chunk, $pos);
            $pos -= $seek;
            fseek($fp, $pos);
            $read    = fread($fp, $seek) . $read;
            $count   = substr_count($read, "\n");
        }
        fclose($fp);

        $all = explode("\n", $read);
        $all = array_slice($all, -$lines - 1);
        return implode("\n", $all);
    }

    /**
     * Svuota il log.
     */
    public static function clear() {
        $path = self::get_path();
        if (!self::exists()) {
            return true;
        }
        if (!is_writable($path)) {
            return new WP_Error('dbdm_log_not_writable', __('debug.log non è scrivibile.', 'db-debug-manager'));
        }
        if (file_put_contents($path, '') === false) {
            return new WP_Error('dbdm_log_clear_fail', __('Impossibile svuotare il log.', 'db-debug-manager'));
        }
        return true;
    }

    /**
     * Conta totale righe (fino a un massimo, per evitare timeout su file enormi).
     */
    public static function line_count($max = 100000) {
        $path = self::get_path();
        if (!self::exists()) {
            return 0;
        }
        $fp = @fopen($path, 'rb');
        if (!$fp) return 0;

        $count = 0;
        while (!feof($fp) && $count < $max) {
            $chunk = fread($fp, 8192);
            $count += substr_count($chunk, "\n");
        }
        fclose($fp);
        return $count;
    }
}
