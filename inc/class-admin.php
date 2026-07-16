<?php
/**
 * DB Debug Manager — Admin UI
 */

if (!defined('ABSPATH')) exit;

class DBDM_Admin {

    private static $instance = null;
    private $hook_suffix = '';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_dbdm_save_constants', array($this, 'handle_save_constants'));
        add_action('admin_post_dbdm_clear_log', array($this, 'handle_clear_log'));
        add_action('admin_post_dbdm_download_log', array($this, 'handle_download_log'));
        add_action('admin_post_dbdm_save_emergency', array($this, 'handle_save_emergency'));
        add_action('admin_post_dbdm_clear_emergency_log', array($this, 'handle_clear_emergency_log'));
        add_action('admin_post_dbdm_create_snapshot', array($this, 'handle_create_snapshot'));
        add_action('admin_post_dbdm_delete_snapshot', array($this, 'handle_delete_snapshot'));
        add_action('admin_post_dbdm_restore_snapshot', array($this, 'handle_restore_snapshot'));
        add_action('admin_post_dbdm_clear_snapshots', array($this, 'handle_clear_snapshots'));
        add_action('wp_ajax_dbdm_refresh_log', array($this, 'ajax_refresh_log'));
    }

    public function register_menu() {
        $this->hook_suffix = add_management_page(
            __('DB Debug Manager', 'db-debug-manager'),
            __('Debug Manager', 'db-debug-manager'),
            'manage_options',
            DBDM_SLUG,
            array($this, 'render_page')
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== $this->hook_suffix) return;

        wp_enqueue_style('db-admin-ui', DBDM_PLUGIN_URL . 'assets/css/db-admin-ui.css', array(), '1.0.0');
        wp_enqueue_style('dbdm-admin', DBDM_PLUGIN_URL . 'assets/css/admin.css', array('db-admin-ui'), DBDM_VERSION);

        wp_enqueue_script('dbdm-admin', DBDM_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), DBDM_VERSION, true);
        wp_localize_script('dbdm-admin', 'DBDM', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dbdm_nonce'),
            'i18n'     => array(
                'confirm_clear' => __('Svuotare il debug.log? Operazione irreversibile.', 'db-debug-manager'),
                'empty_log'     => __('Il log è vuoto.', 'db-debug-manager'),
                'refreshing'    => __('Aggiornamento...', 'db-debug-manager'),
            ),
        ));
    }

    /**
     * Render pagina principale.
     */
    public function render_page() {
        if (!current_user_can('manage_options')) return;

        // Migrazione: elimina il backup legacy esposto nella webroot (<= 1.2.0).
        DBDM_Config::cleanup_legacy_backup();

        $status        = DBDM_Config::get_status();
        $writable      = DBDM_Config::is_writable();
        $config_path   = DBDM_Config::get_config_path();
        $log_path      = DBDM_Log::get_path();
        $log_exists    = DBDM_Log::exists();
        $log_size      = DBDM_Log::size_human();
        $log_writable  = $log_exists ? is_writable($log_path) : is_writable(dirname($log_path));
        $snapshot      = DBDM_Queries::get_last_snapshot();

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'config';
        $valid_tabs = array('config', 'log', 'queries', 'emergency', 'snapshots');
        if (!in_array($tab, $valid_tabs, true)) $tab = 'config';

        include DBDM_PLUGIN_DIR . 'templates/page.php';
    }

    /**
     * Salva modifiche costanti.
     */
    public function handle_save_constants() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_save_constants');

        $posted = isset($_POST['dbdm']) && is_array($_POST['dbdm']) ? $_POST['dbdm'] : array();
        $errors = array();

        foreach (DBDM_Config::MANAGED as $const) {
            $value = !empty($posted[$const]) ? true : false;

            // WP_DEBUG_LOG può essere un path custom (stringa): va preservato.
            if ($const === 'WP_DEBUG_LOG') {
                $current = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null;
                $has_custom_path = is_string($current) && $current !== '';

                if ($value && $has_custom_path) {
                    continue; // già attiva con path custom: non toccare.
                }
                if (!$value && $has_custom_path) {
                    // Disattivazione: memorizza il path per la riattivazione.
                    update_option('dbdm_debug_log_path', $current, false);
                }
                if ($value && !$has_custom_path) {
                    // Riattivazione: ripristina l'eventuale path memorizzato.
                    $saved = get_option('dbdm_debug_log_path', '');
                    if (is_string($saved) && $saved !== '') {
                        $value = $saved;
                    }
                }
            }

            $result = DBDM_Config::set_constant($const, $value);
            if (is_wp_error($result)) {
                $errors[] = $const . ': ' . $result->get_error_message();
            }
        }

        $redirect = add_query_arg(array(
            'page'    => DBDM_SLUG,
            'tab'     => 'config',
            'updated' => empty($errors) ? '1' : '0',
            'err'     => !empty($errors) ? rawurlencode(implode(' | ', $errors)) : null,
        ), admin_url('tools.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_clear_log() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_clear_log');

        $result = DBDM_Log::clear();
        $args = array(
            'page'    => DBDM_SLUG,
            'tab'     => 'log',
            'cleared' => is_wp_error($result) ? '0' : '1',
        );
        if (is_wp_error($result)) {
            $args['err'] = rawurlencode($result->get_error_message());
        }
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
        exit;
    }

    public function handle_download_log() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_download_log');

        $path = DBDM_Log::get_path();
        if (!DBDM_Log::exists()) {
            wp_die(__('Nessun log disponibile.', 'db-debug-manager'));
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="debug-' . gmdate('Ymd-His') . '.log"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * AJAX: refresh log viewer.
     */
    public function ajax_refresh_log() {
        check_ajax_referer('dbdm_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'db-debug-manager')), 403);
        }
        $lines = isset($_POST['lines']) ? (int) $_POST['lines'] : 500;
        $content = DBDM_Log::tail($lines);

        wp_send_json_success(array(
            'content' => $content,
            'size'    => DBDM_Log::size_human(),
            'exists'  => DBDM_Log::exists(),
        ));
    }

    /**
     * Salva password + attivazione emergency.
     */
    public function handle_save_emergency() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_save_emergency');

        $errors = array();

        // Rimozione totale.
        if (isset($_POST['dbdm_action']) && $_POST['dbdm_action'] === 'clear') {
            DBDM_Emergency::clear_password();
            wp_safe_redirect(add_query_arg(array(
                'page' => DBDM_SLUG,
                'tab'  => 'emergency',
                'em_cleared' => '1',
            ), admin_url('tools.php')));
            exit;
        }

        // Password (solo se presente nel POST).
        if (!empty($_POST['dbdm_password'])) {
            $r = DBDM_Emergency::set_password($_POST['dbdm_password']);
            if (is_wp_error($r)) {
                $errors[] = $r->get_error_message();
            }
        } elseif (!DBDM_Emergency::has_password()) {
            $errors[] = __('Imposta prima una password.', 'db-debug-manager');
        }

        // Attivazione: solo se non ci sono errori e c'è una password.
        if (empty($errors) && DBDM_Emergency::has_password()) {
            DBDM_Emergency::set_enabled(!empty($_POST['dbdm_emergency_enabled']));
        } elseif (!empty($errors)) {
            DBDM_Emergency::set_enabled(false);
        }

        // Modalità proxy fidato (indipendente dagli errori password).
        DBDM_Emergency::set_trust_proxy(!empty($_POST['dbdm_trust_proxy']));

        $args = array(
            'page' => DBDM_SLUG,
            'tab'  => 'emergency',
            'em_saved' => empty($errors) ? '1' : '0',
        );
        if (!empty($errors)) {
            $args['err'] = rawurlencode(implode(' | ', $errors));
        }
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
        exit;
    }

    public function handle_clear_emergency_log() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_clear_emergency_log');
        DBDM_Emergency::clear_log();
        wp_safe_redirect(add_query_arg(array(
            'page' => DBDM_SLUG,
            'tab'  => 'emergency',
            'em_log_cleared' => '1',
        ), admin_url('tools.php')));
        exit;
    }

    public function handle_create_snapshot() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_create_snapshot');

        $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';
        $id = DBDM_Snapshots::create(DBDM_Snapshots::TRIGGER_MANUAL, $note);

        $args = array('page' => DBDM_SLUG, 'tab' => 'snapshots');
        if (is_wp_error($id)) {
            $args['snap_err'] = rawurlencode($id->get_error_message());
        } else {
            $args['snap_created'] = '1';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
        exit;
    }

    public function handle_delete_snapshot() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_delete_snapshot');

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        if ($id) DBDM_Snapshots::delete($id);

        wp_safe_redirect(add_query_arg(array(
            'page' => DBDM_SLUG, 'tab' => 'snapshots', 'snap_deleted' => '1',
        ), admin_url('tools.php')));
        exit;
    }

    public function handle_restore_snapshot() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_restore_snapshot');

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $parts = array();
        if (!empty($_POST['restore_plugins'])) $parts[] = 'plugins';
        if (!empty($_POST['restore_theme']))   $parts[] = 'theme';

        if (!$id || empty($parts)) {
            wp_safe_redirect(add_query_arg(array(
                'page' => DBDM_SLUG, 'tab' => 'snapshots',
                'snap_err' => rawurlencode(__('Seleziona almeno una parte da ripristinare.', 'db-debug-manager')),
            ), admin_url('tools.php')));
            exit;
        }

        $messages = DBDM_Snapshots::restore($id, $parts);
        // Passa i messaggi via transient (evita URL lunghi).
        set_transient('dbdm_restore_msgs_' . get_current_user_id(), $messages, 60);

        wp_safe_redirect(add_query_arg(array(
            'page' => DBDM_SLUG, 'tab' => 'snapshots', 'snap_restored' => '1',
        ), admin_url('tools.php')));
        exit;
    }

    public function handle_clear_snapshots() {
        if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_clear_snapshots');
        DBDM_Snapshots::delete_all();
        wp_safe_redirect(add_query_arg(array(
            'page' => DBDM_SLUG, 'tab' => 'snapshots', 'snap_cleared' => '1',
        ), admin_url('tools.php')));
        exit;
    }
}
