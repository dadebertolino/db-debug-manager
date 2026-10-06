<?php
/**
 * DB Debug Manager — Emergency: azioni di ripristino.
 *
 * Disattivazione dei plugin, cambio di tema, transient, costanti di debug,
 * debug.log, ripristino di uno snapshot. Ogni azione restituisce gli avvisi
 * da mostrare: array(tipo, messaggio), tipo 'ok' (eseguita), 'warn' (nulla
 * da cambiare) o 'err'. CSRF e login sono verificati prima, da DBDM_Em_App.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Actions')) return;

class DBDM_Em_Actions {

    /** Temi di default, dal più recente. */
    const DEFAULT_THEMES = array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty');

    /** @var DBDM_Em_Repository */
    private $repo;

    /** @var DBDM_Em_Logger */
    private $logger;

    /** @var string */
    private $config_path;

    /** @var string Con slash finale. */
    private $private_dir;

    /** @var string */
    private $content_dir;

    /** @var string */
    private $plugins_dir;

    /** @var string */
    private $themes_dir;

    /**
     * @param DBDM_Em_Repository $repo
     * @param DBDM_Em_Logger     $logger
     * @param array              $paths config_path, private_dir (con slash
     *                                  finale), content_dir, plugins_dir,
     *                                  themes_dir.
     */
    public function __construct(DBDM_Em_Repository $repo, DBDM_Em_Logger $logger, array $paths) {
        $this->repo        = $repo;
        $this->logger      = $logger;
        $this->config_path = $paths['config_path'];
        $this->private_dir = $paths['private_dir'];
        $this->content_dir = $paths['content_dir'];
        $this->plugins_dir = $paths['plugins_dir'];
        $this->themes_dir  = $paths['themes_dir'];
    }

    /**
     * Esegue l'azione richiesta.
     *
     * @return array[] Avvisi.
     */
    public function run($action, DBDM_Em_Request $request) {
        $methods = array(
            'disable_all_plugins'     => 'disable_all_plugins',
            'disable_plugin'          => 'disable_plugin',
            'switch_to_default_theme' => 'switch_to_default_theme',
            'clear_transients'        => 'clear_transients',
            'toggle_const'            => 'toggle_const',
            'clear_log'               => 'clear_log',
            'restore_snapshot'        => 'restore_snapshot',
        );
        if (!isset($methods[$action])) return array(array('err', 'Azione non riconosciuta.'));
        try {
            return $this->{$methods[$action]}($request);
        } catch (Exception $e) {
            $this->logger->log('ACTION_ERR', $action . ': ' . $e->getMessage());
            return array(array('err', 'Errore: ' . $e->getMessage()));
        }
    }

    private function disable_all_plugins(DBDM_Em_Request $request) {
        $current = $this->repo->active_plugins();
        if ($current === null) return array(self::unreadable_plugins());
        if (!$current) return array(array('warn', 'Nessun plugin era attivo.'));
        $this->repo->update_option('active_plugins', array());
        $this->logger->log('ACTION', 'disable_all_plugins');
        return array(array('ok', 'Tutti i plugin sono stati disattivati.'));
    }

    /**
     * Il modulo invia lo slug in esadecimale (DBDM_Em_View): torna identico
     * anche con byte non UTF-8, che la pagina mostra sostituiti.
     */
    private function disable_plugin(DBDM_Em_Request $request) {
        $key  = $request->post_string('plugin');
        $slug = $key !== '' && strlen($key) % 2 === 0 && ctype_xdigit($key) ? hex2bin($key) : '';
        if ($slug === '' || $slug === false) return array(array('err', 'Plugin non indicato.'));

        $current = $this->repo->active_plugins();
        if ($current === null) return array(self::unreadable_plugins());
        if (!in_array($slug, $current, true)) {
            return array(array('warn', 'Il plugin non era attivo: ' . $slug));
        }
        $new = array_values(array_filter($current, function ($p) use ($slug) {
            return $p !== $slug;
        }));
        $this->repo->update_option('active_plugins', $new);
        $this->logger->log('ACTION', 'disable_plugin: ' . $slug);
        return array(array('ok', 'Plugin disattivato: ' . $slug));
    }

    private static function unreadable_plugins() {
        return array('err', 'Elenco dei plugin attivi mancante o illeggibile nel database: nessuna modifica.');
    }

    private function switch_to_default_theme(DBDM_Em_Request $request) {
        $exclude = array_filter(array($this->repo->get_option('stylesheet'), $this->repo->get_option('template')), 'is_string');
        $target  = self::default_theme($this->themes_dir, $exclude);
        if (!$target) {
            return array(array('err', 'Nessun tema alternativo trovato in ' . $this->themes_dir . '.'));
        }
        $this->repo->update_option('template', $target);
        $this->repo->update_option('stylesheet', $target);
        $this->logger->log('ACTION', 'switch_theme: ' . $target);
        return array(array('ok', 'Tema cambiato a: ' . $target));
    }

    /**
     * Tema da attivare al posto di quello attuale: il tema default più
     * recente installato, altrimenti il primo in ordine alfabetico.
     * 2.0.0 (bug 28): mai il tema attivo né il suo padre (probabilmente la
     * causa del problema), mai un child theme; solo temi con style.css.
     *
     * @param string   $themes_dir
     * @param string[] $exclude    Stylesheet e template attivi.
     * @return string|null
     */
    public static function default_theme($themes_dir, array $exclude = array()) {
        $entries    = @scandir($themes_dir);
        $candidates = array_merge(self::DEFAULT_THEMES, is_array($entries) ? $entries : array());
        foreach ($candidates as $slug) {
            if ($slug === '' || $slug[0] === '.' || in_array($slug, $exclude, true)) continue;
            $theme = DBDM_Emergency_Guard::restorable_theme($slug, $themes_dir);
            if ($theme['ok'] && $theme['template'] === $slug) return $slug;
        }
        return null;
    }

    private function clear_transients(DBDM_Em_Request $request) {
        $deleted = $this->repo->delete_transients();
        if ($deleted === 0) return array(array('warn', 'Nessun transient da eliminare.'));
        $this->logger->log('ACTION', 'clear_transients: ' . $deleted);
        return array(array('ok', $deleted . ' transient eliminati.'));
    }

    private function toggle_const(DBDM_Em_Request $request) {
        $name  = $request->post_string('const');
        $value = $request->post_flag('enable');
        if (!in_array($name, DBDM_Em_Status::MANAGED_CONSTANTS, true)) return array();

        $r = $this->toggle_constant($name, $value);
        if ($r !== true) {
            return array(array('err', 'Errore: ' . $r));
        }
        $this->logger->log('ACTION', 'toggle_const: ' . $name . ' = ' . ($value ? 'true' : 'false'));
        return array(array('ok', $name . ' impostata a ' . ($value ? 'true' : 'false')));
    }

    /**
     * Scrive una costante di debug con la logica condivisa di
     * DBDM_Standalone_Config, che include il controllo di sintassi prima
     * della scrittura (in emergency un wp-config rotto sarebbe il caso
     * peggiore possibile).
     *
     * @return true|string true o messaggio di errore.
     */
    private function toggle_constant($name, $value) {
        $source = $value ? 'true' : 'false';
        if ($name === 'WP_DEBUG_LOG') {
            // Come il pannello (DBDM_Admin::constants_to_write()): acceso, il
            // log va sul percorso personalizzato ricordato o nella cartella
            // privata, mai in wp-content/debug.log raggiungibile da chiunque
            // (1.4.0); spento, un percorso personalizzato viene ricordato in
            // dbdm_debug_log_path per la riaccensione (bug 20).
            $public = $this->content_dir . '/debug.log';
            $path   = DBDM_Em_Status::resolve_log_path(DBDM_Em_Status::debug_log_value($this->config_path), $this->content_dir);
            $custom = $path !== '' && $path !== $public;
            if ($value) {
                if ($custom) return true; // Già attivo su un percorso non pubblico.
                $saved  = DBDM_Em_Status::resolve_log_path($this->repo->get_option('dbdm_debug_log_path', ''), $this->content_dir);
                $source = var_export($saved !== '' && $saved !== $public ? $saved : $this->private_dir . 'debug.log', true);
            } elseif ($custom) {
                $this->repo->save_option('dbdm_debug_log_path', $path);
            }
        }
        $result = DBDM_Standalone_Config::set_constants(
            $this->config_path,
            array($name => $source),
            $this->private_dir . 'wp-config.dbdm-bak'
        );
        // Rimuove l'eventuale backup legacy esposto (versioni <= 1.2.0).
        if (file_exists($this->config_path . '.dbdm-bak')) {
            @unlink($this->config_path . '.dbdm-bak');
        }
        return $result;
    }

    private function clear_log(DBDM_Em_Request $request) {
        $log_path = DBDM_Em_Status::debug_log_path($this->config_path, $this->content_dir);
        if (!file_exists($log_path) || !is_writable($log_path)) {
            return array(array('err', 'debug.log non scrivibile o assente.'));
        }
        if (filesize($log_path) === 0) {
            return array(array('warn', 'debug.log era già vuoto.'));
        }
        file_put_contents($log_path, '');
        $this->logger->log('ACTION', 'clear_debug_log');
        return array(array('ok', 'debug.log svuotato.'));
    }

    private function restore_snapshot(DBDM_Em_Request $request) {
        $snap_id         = $request->post_string('snap_id');
        $restore_plugins = $request->post_flag('restore_plugins');
        $restore_theme   = $request->post_flag('restore_theme');
        $snaps_file      = $this->private_dir . 'snapshots.json';

        if ($snap_id === '' || (!$restore_plugins && !$restore_theme)) {
            return array(array('err', 'Parametri ripristino incompleti.'));
        }
        if (!file_exists($snaps_file)) {
            return array(array('err', 'Nessuno snapshot disponibile.'));
        }
        $target = null;
        foreach (DBDM_Em_Status::snapshots($snaps_file) as $s) {
            if (isset($s['id']) && $s['id'] === $snap_id) {
                $target = $s;
                break;
            }
        }
        if (!$target) {
            return array(array('err', 'Snapshot non trovato.'));
        }

        $notices = array();

        // Plugin attivi. 1.4.0: solo percorsi validi di plugin ancora
        // installati (niente cartelle, risalite, non-stringhe).
        if ($restore_plugins && isset($target['active_plugins'])) {
            list($valid, $missing) = DBDM_Emergency_Guard::restorable_plugins($target['active_plugins'], $this->plugins_dir);
            $this->repo->update_option('active_plugins', $valid);
            $this->logger->log('ACTION', 'restore_snapshot plugins: ' . $snap_id . ', ' . count($valid) . ' plugins');
            $notices[] = array('ok', 'Plugin attivi ripristinati: ' . count($valid) . ' plugin.');
            if (!empty($missing)) {
                $notices[] = array('err', 'Non ripristinati (non più presenti o non validi): ' . implode(', ', $missing));
            }
        }

        // Tema. 1.4.0: il tema padre viene letto dal tema e deve essere
        // installato, altrimenti il sito resterebbe bianco.
        if ($restore_theme && isset($target['stylesheet'])) {
            $theme = DBDM_Emergency_Guard::restorable_theme($target['stylesheet'], $this->themes_dir);
            if ($theme['ok']) {
                $this->repo->update_option('stylesheet', $theme['stylesheet']);
                $this->repo->update_option('template', $theme['template']);
                $this->logger->log('ACTION', 'restore_snapshot theme: ' . $theme['stylesheet']);
                $notices[] = array('ok', 'Tema ripristinato: ' . $theme['stylesheet']);
            } else {
                $notices[] = array('err', 'Tema non ripristinato (' . $theme['error'] . '): ' . $theme['stylesheet']);
            }
        }

        return $notices;
    }
}
