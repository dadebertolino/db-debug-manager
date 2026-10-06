<?php
/**
 * DB Debug Manager — Disinstallazione.
 *
 * Eseguita da uninstall.php quando il plugin viene eliminato dalla pagina
 * Plugin. Rimuove opzioni, transient, user meta e la cartella privata
 * (snapshot, backup di wp-config.php, log). Le costanti scritte in
 * wp-config.php restano: il pannello e la pagina Plugin le elencano prima.
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) exit;

class DBDM_Uninstall {

    const BASENAME = 'db-debug-manager/db-debug-manager.php';

    const MANAGED = array('WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES');

    public static function run() {
        // Prima di cancellare le opzioni: servono a trovare la cartella.
        $dirs = self::private_dirs();

        if (is_multisite()) {
            foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
                switch_to_blog($site_id);
                self::delete_site_data();
                restore_current_blog();
            }
        } else {
            self::delete_site_data();
        }
        delete_metadata('user', 0, 'dbdm_query_monitor_until', '', true);

        foreach ($dirs as $dir) {
            self::rrmdir($dir);
        }
    }

    /**
     * Opzioni e transient del plugin (e la cache dell'updater) nel sito
     * corrente.
     */
    private static function delete_site_data() {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('dbdm_') . '%',
            $wpdb->esc_like('_transient_dbdm_') . '%',
            $wpdb->esc_like('_transient_timeout_dbdm_') . '%'
        ));
        foreach ($names as $name) {
            if (strpos($name, '_transient_timeout_') === 0) continue;
            if (strpos($name, '_transient_') === 0) {
                delete_transient(substr($name, strlen('_transient_')));
            } else {
                delete_option($name);
            }
        }
        delete_transient('dbgu_' . md5(self::BASENAME));
    }

    /**
     * Cartelle private del plugin: quella registrata e ogni
     * wp-content/dbdm-private-{token} (il nome deve avere la forma attesa).
     *
     * @return string[]
     */
    public static function private_dirs() {
        $dirs  = glob(WP_CONTENT_DIR . '/dbdm-private-*', GLOB_ONLYDIR);
        $dirs  = is_array($dirs) ? $dirs : array();
        $saved = get_option('dbdm_private_dir_path', '');
        if (is_string($saved) && $saved !== '' && is_dir($saved)) {
            $dirs[] = rtrim($saved, '/');
        }
        $dirs = array_filter(array_unique($dirs), function ($dir) {
            return (bool) preg_match('/^dbdm-private-[a-f0-9]{16}$/', basename($dir));
        });
        return array_values($dirs);
    }

    /**
     * Costanti di debug definite in wp-config.php, che la disinstallazione
     * lascia al loro posto.
     *
     * @param string|false $config_path
     * @return string[]
     */
    public static function leftover_constants($config_path) {
        $content = $config_path && is_readable($config_path) ? (string) file_get_contents($config_path) : '';
        if ($content === '') return array();
        $names = array_column(DBDM_Standalone_Config::find_defines($content), 'name');
        return array_values(array_intersect(self::MANAGED, $names));
    }

    private static function rrmdir($dir) {
        if (!is_dir($dir) || is_link($dir)) return;
        foreach (array_diff((array) scandir($dir), array('.', '..')) as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
