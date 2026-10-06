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
        return self::effective_path(
            defined('WP_DEBUG') && WP_DEBUG,
            defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null,
            (string) ini_get('error_log')
        );
    }

    /**
     * File del log come lo usa PHP. Con WP_DEBUG e WP_DEBUG_LOG attivi
     * wp_debug_mode() imposta ini error_log, che è il file in cui PHP
     * scrive davvero (2.0.0, bug 7: prima ignorato); altrimenti il percorso
     * indicato da WP_DEBUG_LOG o quello predefinito, da mostrare nel viewer.
     *
     * @param bool   $wp_debug
     * @param mixed  $wp_debug_log
     * @param string $ini_error_log
     * @return string
     */
    public static function effective_path($wp_debug, $wp_debug_log, $ini_error_log) {
        if ($wp_debug && $wp_debug_log && is_string($ini_error_log) && $ini_error_log !== '') {
            return $ini_error_log;
        }
        return self::resolve_path($wp_debug_log);
    }

    /**
     * Percorso del log per un valore di WP_DEBUG_LOG, come lo interpreta
     * wp_debug_mode(): true, '1', 'true' (e qualunque valore non stringa)
     * indicano wp-content/debug.log, le altre stringhe un percorso.
     *
     * @since 1.4.0
     */
    public static function resolve_path($value) {
        // '0' e '' spengono il log come false (2.0.0: '0' era preso per un file).
        if ($value && is_string($value) && !in_array(strtolower($value), array('1', 'true'), true)) {
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
        if (!self::exists()) {
            return '';
        }
        return self::tail_file(self::get_path(), max(10, min(10000, (int) $lines)));
    }

    /** Byte letti al massimo dalla fine del file. */
    const TAIL_MAX_BYTES = 2097152;

    /**
     * Ultime $lines righe di un file, senza l'a capo finale.
     *
     * 2.0.0 (bug 52): lettura all'indietro a blocchi, tempo lineare (prima
     * ogni blocco ricopiava e ricontava tutto il letto), al massimo
     * $max_bytes in memoria anche con righe lunghissime, esattamente
     * $lines righe (prima una in più senza a capo finale).
     *
     * @param string $path
     * @param int    $lines
     * @param int    $max_bytes
     * @return string
     */
    public static function tail_file($path, $lines, $max_bytes = self::TAIL_MAX_BYTES) {
        $size = is_file($path) ? (int) filesize($path) : 0;
        $fp   = $size > 0 ? @fopen($path, 'rb') : false;
        if (!$fp) {
            return '';
        }

        $chunks   = array();
        $newlines = 0;
        $pos      = $size;
        $read     = 0;
        // Una riga in più di a capo: l'eventuale a capo finale non chiude
        // una riga da mostrare.
        while ($pos > 0 && $newlines <= $lines && $read < $max_bytes) {
            $len   = (int) min(8192, $pos, $max_bytes - $read);
            $pos  -= $len;
            fseek($fp, $pos);
            $chunk = (string) fread($fp, $len);
            $chunks[]  = $chunk;
            $newlines += substr_count($chunk, "\n");
            $read     += strlen($chunk);
        }
        fclose($fp);

        $data = implode('', array_reverse($chunks));
        if (substr($data, -1) === "\n") {
            $data = substr($data, 0, -1);
        }
        $all = explode("\n", $data);
        $all = array_slice($all, -$lines);
        // Letto fino al limite di byte prima di N righe: la prima è tagliata.
        if ($pos > 0 && $newlines <= $lines) {
            $all[0] = '…' . $all[0];
        }
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
