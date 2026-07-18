<?php
/**
 * DB Debug Manager — Query monitor
 * Cattura l'elenco query da $wpdb->queries (richiede SAVEQUERIES=true).
 * Le query dell'admin UI stessa vengono escluse dal report.
 */

if (!defined('ABSPATH')) exit;

class DBDM_Queries {

    private static $instance = null;
    private $snapshot = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Salva uno snapshot in chiusura di una pagina frontend (non admin).
        // L'admin di solito genera molte query irrilevanti per il monitor.
        add_action('shutdown', array($this, 'capture_snapshot'), 999);
    }

    /**
     * Salva snapshot in transient per visualizzazione in admin.
     */
    public function capture_snapshot() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (!defined('SAVEQUERIES') || !SAVEQUERIES) {
            return;
        }
        global $wpdb;
        if (empty($wpdb->queries)) {
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
