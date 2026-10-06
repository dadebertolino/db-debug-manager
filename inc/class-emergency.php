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
    /**
     * Epoca delle sessioni emergency (1.4.0): emergency.php lega ogni
     * sessione a questo valore; cambiarlo invalida tutte le sessioni aperte.
     * Cambia a ogni modifica di password o abilitazione e alla
     * disattivazione del plugin.
     */
    const OPTION_EPOCH       = 'dbdm_emergency_epoch';
    const MIN_PWD_LEN        = 12;

    public static function is_enabled() {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    public static function set_enabled($enabled) {
        if ((bool) $enabled !== self::is_enabled()) {
            self::bump_epoch();
        }
        update_option(self::OPTION_ENABLED, (bool) $enabled, false);
    }

    /**
     * Invalida tutte le sessioni emergency aperte.
     *
     * @since 1.4.0
     */
    public static function bump_epoch() {
        update_option(self::OPTION_EPOCH, bin2hex(random_bytes(16)), false);
    }

    /**
     * Disattivazione del plugin (1.4.0): l'accesso emergency si spegne e le
     * sessioni aperte vengono chiuse. Deve restare usabile a sito rotto, ma
     * non dopo che l'admin ha scelto di disattivare il plugin.
     */
    public static function on_plugin_deactivate() {
        self::set_enabled(false);
        self::bump_epoch();
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
        self::bump_epoch();
        return true;
    }

    public static function clear_password() {
        delete_option(self::OPTION_HASH);
        self::set_enabled(false);
        self::bump_epoch();
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
    /** Percorso assoluto della cartella privata, letto da emergency.php (1.4.0). */
    const OPTION_DIR_PATH    = 'dbdm_private_dir_path';
    /**
     * wp-content, plugin e temi come li vede WordPress, letti da
     * emergency.php (2.0.0): WP_CONTENT_DIR può essere un'espressione che
     * l'emergency non sa valutare.
     */
    const OPTION_SITE_PATHS  = 'dbdm_site_paths';

    /**
     * Path della cartella privata (con trailing slash), garantendone
     * esistenza e protezione. Riusabile dalle altre classi del plugin.
     *
     * 1.4.0: sta in wp-content/dbdm-private-{token}/, FUORI dalla cartella
     * del plugin: fino alla 1.3.x ogni aggiornamento (o cancellazione) del
     * plugin la eliminava, con snapshot, backup di wp-config.php e log degli
     * accessi emergency. Il nome contiene un token casuale così i file non
     * sono raggiungibili indovinando l'URL nemmeno dove .htaccess è ignorato
     * (Nginx). Token e percorso sono in wp_options, letti da emergency.php.
     */
    public static function private_dir() {
        $token = get_option(self::OPTION_DIR_TOKEN, '');
        if (!$token || !preg_match('/^[a-f0-9]{16}$/', $token)) {
            $token = bin2hex(random_bytes(8));
            update_option(self::OPTION_DIR_TOKEN, $token, false);
        }

        $dir = WP_CONTENT_DIR . '/dbdm-private-' . $token;
        if (!is_dir($dir)) {
            self::migrate_private_dir($dir, $token);
        }
        self::ensure_private_dir($dir);

        if (get_option(self::OPTION_DIR_PATH) !== $dir) {
            update_option(self::OPTION_DIR_PATH, $dir, false);
        }
        $paths = self::site_paths();
        if (get_option(self::OPTION_SITE_PATHS) !== $paths) {
            update_option(self::OPTION_SITE_PATHS, $paths, false);
        }
        return $dir . '/';
    }

    /**
     * Percorsi del sito per emergency.php.
     *
     * @return array{content_dir:string,plugins_dir:string,themes_dir:string}
     */
    public static function site_paths() {
        return array(
            'content_dir' => WP_CONTENT_DIR,
            'plugins_dir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins',
            'themes_dir'  => function_exists('get_theme_root') ? get_theme_root() : WP_CONTENT_DIR . '/themes',
        );
    }

    /**
     * Sposta i file dalle posizioni delle versioni precedenti, dentro la
     * cartella del plugin: private-{token}/ (1.3.x) e private/ (≤ 1.2.x).
     */
    private static function migrate_private_dir($dir, $token) {
        foreach (array(DBDM_PLUGIN_DIR . 'private-' . $token, DBDM_PLUGIN_DIR . 'private') as $old) {
            if (!is_dir($old)) continue;
            if (@rename($old, $dir)) return;
            // Rename tra filesystem diversi: copia e cancella.
            if (!is_dir($dir)) @mkdir($dir, 0750);
            foreach ((array) scandir($old) as $f) {
                if ($f === '.' || $f === '..' || !is_file($old . '/' . $f)) continue;
                if (!file_exists($dir . '/' . $f)) @copy($old . '/' . $f, $dir . '/' . $f);
                @unlink($old . '/' . $f);
            }
            @rmdir($old);
            return;
        }
    }

    /**
     * True se la cartella privata esiste ed è scrivibile: senza, niente
     * backup di wp-config.php, snapshot né rate limit dell'emergency.
     *
     * @since 1.4.0
     */
    public static function private_dir_writable() {
        return is_writable(self::private_dir());
    }

    /**
     * Crea la cartella privata con protezioni (htaccess + index).
     */
    private static function ensure_private_dir($dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', self::htaccess_rules());
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
    }

    /**
     * Regole di blocco valide sia per Apache 2.4 sia per 2.2 (una sola delle
     * due direttive senza IfModule dà errore 500 sull'altra versione).
     *
     * @since 1.4.0
     */
    public static function htaccess_rules() {
        return "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
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
        @unlink(self::log_path() . '.1'); // Copia ruotata da emergency.php (2.0.0).
        @unlink(self::rate_limit_path());
    }

    /**
     * URL dell'emergency.php.
     */
    public static function access_url() {
        return DBDM_PLUGIN_URL . 'emergency.php';
    }
}
