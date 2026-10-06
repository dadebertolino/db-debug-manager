<?php
/**
 * DB Debug Manager — Emergency: flusso di una richiesta.
 *
 * Configurazione e database, controlli di attivazione, cartella privata,
 * logout, login con limite dei tentativi, azioni, dashboard. È l'unica
 * classe dell'emergency che invia header o avvia la sessione.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_App')) return;

class DBDM_Em_App {

    /** @var string Cartella del plugin, senza slash finale. */
    private $plugin_dir;

    /** @var callable function(array $creds): PDO|false */
    private $connect;

    /**
     * @param string        $plugin_dir
     * @param callable|null $connect    Connessione al database (nei test);
     *                                  di default DBDM_Standalone_Config::connect().
     */
    public function __construct($plugin_dir, $connect = null) {
        $this->plugin_dir = rtrim($plugin_dir, '/');
        $this->connect    = $connect ? $connect : array('DBDM_Standalone_Config', 'connect');
    }

    /**
     * wp-content, plugin e temi del sito. 2.0.0 (bug 21): dai percorsi
     * salvati da WordPress (DBDM_Emergency::site_paths(), che conosce
     * WP_CONTENT_DIR, WP_PLUGIN_DIR e la cartella dei temi), ciascuno solo
     * se è una cartella esistente; altrimenti dalla posizione del plugin
     * (wp-content/plugins/db-debug-manager).
     *
     * @param mixed  $saved      Opzione dbdm_site_paths.
     * @param string $plugin_dir
     * @return array{content_dir:string,plugins_dir:string,themes_dir:string}
     */
    public static function site_paths($saved, $plugin_dir) {
        $plugins = dirname(rtrim($plugin_dir, '/'));
        $content = dirname($plugins);
        $paths   = array(
            'content_dir' => $content,
            'plugins_dir' => $plugins,
            'themes_dir'  => $content . '/themes',
        );
        foreach ($paths as $key => $default) {
            $value = is_array($saved) && isset($saved[$key]) ? $saved[$key] : null;
            if (is_string($value) && $value !== '' && $value[0] === '/' && is_dir($value)) {
                $paths[$key] = rtrim($value, '/');
            }
        }
        return $paths;
    }

    /**
     * Cartella privata creata dal pannello: dal percorso salvato, o dal token
     * nella posizione standard (wp-content/dbdm-private-{token}/). Il nome deve
     * avere la forma attesa: un valore manomesso nel DB non può indicare
     * un'altra cartella. 1.4.0: nessun ripiego su una cartella dal nome
     * prevedibile: senza cartella privata l'accesso è negato.
     *
     * @return string Percorso con slash finale, '' se non trovata.
     */
    public static function resolve_private_dir($path, $token, $content_dir) {
        $candidates = array();
        if (is_string($path) && $path !== '') $candidates[] = rtrim($path, '/');
        if (is_string($token) && preg_match('/^[a-f0-9]{16}$/', $token)) {
            $candidates[] = $content_dir . '/dbdm-private-' . $token;
        }
        foreach ($candidates as $dir) {
            if (preg_match('/^dbdm-private-[a-f0-9]{16}$/', basename($dir)) && is_dir($dir)) {
                return $dir . '/';
            }
        }
        return '';
    }

    public function run(DBDM_Em_Request $request) {
        DBDM_Em_Session::start($request);
        $this->dispatch($request, new DBDM_Em_Session($_SESSION));
    }

    /**
     * Risponde a una richiesta con la sessione già avviata.
     * 2.0.0 (bug 34): un errore del database (tipicamente $table_prefix che
     * non corrisponde alle tabelle) dà una pagina d'errore invece di una
     * risposta 500 vuota; il dettaglio va solo nel log degli errori di PHP.
     */
    public function dispatch(DBDM_Em_Request $request, DBDM_Em_Session $session) {
        $view = new DBDM_Em_View($session);
        try {
            $this->handle($request, $session, $view);
        } catch (PDOException $e) {
            error_log('DB Debug Manager emergency: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- unico canale per i dettagli, fuori dalla pagina.
            $view->error('Errore nella lettura del database. Controlla che $table_prefix in wp-config.php corrisponda alle tabelle del sito; il dettaglio è nel log degli errori di PHP.');
        }
    }

    private function handle(DBDM_Em_Request $request, DBDM_Em_Session $session, DBDM_Em_View $view) {

        // Configurazione e database.
        $config_path = DBDM_Standalone_Config::find_wp_config($this->plugin_dir);
        if (!$config_path) {
            $view->error('wp-config.php non trovato.');
            return;
        }
        $creds = DBDM_Standalone_Config::parse_credentials($config_path);
        if (!$creds) {
            $view->error('Impossibile leggere le credenziali da wp-config.php.');
            return;
        }
        $pdo = call_user_func($this->connect, $creds);
        if (!$pdo) {
            $view->error('Connessione al database fallita.');
            return;
        }
        $repo = new DBDM_Em_Repository($pdo, $creds['prefix']);

        // Attivazione.
        $enabled = $repo->get_option('dbdm_emergency_enabled');
        if (!$enabled || $enabled === '0') {
            $view->error('L\'accesso emergency è disattivato. Abilitalo dalla dashboard di WordPress: Strumenti → Debug Manager → Emergency.');
            return;
        }
        $stored_hash = $repo->get_option('dbdm_emergency_hash');
        if (empty($stored_hash)) {
            $view->error('Nessuna password emergency configurata.');
            return;
        }

        // Modalità proxy fidato: da qui in poi l'IP può venire dagli header proxy.
        $trust_proxy = (bool) $repo->get_option('dbdm_emergency_trust_proxy', false);

        // Sessioni legate a password ed epoca (vedi DBDM_Em_Session::is_authed()).
        $fingerprint = DBDM_Emergency_Guard::session_fingerprint(
            $stored_hash,
            $repo->get_option('dbdm_emergency_epoch', '')
        );

        $paths = self::site_paths($repo->get_option('dbdm_site_paths'), $this->plugin_dir);

        // Cartella privata (log accessi, limite tentativi, snapshot, backup).
        $private_dir = self::resolve_private_dir(
            $repo->get_option('dbdm_private_dir_path', ''),
            $repo->get_option('dbdm_private_dir_token', ''),
            $paths['content_dir']
        );
        if ($private_dir === '') {
            $view->error('Cartella privata del plugin non trovata. Apri una volta il pannello Debug Manager da WordPress per crearla.');
            return;
        }
        if (!is_writable($private_dir)) {
            // Senza cartella scrivibile non c'è limite ai tentativi: accesso negato.
            $view->error('La cartella privata del plugin non è scrivibile dal server web: accesso negato.');
            return;
        }
        $rl_file = $private_dir . 'emergency-ratelimit.json';
        $ip      = $request->ip($trust_proxy);
        $rl_key  = DBDM_Emergency_Guard::rate_key($ip);
        $logger  = new DBDM_Em_Logger($private_dir . 'emergency-access.log', $ip, $request->user_agent());
        $action  = $request->action();

        // Logout.
        if ($action === 'logout') {
            $session->clear();
            session_destroy();
            $this->redirect($request);
            return;
        }

        // Login.
        if (!$session->is_authed($fingerprint) && $request->is_post() && $request->has_post('password')) {
            if (!$session->csrf_check($request->post_string('csrf'))) {
                $logger->log('LOGIN_CSRF_FAIL');
                $view->login('Token di sessione non valido. Ricarica la pagina.');
                return;
            }
            // 1.4.0: il tentativo viene contato PRIMA della verifica, sotto lock:
            // anche con richieste parallele non si superano i 5 tentativi.
            $attempt = DBDM_Emergency_Guard::reserve_attempt($rl_file, $rl_key);
            if ($attempt['error'] !== '') {
                $logger->log('LOGIN_REFUSED', 'rate limit non disponibile');
                $view->login('Limite dei tentativi non disponibile: accesso negato.');
                return;
            }
            if (!$attempt['allowed']) {
                $logger->log('LOGIN_BLOCKED', 'IP locked, ' . $attempt['retry_after'] . 's remaining');
                $view->login('Troppi tentativi. Riprova tra ' . ceil($attempt['retry_after'] / 60) . ' minuti.');
                return;
            }
            $password = $request->post_string('password');
            if ($password !== '' && password_verify($password, $stored_hash)) {
                // 1.4.0: nuovo ID di sessione al login (niente session fixation).
                session_regenerate_id(true);
                $session->login($fingerprint);
                DBDM_Emergency_Guard::reset($rl_file, $rl_key);
                $logger->log('LOGIN_SUCCESS');
                $this->redirect($request);
                return;
            }
            $logger->log('LOGIN_FAIL');
            $view->login('Password errata.');
            return;
        }

        if (!$session->is_authed($fingerprint)) {
            $lock = DBDM_Emergency_Guard::locked_for($rl_file, $rl_key);
            $view->login($lock > 0 ? 'IP bloccato. Riprova tra ' . ceil($lock / 60) . ' minuti.' : '');
            return;
        }

        $actions = new DBDM_Em_Actions($repo, $logger, $paths + array(
            'config_path' => $config_path,
            'private_dir' => $private_dir,
        ));
        $notices = self::handle_actions($request, $session, $actions, $logger);

        $view->dashboard($notices, DBDM_Em_Status::collect($repo, $config_path, $paths['content_dir'], $private_dir));
    }

    /**
     * Azioni della dashboard: solo in POST e con token CSRF valido. 2.0.0
     * (bug 30): un token scaduto o mancante non passa più in silenzio.
     *
     * @return array[] Avvisi.
     */
    public static function handle_actions(DBDM_Em_Request $request, DBDM_Em_Session $session, DBDM_Em_Actions $actions, DBDM_Em_Logger $logger) {
        $action = $request->action();
        if (!$request->is_post() || $action === '') return array();
        if (!$session->csrf_check($request->post_string('csrf'))) {
            $logger->log('ACTION_CSRF_FAIL', $action);
            return array(array('err', 'Modulo scaduto: azione non eseguita. Riprova.'));
        }
        return $actions->run($action, $request);
    }

    private function redirect(DBDM_Em_Request $request) {
        header('Location: ' . $request->path());
    }
}
