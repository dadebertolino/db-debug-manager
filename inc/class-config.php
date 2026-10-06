<?php
/**
 * DB Debug Manager — Config handler
 * Legge e modifica le costanti di debug in wp-config.php.
 */

if (!defined('ABSPATH')) exit;

class DBDM_Config {

    /**
     * Costanti gestite dal plugin.
     */
    const MANAGED = array(
        'WP_DEBUG',
        'WP_DEBUG_LOG',
        'WP_DEBUG_DISPLAY',
        'SCRIPT_DEBUG',
        'SAVEQUERIES',
    );

    /**
     * Trova il path di wp-config.php (in ABSPATH o in una dir sopra).
     */
    public static function get_config_path() {
        if (file_exists(ABSPATH . 'wp-config.php')) {
            return ABSPATH . 'wp-config.php';
        }
        if (file_exists(dirname(ABSPATH) . '/wp-config.php') && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) {
            return dirname(ABSPATH) . '/wp-config.php';
        }
        return false;
    }

    /**
     * True se wp-config.php è scrivibile.
     */
    public static function is_writable() {
        $path = self::get_config_path();
        return $path && is_writable($path);
    }

    /**
     * Stato corrente di tutte le costanti gestite.
     * Ritorna un array: [const_name => ['defined' => bool, 'value' => mixed]]
     */
    public static function get_status() {
        $out = array();
        foreach (self::MANAGED as $const) {
            $out[$const] = array(
                'defined' => defined($const),
                'value'   => defined($const) ? constant($const) : null,
            );
        }
        return $out;
    }

    /**
     * Come mostrare una costante nel pannello. Attiva = valore vero per PHP
     * (2.0.0, bug 6: `define( 'WP_DEBUG', 1 )` risultava spenta e il
     * salvataggio successivo scriveva false). Per WP_DEBUG_LOG un percorso
     * personalizzato è una stringa diversa da '1'/'true', come in
     * wp_debug_mode() (bug 7).
     *
     * @param string $const
     * @param array  $status Voce di get_status(): defined, value.
     * @return array{on:bool,custom_path:string}
     */
    public static function constant_state($const, array $status) {
        $value = !empty($status['defined']) ? $status['value'] : null;
        $on    = (bool) $value;
        $path  = '';
        if ($const === 'WP_DEBUG_LOG' && $on && is_string($value) && !in_array(strtolower($value), array('1', 'true'), true)) {
            $path = $value;
        }
        return array('on' => $on, 'custom_path' => $path);
    }

    /**
     * Imposta una costante in wp-config.php.
     * La modifica ha effetto al prossimo caricamento di WP.
     *
     * @param string $name  Nome costante (deve essere in MANAGED).
     * @param mixed  $value true|false|stringa.
     * @return true|WP_Error
     */
    public static function set_constant($name, $value) {
        return self::set_constants(array($name => $value));
    }

    /**
     * Imposta più costanti con un'unica scrittura di wp-config.php e un
     * unico backup dello stato precedente (1.4.0). Se il backup non riesce
     * non viene scritto nulla.
     *
     * @param array<string,mixed> $values nome => true|false|stringa.
     * @return true|WP_Error
     */
    public static function set_constants(array $values) {
        $source = array();
        foreach ($values as $name => $value) {
            if (!in_array($name, self::MANAGED, true)) {
                return new WP_Error('dbdm_invalid_const', sprintf(__('Costante non gestita: %s', 'db-debug-manager'), $name));
            }
            $source[$name] = self::format_value($value);
        }
        if (!$source) return true;

        $path = self::get_config_path();
        if (!$path) {
            return new WP_Error('dbdm_no_config', __('wp-config.php non trovato.', 'db-debug-manager'));
        }
        if (!is_writable($path)) {
            return new WP_Error('dbdm_not_writable', __('wp-config.php non è scrivibile. Controlla i permessi del file.', 'db-debug-manager'));
        }

        // Backup nella cartella privata (mai accanto a wp-config.php, dove
        // sarebbe servito come testo semplice esponendo le credenziali DB).
        $result = DBDM_Standalone_Config::set_constants($path, $source, self::backup_path());
        self::cleanup_legacy_backup();
        if ($result !== true) {
            return new WP_Error('dbdm_write_fail', $result);
        }
        return true;
    }

    /**
     * Path del backup di wp-config.php nella cartella privata.
     */
    public static function backup_path() {
        return DBDM_Emergency::private_dir() . 'wp-config.dbdm-bak';
    }

    /**
     * Elimina il backup legacy creato accanto a wp-config.php dalle
     * versioni <= 1.2.0: si trova nella webroot ed è potenzialmente
     * scaricabile come testo semplice (leak credenziali DB).
     */
    public static function cleanup_legacy_backup() {
        $path = self::get_config_path();
        if ($path && file_exists($path . '.dbdm-bak')) {
            @unlink($path . '.dbdm-bak');
        }
    }

    /**
     * Converte un valore PHP in rappresentazione sorgente.
     */
    private static function format_value($value) {
        if (is_bool($value) || $value === 'true' || $value === 'false') {
            $b = is_bool($value) ? $value : ($value === 'true');
            return $b ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Letterale PHP corretto (apici singoli, solo \' e \\ come escape).
        return var_export((string) $value, true);
    }
}
