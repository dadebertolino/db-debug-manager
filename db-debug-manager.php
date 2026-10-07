<?php
/**
 * Plugin Name: DB Debug Manager
 * Plugin URI: https://www.davidebertolino.it/progetti/
 * Description: Gestione centralizzata del debug di WordPress: abilita/disabilita WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, SAVEQUERIES direttamente dal pannello, con viewer del debug.log e monitor delle query SQL.
 * Version: 2.0.0
 * Author: Davide Bertolino
 * Author URI: https://www.davidebertolino.it
 * License: GPL v2 or later
 * Text Domain: db-debug-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/dadebertolino/db-debug-manager
 */

if (!defined('ABSPATH')) exit;

define('DBDM_VERSION', '2.0.0');
define('DBDM_PLUGIN_FILE', __FILE__);
define('DBDM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBDM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBDM_SLUG', 'db-debug-manager');

require_once DBDM_PLUGIN_DIR . 'inc/class-standalone-config.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-config.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-log.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-queries.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-emergency.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-snapshots.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-admin.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-updater.php';
require_once DBDM_PLUGIN_DIR . 'inc/class-uninstall.php';

/**
 * Main plugin bootstrap (singleton).
 */
final class DB_Debug_Manager {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Avvio sempre il monitor delle query: intercetta SAVEQUERIES se attiva.
        DBDM_Queries::instance();

        // Snapshot hooks (sia admin che non-admin, perché upgrader_process_complete
        // può scattare in contesti diversi).
        DBDM_Snapshots::init();

        if (is_admin()) {
            DBDM_Admin::instance();
        }

        // Auto-updater da GitHub.
        new DB_GitHub_Updater(__FILE__, 'dadebertolino', 'db-debug-manager');
    }
}

DB_Debug_Manager::instance();

// 2.0.0 (bug 58): traduzioni da languages/ (il plugin non è su wordpress.org).
add_action('init', function () {
    load_plugin_textdomain('db-debug-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// 1.4.0: disattivando il plugin l'accesso emergency si spegne.
register_deactivation_hook(__FILE__, array('DBDM_Emergency', 'on_plugin_deactivate'));
