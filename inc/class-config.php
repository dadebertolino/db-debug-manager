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
     * Imposta una costante in wp-config.php.
     * La modifica ha effetto al prossimo caricamento di WP.
     *
     * @param string $name  Nome costante (deve essere in MANAGED).
     * @param mixed  $value true|false|stringa.
     * @return true|WP_Error
     */
    public static function set_constant($name, $value) {
        if (!in_array($name, self::MANAGED, true)) {
            return new WP_Error('dbdm_invalid_const', sprintf(__('Costante non gestita: %s', 'db-debug-manager'), $name));
        }

        $path = self::get_config_path();
        if (!$path) {
            return new WP_Error('dbdm_no_config', __('wp-config.php non trovato.', 'db-debug-manager'));
        }
        if (!is_writable($path)) {
            return new WP_Error('dbdm_not_writable', __('wp-config.php non è scrivibile. Controlla i permessi del file.', 'db-debug-manager'));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return new WP_Error('dbdm_read_fail', __('Impossibile leggere wp-config.php.', 'db-debug-manager'));
        }

        // Backup prima di ogni modifica, nella cartella private/ del plugin
        // (deny-all via .htaccess): mai accanto a wp-config.php, dove sarebbe
        // servito come testo semplice esponendo le credenziali DB.
        @copy($path, self::backup_path());
        self::cleanup_legacy_backup();

        $new_contents = DBDM_Standalone_Config::replace_or_insert_constant(
            $contents, $name, self::format_value($value)
        );

        if ($new_contents === $contents) {
            // Nessuna modifica necessaria.
            return true;
        }

        // Validazione sintattica prima di salvare (logica condivisa).
        $check = DBDM_Standalone_Config::php_lint_string($new_contents);
        if ($check !== true) {
            return new WP_Error('dbdm_syntax_error', __('Errore di sintassi rilevato. Modifica annullata.', 'db-debug-manager'));
        }

        if (file_put_contents($path, $new_contents) === false) {
            return new WP_Error('dbdm_write_fail', __('Impossibile scrivere wp-config.php.', 'db-debug-manager'));
        }

        return true;
    }

    /**
     * Path del backup di wp-config.php dentro private/.
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
        if (is_numeric($value)) {
            return (string) $value;
        }
        return "'" . addslashes((string) $value) . "'";
    }
}
