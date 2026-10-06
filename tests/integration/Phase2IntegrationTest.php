<?php
/**
 * Fase 2 su WordPress vero: snapshot prima degli aggiornamenti (bug 45),
 * ripristino dei plugin con gli hook del core e plugin di rete (bug 46, 47),
 * monitor query solo per l'amministratore che lo attiva (bug 49),
 * disinstallazione (bug 51), token della cartella privata concorrente (bug 55).
 *
 * @package DBDM\Tests\Integration
 */

class Phase2IntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		DBDM_Snapshots::delete_all();
		$ref = new ReflectionProperty( 'DBDM_Snapshots', 'pre_upgrade_done' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );
	}

	public function tear_down() {
		$GLOBALS['wpdb']->queries = array();
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function admin() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
		return $user;
	}

	/* --- Snapshot prima degli aggiornamenti (bug 45) ----------------------- */

	public function test_uno_snapshot_prima_degli_aggiornamenti_per_richiesta(): void {
		$extra = array( 'plugin' => 'hello.php', 'type' => 'plugin', 'action' => 'update' );

		$this->assertTrue( apply_filters( 'upgrader_pre_install', true, $extra ), 'filtro trasparente' );
		apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'akismet/akismet.php' ) );

		$all = DBDM_Snapshots::get_all();
		$this->assertCount( 1, $all, 'un aggiornamento in blocco occupa un solo posto' );
		$this->assertSame( DBDM_Snapshots::TRIGGER_PRE_UPGRADE, $all[0]['trigger'] );
		$this->assertStringContainsString( 'hello.php', $all[0]['note'] );
	}

	public function test_nessuno_snapshot_se_l_installazione_e_gia_fallita(): void {
		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, apply_filters( 'upgrader_pre_install', $error, array( 'plugin' => 'hello.php' ) ) );
		$this->assertSame( array(), DBDM_Snapshots::get_all() );
	}

	public function test_dopo_l_aggiornamento_solo_per_il_core(): void {
		do_action( 'upgrader_process_complete', null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'hello.php' ) ) );
		$this->assertSame( array(), DBDM_Snapshots::get_all() );

		do_action( 'upgrader_process_complete', null, array( 'type' => 'core', 'action' => 'update' ) );
		$all = DBDM_Snapshots::get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( DBDM_Snapshots::TRIGGER_UPGRADE, $all[0]['trigger'] );
	}

	public function test_i_manuali_restano_dopo_molti_aggiornamenti(): void {
		$this->admin();
		$manual = array();
		for ( $i = 0; $i < 5; $i++ ) {
			// Stato diverso a ogni snapshot, altrimenti la deduplica li unisce.
			update_option( 'stylesheet', 0 === $i % 2 ? 'a' : 'b' );
			$manual[] = DBDM_Snapshots::create( DBDM_Snapshots::TRIGGER_MANUAL, "manuale $i" );
		}
		for ( $i = 0; $i < 6; $i++ ) {
			update_option( 'stylesheet', 0 === $i % 2 ? 'c' : 'd' );
			DBDM_Snapshots::create( DBDM_Snapshots::TRIGGER_UPGRADE, "core $i" );
		}

		$ids = array_column( DBDM_Snapshots::get_all(), 'id' );
		foreach ( $manual as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertCount( 10, $ids );
	}

	/* --- Ripristino dei plugin (bug 46, 47) ------------------------------- */

	const HOOK_PLUGIN = 'dbdm-hook/dbdm-hook.php';

	/**
	 * Plugin di prova che registra attivazione e disattivazione.
	 */
	private function hook_plugin() {
		$dir = WP_PLUGIN_DIR . '/dbdm-hook';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents(
			$dir . '/dbdm-hook.php',
			"<?php\n/*\nPlugin Name: DBDM hook\nNetwork: false\n*/\n" .
			"register_activation_hook( __FILE__, function () { update_option( 'dbdm_hook_activated', 'si' ); } );\n" .
			"register_deactivation_hook( __FILE__, function () { update_option( 'dbdm_hook_deactivated', 'si' ); } );\n"
		);
		wp_clean_plugins_cache( false );
	}

	private function remove_hook_plugin() {
		@unlink( WP_PLUGIN_DIR . '/dbdm-hook/dbdm-hook.php' );
		@rmdir( WP_PLUGIN_DIR . '/dbdm-hook' );
		wp_clean_plugins_cache( false );
	}

	private function snapshot_with( array $state ) {
		$all   = DBDM_Snapshots::get_all();
		$all[] = array_merge( array( 'id' => 'snap_p2_' . count( $all ), 'trigger' => 'manual', 'timestamp' => time() ), $state );
		dbdm_integration_write_snapshots( $all );
		return end( $all )['id'];
	}

	public function test_il_ripristino_esegue_gli_hook_di_attivazione_e_disattivazione(): void {
		$this->admin();
		$this->hook_plugin();
		try {
			$messages = DBDM_Snapshots::restore( $this->snapshot_with( array( 'active_plugins' => array( self::HOOK_PLUGIN, 'non-esiste/x.php' ) ) ), array( 'plugins' ) );

			$this->assertTrue( is_plugin_active( self::HOOK_PLUGIN ) );
			$this->assertSame( 'si', get_option( 'dbdm_hook_activated' ), 'hook di attivazione eseguito' );
			$this->assertSame( array( 'ok', 'Plugin attivi ripristinati: 1.' ), $messages[0] );
			$this->assertSame( 'warn', $messages[1][0] );
			$this->assertStringContainsString( 'non-esiste/x.php', $messages[1][1] );

			DBDM_Snapshots::restore( $this->snapshot_with( array( 'active_plugins' => array() ) ), array( 'plugins' ) );

			$this->assertFalse( is_plugin_active( self::HOOK_PLUGIN ) );
			$this->assertSame( 'si', get_option( 'dbdm_hook_deactivated' ), 'hook di disattivazione eseguito' );
		} finally {
			deactivate_plugins( self::HOOK_PLUGIN, true );
			$this->remove_hook_plugin();
		}
	}

	public function test_il_ripristino_non_disattiva_il_debug_manager(): void {
		$self = plugin_basename( DBDM_PLUGIN_FILE );
		update_option( 'active_plugins', array( $self ) );

		DBDM_Snapshots::restore( $this->snapshot_with( array( 'active_plugins' => array() ) ), array( 'plugins' ) );

		$this->assertContains( $self, (array) get_option( 'active_plugins' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_in_multisite_plugin_di_rete_catturati_e_ripristinati(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Richiede WP_MULTISITE=1.' );
		}
		$this->admin();
		$this->hook_plugin();
		try {
			activate_plugin( self::HOOK_PLUGIN, '', true );
			$state = DBDM_Snapshots::capture_state();
			$this->assertContains( self::HOOK_PLUGIN, $state['network_plugins'] );

			deactivate_plugins( self::HOOK_PLUGIN, true, true );
			$diff = DBDM_Snapshots::diff( $state, DBDM_Snapshots::capture_state() );
			$this->assertSame( array( self::HOOK_PLUGIN ), $diff['network_deactivated'] );
			$this->assertFalse( DBDM_Snapshots::diff_is_empty( $diff ) );

			$messages = DBDM_Snapshots::restore( $this->snapshot_with( $state ), array( 'plugins' ) );
			$this->assertTrue( is_plugin_active_for_network( self::HOOK_PLUGIN ) );
			$this->assertContains( array( 'ok', 'Plugin attivi in rete ripristinati: 1.' ), $messages );
		} finally {
			deactivate_plugins( self::HOOK_PLUGIN, true, true );
			$this->remove_hook_plugin();
		}
	}

	/* --- Monitor query (bug 49) -------------------------------------------- */

	private function run_capture() {
		delete_transient( 'dbdm_last_queries' );
		$GLOBALS['wpdb']->queries = array( array( "SELECT * FROM wp_users WHERE user_email = 'visitatore@example.com'", 0.001, 'test' ) );
		DBDM_Queries::instance()->capture_snapshot();
		return get_transient( 'dbdm_last_queries' );
	}

	public function test_le_visite_degli_altri_non_vengono_registrate(): void {
		$admin = $this->admin();
		DBDM_Queries::start( $admin );

		wp_set_current_user( 0 );
		$this->assertFalse( $this->run_capture(), 'visitatore anonimo' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->run_capture(), 'utente non amministratore' );
	}

	public function test_solo_l_amministratore_che_l_ha_attivato(): void {
		$admin = $this->admin();
		$this->assertFalse( $this->run_capture(), 'monitor spento' );

		DBDM_Queries::start( $admin );
		$this->assertGreaterThan( 1700, DBDM_Queries::remaining( $admin ) );
		$snapshot = $this->run_capture();
		$this->assertIsArray( $snapshot );
		$this->assertSame( 1, $snapshot['num'] );

		$other = $this->admin();
		$this->assertFalse( $this->run_capture(), 'un altro amministratore senza monitor' );

		wp_set_current_user( $admin );
		DBDM_Queries::stop( $admin );
		$this->assertFalse( $this->run_capture(), 'monitor fermato' );
	}

	public function test_monitor_scaduto(): void {
		$admin = $this->admin();
		update_user_meta( $admin, DBDM_Queries::META_UNTIL, time() - 1 );
		$this->assertSame( 0, DBDM_Queries::remaining( $admin ) );
		$this->assertFalse( $this->run_capture() );
	}

	public function test_mai_in_bacheca_e_al_login(): void {
		DBDM_Queries::start( $this->admin() );

		set_current_screen( 'dashboard' );
		$this->assertFalse( $this->run_capture(), 'bacheca' );
		unset( $GLOBALS['current_screen'] );

		$pagenow            = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->assertFalse( $this->run_capture(), 'login' );
		$GLOBALS['pagenow'] = $pagenow;

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( $this->run_capture(), 'AJAX' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/* --- Token della cartella privata (bug 55) ---------------------------- */

	public function test_token_creato_da_un_altra_richiesta_nel_frattempo(): void {
		global $wpdb;
		delete_option( DBDM_Emergency::OPTION_DIR_TOKEN );
		// Un'altra richiesta scrive il token dopo che questa l'ha letto vuoto.
		$wpdb->insert( $wpdb->options, array( 'option_name' => DBDM_Emergency::OPTION_DIR_TOKEN, 'option_value' => 'abcdef0123456789', 'autoload' => 'no' ) );
		$stale = function () {
			return '';
		};
		add_filter( 'pre_option_' . DBDM_Emergency::OPTION_DIR_TOKEN, $stale );
		// add_option() controlla se l'opzione esiste: da lì in poi la lettura
		// non è più quella vecchia.
		add_filter(
			'default_option_' . DBDM_Emergency::OPTION_DIR_TOKEN,
			function ( $default ) use ( $stale ) {
				remove_filter( 'pre_option_' . DBDM_Emergency::OPTION_DIR_TOKEN, $stale );
				return $default;
			}
		);

		$dir = DBDM_Emergency::private_dir();

		$this->assertSame( WP_CONTENT_DIR . '/dbdm-private-abcdef0123456789/', $dir, 'vale il token già scritto' );
		$this->assertSame( 'abcdef0123456789', get_option( DBDM_Emergency::OPTION_DIR_TOKEN ) );
		DBDM_Uninstall::run();
	}

	/* --- Disinstallazione (bug 51) ----------------------------------------- */

	public function test_la_disinstallazione_rimuove_dati_e_cartella_privata(): void {
		$admin = $this->admin();
		$dir   = DBDM_Emergency::private_dir();
		DBDM_Emergency::set_password( 'Password-Lunga-123' );
		DBDM_Snapshots::create( DBDM_Snapshots::TRIGGER_MANUAL, 'prima di disinstallare' );
		set_transient( 'dbdm_last_queries', array( 'num' => 1 ), HOUR_IN_SECONDS );
		set_transient( 'dbgu_' . md5( DBDM_Uninstall::BASENAME ), array( 'version' => '9' ), HOUR_IN_SECONDS );
		DBDM_Queries::start( $admin );
		update_option( 'dbdmaltro', 'resta' );
		update_option( 'altro_plugin', 'resta' );
		$this->assertFileExists( $dir . 'snapshots.json' );

		DBDM_Uninstall::run();

		foreach ( array( DBDM_Emergency::OPTION_HASH, DBDM_Emergency::OPTION_DIR_TOKEN, DBDM_Emergency::OPTION_DIR_PATH, DBDM_Emergency::OPTION_SITE_PATHS ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertFalse( get_transient( 'dbdm_last_queries' ) );
		$this->assertFalse( get_transient( 'dbgu_' . md5( DBDM_Uninstall::BASENAME ) ) );
		$this->assertSame( '', get_user_meta( $admin, DBDM_Queries::META_UNTIL, true ) );
		$this->assertDirectoryDoesNotExist( rtrim( $dir, '/' ) );
		$this->assertSame( 'resta', get_option( 'dbdmaltro' ), 'solo le opzioni dbdm_' );
		$this->assertSame( 'resta', get_option( 'altro_plugin' ) );
	}

	public function test_costanti_che_restano_dopo_la_disinstallazione(): void {
		$config = get_temp_dir() . 'dbdm-wp-config-' . uniqid() . '.php';
		file_put_contents( $config, "<?php\ndefine( 'WP_DEBUG', true );\n/* define( 'SAVEQUERIES', true ); */\ndefine( 'DB_NAME', 'x' );\ndefined( 'SCRIPT_DEBUG' ) || define( 'SCRIPT_DEBUG', false );\n" );

		$this->assertSame( array( 'WP_DEBUG', 'SCRIPT_DEBUG' ), DBDM_Uninstall::leftover_constants( $config ) );
		$this->assertSame( array(), DBDM_Uninstall::leftover_constants( false ) );
		unlink( $config );
	}

	/**
	 * @group ms-required
	 */
	public function test_in_multisite_la_disinstallazione_pulisce_ogni_sito(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Richiede WP_MULTISITE=1.' );
		}
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		set_transient( 'dbdm_last_queries', array( 'num' => 1 ), HOUR_IN_SECONDS );
		restore_current_blog();

		DBDM_Uninstall::run();

		switch_to_blog( $blog );
		$this->assertFalse( get_transient( 'dbdm_last_queries' ) );
		restore_current_blog();
	}
}
