<?php
/**
 * DB Debug Manager — Emergency access management (WP side).
 * Gestisce password, lettura log tentativi, statistiche.
 */

if (!defined('ABSPATH')) exit;

class DBDM_Emergency {

    const OPTION_HASH        = 'dbdm_emergency_hash';
    const OPTION_ENABLED     = 'dbdm_emergency_enabled';
    const OPTION_TRUST_PROXY = 'dbdm_emergency_trust_proxy';
    const MIN_PWD_LEN        = 12;

    public static function is_enabled() {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    public static function set_enabled($enabled) {
        update_option(self::OPTION_ENABLED, (bool) $enabled, false);
    }

    /**
     * True se il sito è dichiarato dietro un proxy/CDN fidato:
     * emergency.php userà gli header proxy per risalire all'IP client.
     * Default false: si usa solo REMOTE_ADDR (non falsificabile).
     */
    public static function trusts_proxy() {
        return (bool) get_option(self::OPTION_TRUST_PROXY, false);
    }

    public static function set_trust_proxy($trust) {
        update_option(self::OPTION_TRUST_PROXY, (bool) $trust, false);
    }

    public static function has_password() {
        $h = get_option(self::OPTION_HASH, '');
        return !empty($h);
    }

    /**
     * Imposta la password. Ritorna true o WP_Error.
     */
    public static function set_password($plain) {
        if (strlen($plain) < self::MIN_PWD_LEN) {
            return new WP_Error('dbdm_pwd_short', sprintf(
                __('La password deve avere almeno %d caratteri.', 'db-debug-manager'),
                self::MIN_PWD_LEN
            ));
        }
        if (!preg_match('/[A-Z]/', $plain) || !preg_match('/[a-z]/', $plain) || !preg_match('/[0-9]/', $plain)) {
            return new WP_Error('dbdm_pwd_weak', __('La password deve contenere maiuscole, minuscole e numeri.', 'db-debug-manager'));
        }
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        update_option(self::OPTION_HASH, $hash, false); // autoload false
        return true;
    }

    public static function clear_password() {
        delete_option(self::OPTION_HASH);
        self::set_enabled(false);
    }

    /**
     * Path del file log tentativi.
     */
    public static function log_path() {
        return self::private_dir() . 'emergency-access.log';
    }

    public static function rate_limit_path() {
        return self::private_dir() . 'emergency-ratelimit.json';
    }

    const OPTION_DIR_TOKEN   = 'dbdm_private_dir_token';

    /**
     * Path della cartella privata (con trailing slash), garantendone
     * esistenza e protezione. Riusabile dalle altre classi del plugin.
     *
     * Il nome contiene un token casuale (private-{16 hex}) così i file
     * interni (log, snapshot, backup wp-config) non sono raggiungibili
     * indovinando l'URL nemmeno su server dove .htaccess è ignorato (Nginx).
     * Il token è salvato in wp_options ed è letto anche da emergency.php.
     */
    public static function private_dir() {
        $token = get_option(self::OPTION_DIR_TOKEN, '');
        if (!$token || !preg_match('/^[a-f0-9]{16}$/', $token)) {
            $token = bin2hex(random_bytes(8));
            update_option(self::OPTION_DIR_TOKEN, $token, false);
        }

        $dir    = DBDM_PLUGIN_DIR . 'private-' . $token;
        $legacy = DBDM_PLUGIN_DIR . 'private';

        // Migrazione: rinomina la vecchia private/ preservando i contenuti.
        if (!is_dir($dir) && is_dir($legacy)) {
            @rename($legacy, $dir);
        }

        self::ensure_private_dir($dir);
        return $dir . '/';
    }

    /**
     * Crea la cartella privata con protezioni (htaccess + index).
     */
    private static function ensure_private_dir($dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
    }

    /**
     * Ritorna ultime righe del log tentativi.
     */
    public static function get_log_entries($lines = 100) {
        $path = self::log_path();
        if (!file_exists($path)) return array();
        $content = file_get_contents($path);
        if ($content === false) return array();
        $all = array_filter(explode("\n", $content));
        return array_slice($all, -$lines);
    }

    public static function clear_log() {
        @unlink(self::log_path());
        @unlink(self::rate_limit_path());
    }

    /**
     * URL dell'emergency.php.
     */
    public static function access_url() {
        return DBDM_PLUGIN_URL . 'emergency.php';
    }
}
