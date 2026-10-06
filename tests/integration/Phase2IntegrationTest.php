<?php
/**
 * Fase 2 su WordPress vero: snapshot prima degli aggiornamenti (bug 45),
 * monitor query solo per l'amministratore che lo attiva (bug 49),
 * disinstallazione (bug 51).
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
