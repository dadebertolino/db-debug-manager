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

        // Backup prima di ogni modifica.
        $backup = $path . '.dbdm-bak';
        @copy($path, $backup);

        $new_contents = self::replace_or_insert_constant($contents, $name, $value);

        if ($new_contents === $contents) {
            // Nessuna modifica necessaria.
            return true;
        }

        // Validazione sintattica prima di salvare.
        $tmp = wp_tempnam('dbdm-config');
        if (!$tmp || file_put_contents($tmp, $new_contents) === false) {
            return new WP_Error('dbdm_tmp_fail', __('Impossibile creare file temporaneo.', 'db-debug-manager'));
        }

        $check = self::php_lint($tmp);
        @unlink($tmp);

        if (is_wp_error($check)) {
            return $check;
        }

        if (file_put_contents($path, $new_contents) === false) {
            return new WP_Error('dbdm_write_fail', __('Impossibile scrivere wp-config.php.', 'db-debug-manager'));
        }

        return true;
    }

    /**
     * Verifica sintassi PHP di un file.
     */
    private static function php_lint($file) {
        if (!function_exists('exec')) {
            return true; // skip se exec disabilitata
        }
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        @exec($cmd, $output, $code);
        if ($code !== 0) {
            return new WP_Error('dbdm_syntax_error', __('Errore di sintassi rilevato. Modifica annullata.', 'db-debug-manager'));
        }
        return true;
    }

    /**
     * Sostituisce o inserisce una define() in wp-config.
     * Regole:
     * - Se la costante esiste (anche commentata con // o #), viene sostituita la riga.
     * - Altrimenti viene inserita prima di "/* That's all, stop editing!".
     */
    private static function replace_or_insert_constant($contents, $name, $value) {
        $php_value = self::format_value($value);
        $new_line  = "define('{$name}', {$php_value});";

        // Regex: cattura righe define della costante, anche commentate.
        // Matches: // define(...); | # define(...); | /* define(...); */ | define(...);
        $pattern = '/^[ \t]*(?:\/\/|#|\/\*)?[ \t]*define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,.*?\)\s*;[ \t]*(?:\*\/)?[ \t]*(\r?\n|$)/mi';

        if (preg_match($pattern, $contents)) {
            return preg_replace($pattern, $new_line . "\n", $contents, 1);
        }

        // Inserimento prima del marker di fine editing.
        $marker = "/* That's all, stop editing!";
        $pos = strpos($contents, $marker);
        if ($pos !== false) {
            return substr($contents, 0, $pos) . $new_line . "\n\n" . substr($contents, $pos);
        }

        // Fallback: inserisci prima del primo require_once ABSPATH.
        $fallback = '/^(require_once\s*[\(\s].*?wp-settings\.php.*?;)/mi';
        if (preg_match($fallback, $contents)) {
            return preg_replace($fallback, $new_line . "\n\n$1", $contents, 1);
        }

        // Ultimo fallback: in coda.
        return rtrim($contents) . "\n\n" . $new_line . "\n";
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
