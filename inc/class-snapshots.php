<?php
/**
 * DB Debug Manager — Preflight Snapshots
 *
 * Cattura lo stato del sito (plugin attivi + tema + versioni) e consente rollback.
 * Trigger: manuale, attivazione emergency, completamento upgrade WP.
 *
 * Storage: snapshots.json nella cartella privata randomizzata, FIFO, max MAX_SNAPSHOTS.
 */

if (!defined('ABSPATH')) exit;

class DBDM_Snapshots {

    const MAX_SNAPSHOTS = 5;
    const TRIGGER_MANUAL    = 'manual';
    const TRIGGER_EMERGENCY = 'emergency_enabled';
    const TRIGGER_UPGRADE   = 'wp_upgrade';

    public static function init() {
        // Snapshot quando viene attivato l'emergency.
        add_action('update_option_' . DBDM_Emergency::OPTION_ENABLED, array(__CLASS__, 'on_emergency_toggle'), 10, 2);
        add_action('add_option_' . DBDM_Emergency::OPTION_ENABLED, array(__CLASS__, 'on_emergency_add'), 10, 2);

        // Snapshot dopo un update completato (core/plugin/theme).
        add_action('upgrader_process_complete', array(__CLASS__, 'on_upgrade_complete'), 10, 2);
    }

    /**
     * Hook: attivazione/disattivazione emergency.
     * Scatta snapshot SOLO quando si passa da off a on.
     */
    public static function on_emergency_toggle($old, $new) {
        if (!$old && $new) {
            self::create(self::TRIGGER_EMERGENCY, __('Attivazione Emergency Access', 'db-debug-manager'));
        }
    }

    public static function on_emergency_add($option, $value) {
        if ($value) {
            self::create(self::TRIGGER_EMERGENCY, __('Attivazione Emergency Access', 'db-debug-manager'));
        }
    }

    /**
     * Hook: dopo upgrade WP. $hook_extra contiene action/type/plugins/themes.
     */
    public static function on_upgrade_complete($upgrader, $hook_extra) {
        $type = isset($hook_extra['type']) ? $hook_extra['type'] : '';
        $action = isset($hook_extra['action']) ? $hook_extra['action'] : '';

        if ($action !== 'update' && $action !== 'install') return;
        if (!in_array($type, array('plugin', 'theme', 'core'), true)) return;

        $note = sprintf(__('Dopo %1$s %2$s', 'db-debug-manager'), $action, $type);

        if ($type === 'plugin' && !empty($hook_extra['plugins'])) {
            $note .= ': ' . implode(', ', array_slice((array) $hook_extra['plugins'], 0, 3));
        } elseif ($type === 'theme' && !empty($hook_extra['themes'])) {
            $note .= ': ' . implode(', ', array_slice((array) $hook_extra['themes'], 0, 3));
        }

        self::create(self::TRIGGER_UPGRADE, $note);
    }

    /**
     * Path del file snapshot JSON.
     */
    public static function storage_path() {
        // Riusa la cartella privata (randomizzata) gestita da DBDM_Emergency.
        return DBDM_Emergency::private_dir() . 'snapshots.json';
    }

    /**
     * Crea uno snapshot dello stato attuale.
     * Ritorna l'ID del nuovo snapshot o WP_Error.
     */
    public static function create($trigger = self::TRIGGER_MANUAL, $note = '') {
        $snapshot = self::capture_state();
        $snapshot['id']        = uniqid('snap_', true);
        $snapshot['trigger']   = $trigger;
        $snapshot['note']      = (string) $note;
        $snapshot['timestamp'] = time();
        $snapshot['created_by'] = get_current_user_id();

        $all = self::get_all();

        // Dedup: se l'ultimo snapshot ha stato identico (e stesso trigger entro 60s), skippa.
        if (!empty($all)) {
            $last = $all[count($all) - 1];
            if ($last['trigger'] === $trigger
                && (time() - $last['timestamp']) < 60
                && self::states_equal($last, $snapshot)) {
                return $last['id'];
            }
        }

        $all[] = $snapshot;

        // FIFO: tieni solo gli ultimi MAX_SNAPSHOTS.
        if (count($all) > self::MAX_SNAPSHOTS) {
            $all = array_slice($all, -self::MAX_SNAPSHOTS);
        }

        if (!self::write_all($all)) {
            return new WP_Error('dbdm_snap_write_fail', __('Impossibile scrivere file snapshot.', 'db-debug-manager'));
        }
        return $snapshot['id'];
    }

    /**
     * Cattura lo stato corrente (senza ID/trigger/timestamp).
     */
    public static function capture_state() {
        $active_plugins = (array) get_option('active_plugins', array());
        $stylesheet     = (string) get_option('stylesheet', '');
        $template       = (string) get_option('template', '');

        $plugin_versions = array();
        if (function_exists('get_plugins')) {
            $all_plugins = get_plugins();
            foreach ($all_plugins as $file => $data) {
                $plugin_versions[$file] = array(
                    'name'    => isset($data['Name']) ? $data['Name'] : $file,
                    'version' => isset($data['Version']) ? $data['Version'] : '',
                    'active'  => in_array($file, $active_plugins, true),
                );
            }
        }

        $theme_versions = array();
        if (function_exists('wp_get_themes')) {
            foreach (wp_get_themes() as $slug => $theme) {
                $theme_versions[$slug] = array(
                    'name'    => $theme->get('Name'),
                    'version' => $theme->get('Version'),
                );
            }
        }

        return array(
            'active_plugins'  => $active_plugins,
            'stylesheet'      => $stylesheet,
            'template'        => $template,
            'plugin_versions' => $plugin_versions,
            'theme_versions'  => $theme_versions,
            'wp_version'      => get_bloginfo('version'),
        );
    }

    /**
     * Due snapshot hanno stesso stato "sensibile" (plugin attivi + tema + versioni)?
     */
    private static function states_equal($a, $b) {
        $fields = array('active_plugins', 'stylesheet', 'template');
        foreach ($fields as $f) {
            $va = isset($a[$f]) ? $a[$f] : null;
            $vb = isset($b[$f]) ? $b[$f] : null;
            if (is_array($va)) sort($va);
            if (is_array($vb)) sort($vb);
            if ($va !== $vb) return false;
        }
        // Confronta solo versioni dei plugin (ignora nomi).
        $va = array();
        foreach (($a['plugin_versions'] ?? array()) as $k => $v) $va[$k] = $v['version'] ?? '';
        $vb = array();
        foreach (($b['plugin_versions'] ?? array()) as $k => $v) $vb[$k] = $v['version'] ?? '';
        ksort($va);
ksort($vb);
        return $va === $vb;
    }

    /**
     * Tutti gli snapshot ordinati cronologicamente (oldest first).
     */
    public static function get_all() {
        $path = self::storage_path();
        if (!file_exists($path)) return array();
        $raw = @file_get_contents($path);
        if (!$raw) return array();
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    public static function get_by_id($id) {
        foreach (self::get_all() as $s) {
            if ($s['id'] === $id) return $s;
        }
        return null;
    }

    public static function delete($id) {
        $all = self::get_all();
        $filtered = array_values(array_filter($all, function ($s) use ($id) {
 return $s['id'] !== $id;
}));
        return self::write_all($filtered);
    }

    public static function delete_all() {
        return self::write_all(array());
    }

    /**
     * 1.4.0: un nome o una versione con UTF-8 non valido (header di un
     * plugin in Latin-1) facevano fallire json_encode e la scrittura di una
     * stringa vuota cancellava TUTTI gli snapshot. Ora i caratteri non validi
     * vengono sostituiti, un errore non scrive nulla e la scrittura è
     * atomica.
     */
    private static function write_all($data) {
        $json = json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return false;
        }
        return DBDM_Standalone_Config::write_file(self::storage_path(), $json, 0640) === true;
    }

    /**
     * Calcola diff tra snapshot e stato attuale (o tra due snapshot).
     *
     * Ritorna:
     * [
     *   'plugins_activated'   => [file, ...],   // attivi ORA, non nello snapshot
     *   'plugins_deactivated' => [file, ...],   // attivi nello snapshot, non ORA
     *   'plugins_updated'     => [file => [from, to]],
     *   'plugins_installed'   => [file => name],
     *   'plugins_removed'     => [file => name],
     *   'theme_changed'       => [from, to] | null,
     *   'wp_version_changed'  => [from, to] | null,
     * ]
     */
    public static function diff_with_current($snapshot) {
        $current = self::capture_state();
        return self::diff($snapshot, $current);
    }

    public static function diff($a, $b) {
        $a_active = (array) ($a['active_plugins'] ?? array());
        $b_active = (array) ($b['active_plugins'] ?? array());

        $activated   = array_values(array_diff($b_active, $a_active));
        $deactivated = array_values(array_diff($a_active, $b_active));

        $a_versions = $a['plugin_versions'] ?? array();
        $b_versions = $b['plugin_versions'] ?? array();

        $updated   = array();
        $installed = array();
        $removed   = array();

        foreach ($b_versions as $file => $info) {
            if (!isset($a_versions[$file])) {
                $installed[$file] = $info['name'] ?? $file;
            } elseif (($a_versions[$file]['version'] ?? '') !== ($info['version'] ?? '')) {
                $updated[$file] = array(
                    'name' => $info['name'] ?? $file,
                    'from' => $a_versions[$file]['version'] ?? '—',
                    'to'   => $info['version'] ?? '—',
                );
            }
        }
        foreach ($a_versions as $file => $info) {
            if (!isset($b_versions[$file])) {
                $removed[$file] = $info['name'] ?? $file;
            }
        }

        $theme_changed = null;
        if (($a['stylesheet'] ?? '') !== ($b['stylesheet'] ?? '')) {
            $theme_changed = array(
                'from' => $a['stylesheet'] ?? '—',
                'to'   => $b['stylesheet'] ?? '—',
            );
        }

        $wp_changed = null;
        if (($a['wp_version'] ?? '') !== ($b['wp_version'] ?? '')) {
            $wp_changed = array(
                'from' => $a['wp_version'] ?? '—',
                'to'   => $b['wp_version'] ?? '—',
            );
        }

        return array(
            'plugins_activated'   => $activated,
            'plugins_deactivated' => $deactivated,
            'plugins_updated'     => $updated,
            'plugins_installed'   => $installed,
            'plugins_removed'     => $removed,
            'theme_changed'       => $theme_changed,
            'wp_version_changed'  => $wp_changed,
        );
    }

    public static function diff_is_empty($d) {
        return empty($d['plugins_activated'])
            && empty($d['plugins_deactivated'])
            && empty($d['plugins_updated'])
            && empty($d['plugins_installed'])
            && empty($d['plugins_removed'])
            && empty($d['theme_changed'])
            && empty($d['wp_version_changed']);
    }

    /**
     * Ripristina dallo snapshot.
     * $parts: array con 'plugins' e/o 'theme' — cosa ripristinare.
     * Ritorna array di messaggi di esito.
     */
    public static function restore($id, $parts = array('plugins', 'theme')) {
        $snap = self::get_by_id($id);
        if (!$snap) return array(array('err', __('Snapshot non trovato.', 'db-debug-manager')));

        $messages = array();

        if (in_array('plugins', $parts, true)) {
            // Filtra solo plugin che esistono ancora sul filesystem (evita fatal su plugin rimossi).
            $existing = array_keys($snap['plugin_versions'] ?? array());
            $current_installed = function_exists('get_plugins') ? array_keys(get_plugins()) : $existing;
            $to_activate = array_values(array_intersect($snap['active_plugins'] ?? array(), $current_installed));

            update_option('active_plugins', $to_activate);
            $messages[] = array('ok', sprintf(
                __('Plugin attivi ripristinati: %d.', 'db-debug-manager'),
                count($to_activate)
            ));

            $missing = array_diff($snap['active_plugins'] ?? array(), $current_installed);
            if (!empty($missing)) {
                $messages[] = array('warn', sprintf(
                    __('Non ripristinati (non più installati): %s', 'db-debug-manager'),
                    implode(', ', $missing)
                ));
            }
        }

        if (in_array('theme', $parts, true)) {
            $target = isset($snap['stylesheet']) && is_string($snap['stylesheet']) ? $snap['stylesheet'] : '';
            if ($target && function_exists('wp_get_theme')) {
                $theme = wp_get_theme($target);
                if (!$theme->exists()) {
                    $messages[] = array('err', sprintf(__('Tema non più installato: %s', 'db-debug-manager'), $target));
                } elseif ($theme->errors() || ($theme->get_template() !== $target && !wp_get_theme($theme->get_template())->exists())) {
                    // 1.4.0: un child theme senza padre lascerebbe il sito bianco.
                    $messages[] = array('err', sprintf(__('Tema non ripristinato: il tema padre "%1$s" di %2$s non è installato.', 'db-debug-manager'), $theme->get_template(), $target));
                } else {
                    // 1.4.0: switch_theme() (padre dal tema stesso, hook del
                    // core, autoload invariato) invece di scrivere le opzioni.
                    switch_theme($target);
                    $messages[] = array('ok', sprintf(__('Tema ripristinato: %s', 'db-debug-manager'), $target));
                }
            }
        }

        return $messages;
    }
}
