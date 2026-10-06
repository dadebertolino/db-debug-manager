<?php
/**
 * DB Debug Manager — Query monitor
 * Cattura l'elenco query da $wpdb->queries (richiede SAVEQUERIES=true).
 *
 * 2.0.0 (bug 49): solo le pagine dell'amministratore che ha attivato il
 * monitor (per MONITOR_DURATION), mai login, REST, AJAX, cron e WP-CLI.
 * Prima ogni richiesta pubblica salvava fino a 500 query complete: email,
 * indirizzi e token dei visitatori nel database e nei backup.
 */

if (!defined('ABSPATH')) exit;

class DBDM_Queries {

    /** User meta: fino a quando il monitor è attivo per quell'utente. */
    const META_UNTIL = 'dbdm_query_monitor_until';
    const MONITOR_DURATION = 1800;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('shutdown', array($this, 'capture_snapshot'), 999);
    }

    public static function start($user_id) {
        update_user_meta((int) $user_id, self::META_UNTIL, time() + self::MONITOR_DURATION);
    }

    public static function stop($user_id) {
        delete_user_meta((int) $user_id, self::META_UNTIL);
    }

    /**
     * Secondi di monitor rimasti per l'utente (0 se spento o scaduto).
     */
    public static function remaining($user_id) {
        $until = (int) get_user_meta((int) $user_id, self::META_UNTIL, true);
        return max(0, $until - time());
    }

    /**
     * La richiesta corrente va registrata?
     */
    public static function should_capture() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (defined('WP_CLI') && WP_CLI) return false;
        if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') return false;
        if (!is_user_logged_in() || !current_user_can(DBDM_Admin::cap())) return false;
        return self::remaining(get_current_user_id()) > 0;
    }

    /**
     * Salva snapshot in transient per visualizzazione in admin.
     */
    public function capture_snapshot() {
        global $wpdb;
        if (empty($wpdb->queries) || !self::should_capture()) {
            return;
        }

        $data = array(
            'url'        => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'time'       => current_time('mysql'),
            'num'        => count($wpdb->queries),
            'total_time' => 0,
            'queries'    => array(),
        );

        foreach ($wpdb->queries as $q) {
            // $q: [0] => sql, [1] => time, [2] => stack
            $data['total_time'] += $q[1];
            // Limito l'accumulo a 500 query per transient (evito payload enormi).
            if (count($data['queries']) < 500) {
                $data['queries'][] = array(
                    'sql'   => $q[0],
                    'time'  => $q[1],
                    'stack' => $q[2],
                );
            }
        }

        set_transient('dbdm_last_queries', $data, HOUR_IN_SECONDS);
    }

    /**
     * Ultimo snapshot salvato.
     */
    public static function get_last_snapshot() {
        return get_transient('dbdm_last_queries');
    }

    public static function clear_snapshot() {
        delete_transient('dbdm_last_queries');
    }
}
