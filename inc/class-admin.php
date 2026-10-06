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

    /**
     * Capability richiesta: in multisite wp-config.php e debug.log sono della
     * rete intera, quindi solo i super admin (1.4.0).
     */
    public static function cap() {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    /**
     * URL della pagina del plugin (bacheca di rete in multisite).
     *
     * @param array $args Argomenti della query (tab, messaggi…).
     */
    public static function page_url($args = array()) {
        $base = is_multisite() ? network_admin_url('settings.php') : admin_url('tools.php');
        return add_query_arg(array_merge(array('page' => DBDM_SLUG), $args), $base);
    }

    private function __construct() {
        add_action(is_multisite() ? 'network_admin_menu' : 'admin_menu', array($this, 'register_menu'));
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
        add_action('admin_post_dbdm_query_monitor', array($this, 'handle_query_monitor'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_action('wp_ajax_dbdm_refresh_log', array($this, 'ajax_refresh_log'));
    }

    public function register_menu() {
        if (is_multisite()) {
            $this->hook_suffix = add_submenu_page(
                'settings.php',
                __('DB Debug Manager', 'db-debug-manager'),
                __('Debug Manager', 'db-debug-manager'),
                self::cap(),
                DBDM_SLUG,
                array($this, 'render_page')
            );
            return;
        }
        $this->hook_suffix = add_management_page(
            __('DB Debug Manager', 'db-debug-manager'),
            __('Debug Manager', 'db-debug-manager'),
            self::cap(),
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
        if (!current_user_can(self::cap())) return;

        // Migrazione: elimina il backup legacy esposto nella webroot (<= 1.2.0).
        DBDM_Config::cleanup_legacy_backup();

        $consts_status        = DBDM_Config::get_status();
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
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_save_constants');

        $posted = isset($_POST['dbdm']) && is_array($_POST['dbdm']) ? array_map('sanitize_text_field', wp_unslash($_POST['dbdm'])) : array();
        $result = DBDM_Config::set_constants(self::constants_to_write($posted));
        $errors = is_wp_error($result) ? array($result->get_error_message()) : array();

        $redirect = self::page_url(array(
            'tab'     => 'config',
            'updated' => empty($errors) ? '1' : '0',
            'err'     => !empty($errors) ? rawurlencode(implode(' | ', $errors)) : null,
        ));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Costanti da scrivere per il form inviato: solo quelle il cui stato
     * cambia rispetto a quello attuale (1.4.0: prima venivano scritte tutte,
     * compresa WP_DEBUG_DISPLAY attiva di default).
     *
     * WP_DEBUG_LOG (1.4.0): attivandolo il log va nella cartella privata,
     * non in wp-content/debug.log raggiungibile da chiunque; un percorso
     * personalizzato già impostato viene rispettato e, disattivando, ricordato
     * per la riattivazione.
     *
     * @param array $posted Checkbox inviate (nome => '1').
     * @return array<string,mixed>
     */
    public static function constants_to_write($posted) {
        $out = array();
        foreach (DBDM_Config::MANAGED as $const) {
            $want    = !empty($posted[$const]);
            $current = defined($const) ? constant($const) : null;

            if ($const === 'WP_DEBUG_LOG') {
                $path   = DBDM_Log::resolve_path($current);
                $custom = $current && wp_normalize_path($path) !== wp_normalize_path(DBDM_Log::public_path());
                if ($want) {
                    if ($custom) continue; // Già attivo su un percorso non pubblico.
                    $saved = get_option('dbdm_debug_log_path', '');
                    $saved_ok = is_string($saved) && $saved !== '' && wp_normalize_path(DBDM_Log::resolve_path($saved)) !== wp_normalize_path(DBDM_Log::public_path());
                    $out[$const] = $saved_ok ? $saved : DBDM_Log::private_path();
                } elseif ($current) {
                    if ($custom) {
                        update_option('dbdm_debug_log_path', $path, false);
                    }
                    $out[$const] = false;
                }
                continue;
            }

            if ($want !== (bool) $current) {
                $out[$const] = $want;
            }
        }
        return $out;
    }

    public function handle_clear_log() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_clear_log');

        $result = DBDM_Log::clear();
        $args = array(
            'tab'     => 'log',
            'cleared' => is_wp_error($result) ? '0' : '1',
        );
        if (is_wp_error($result)) {
            $args['err'] = rawurlencode($result->get_error_message());
        }
        wp_safe_redirect(self::page_url($args));
        exit;
    }

    public function handle_download_log() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_download_log');

        $path = DBDM_Log::get_path();
        if (!DBDM_Log::exists()) {
            wp_die(esc_html__('Nessun log disponibile.', 'db-debug-manager'));
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
        if (!current_user_can(self::cap())) {
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
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_save_emergency');

        $errors = array();

        // Rimozione totale.
        if (isset($_POST['dbdm_action']) && $_POST['dbdm_action'] === 'clear') {
            DBDM_Emergency::clear_password();
            wp_safe_redirect(self::page_url(array(
                'tab'  => 'emergency',
                'em_cleared' => '1',
            )));
            exit;
        }

        // Password (solo se presente nel POST).
        if (!empty($_POST['dbdm_password'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- la password non va alterata: viene solo hashata, mai stampata o salvata in chiaro.
            $r = DBDM_Emergency::set_password(wp_unslash($_POST['dbdm_password']));
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
            'tab'  => 'emergency',
            'em_saved' => empty($errors) ? '1' : '0',
        );
        if (!empty($errors)) {
            $args['err'] = rawurlencode(implode(' | ', $errors));
        }
        wp_safe_redirect(self::page_url($args));
        exit;
    }

    public function handle_clear_emergency_log() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_clear_emergency_log');
        DBDM_Emergency::clear_log();
        wp_safe_redirect(self::page_url(array(
            'tab'  => 'emergency',
            'em_log_cleared' => '1',
        )));
        exit;
    }

    public function handle_create_snapshot() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_create_snapshot');

        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
        $id = DBDM_Snapshots::create(DBDM_Snapshots::TRIGGER_MANUAL, $note);

        $args = array('tab' => 'snapshots');
        if (is_wp_error($id)) {
            $args['snap_err'] = rawurlencode($id->get_error_message());
        } else {
            $args['snap_created'] = '1';
        }
        wp_safe_redirect(self::page_url($args));
        exit;
    }

    public function handle_delete_snapshot() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_delete_snapshot');

        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        if ($id) DBDM_Snapshots::delete($id);

        wp_safe_redirect(self::page_url(array(
            'tab' => 'snapshots', 'snap_deleted' => '1',
        )));
        exit;
    }

    public function handle_restore_snapshot() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_restore_snapshot');

        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        $parts = array();
        if (!empty($_POST['restore_plugins'])) $parts[] = 'plugins';
        if (!empty($_POST['restore_theme']))   $parts[] = 'theme';

        if (!$id || empty($parts)) {
            wp_safe_redirect(self::page_url(array(
                'tab' => 'snapshots',
                'snap_err' => rawurlencode(__('Seleziona almeno una parte da ripristinare.', 'db-debug-manager')),
            )));
            exit;
        }

        $messages = DBDM_Snapshots::restore($id, $parts);
        // Passa i messaggi via transient (evita URL lunghi).
        set_transient('dbdm_restore_msgs_' . get_current_user_id(), $messages, 60);

        wp_safe_redirect(self::page_url(array(
            'tab' => 'snapshots', 'snap_restored' => '1',
        )));
        exit;
    }

    public function handle_clear_snapshots() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_clear_snapshots');
        DBDM_Snapshots::delete_all();
        wp_safe_redirect(self::page_url(array(
            'tab' => 'snapshots', 'snap_cleared' => '1',
        )));
        exit;
    }

    /**
     * Attiva o spegne il monitor delle query per l'utente corrente (2.0.0).
     */
    public function handle_query_monitor() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Permessi insufficienti.', 'db-debug-manager'));
        check_admin_referer('dbdm_query_monitor');

        if (!empty($_POST['start'])) {
            DBDM_Queries::start(get_current_user_id());
        } else {
            DBDM_Queries::stop(get_current_user_id());
        }
        wp_safe_redirect(self::page_url(array('tab' => 'queries')));
        exit;
    }

    /**
     * Pagina Plugin (2.0.0, bug 51): le costanti di debug in wp-config.php
     * restano dopo la disinstallazione, quindi vengono elencate.
     */
    public function plugin_row_meta($meta, $file) {
        if ($file !== DBDM_Uninstall::BASENAME || !current_user_can(self::cap())) return $meta;
        $left = DBDM_Uninstall::leftover_constants(DBDM_Config::get_config_path());
        if ($left) {
            $meta[] = esc_html(sprintf(
                __('Eliminando il plugin restano in wp-config.php: %s', 'db-debug-manager'),
                implode(', ', $left)
            ));
        }
        return $meta;
    }
}
