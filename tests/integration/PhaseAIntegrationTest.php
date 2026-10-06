<?php
/**
 * Fase A su WordPress vero: capability in multisite (bug 40), disattivazione
 * (bug 14), cartella privata (bug 39), ripristino del tema e autoload
 * (bug 17, 46).
 *
 * @package DBDM\Tests\Integration
 */

class PhaseAIntegrationTest extends WP_UnitTestCase {

	/** @var string[] Cartelle create dal test, da rimuovere. */
	private $cleanup = array();

	public function tear_down() {
		foreach ( $this->cleanup as $dir ) {
			$this->rrmdir( $dir );
		}
		parent::tear_down();
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			is_dir( "$dir/$item" ) ? $this->rrmdir( "$dir/$item" ) : unlink( "$dir/$item" );
		}
		rmdir( $dir );
	}

	/* --- Capability (bug 40) --------------------------------------------- */

	public function test_capability_single_site(): void {
		$this->assertSame( is_multisite() ? 'manage_network_options' : 'manage_options', DBDM_Admin::cap() );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		if ( ! is_multisite() ) {
			$this->assertTrue( current_user_can( DBDM_Admin::cap() ) );
		}
	}

	/**
	 * @group ms-required
	 */
	public function test_in_multisite_l_admin_di_un_sito_non_accede(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Richiede WP_MULTISITE=1.' );
		}
		$blog  = self::factory()->blog->create();
		$admin = self::factory()->user->create();
		add_user_to_blog( $blog, $admin, 'administrator' );
		switch_to_blog( $blog );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( DBDM_Admin::cap() ) );

		$this->expectException( 'WPDieException' );
		DBDM_Admin::instance()->handle_save_constants();
	}

	/**
	 * @group ms-required
	 */
	public function test_in_multisite_il_pannello_e_nella_bacheca_di_rete(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Richiede WP_MULTISITE=1.' );
		}
		// Singleton nuovo: gli hook di un'istanza precedente sono stati
		// rimossi dal ripristino degli hook tra un test e l'altro.
		$ref = new ReflectionProperty( 'DBDM_Admin', 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		DBDM_Admin::instance();
		$this->assertNotFalse( has_action( 'network_admin_menu', array( DBDM_Admin::instance(), 'register_menu' ) ) );
		$this->assertFalse( has_action( 'admin_menu', array( DBDM_Admin::instance(), 'register_menu' ) ) );
		$this->assertStringContainsString( '/wp-admin/network/settings.php?page=db-debug-manager', DBDM_Admin::page_url() );
	}

	/* --- Disattivazione (bug 14) ----------------------------------------- */

	public function test_la_disattivazione_spegne_l_emergency(): void {
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( DBDM_PLUGIN_FILE ), array( 'DBDM_Emergency', 'on_plugin_deactivate' ) ) );

		DBDM_Emergency::set_password( 'Password-Lunga-123' );
		DBDM_Emergency::set_enabled( true );
		$epoch = get_option( DBDM_Emergency::OPTION_EPOCH );

		do_action( 'deactivate_' . plugin_basename( DBDM_PLUGIN_FILE ), false );

		$this->assertFalse( DBDM_Emergency::is_enabled() );
		$this->assertNotSame( $epoch, get_option( DBDM_Emergency::OPTION_EPOCH ) );
	}

	/* --- Cartella privata (bug 39) --------------------------------------- */

	public function test_cartella_privata_fuori_dalla_cartella_dei_plugin(): void {
		$dir = DBDM_Emergency::private_dir();
		$this->cleanup[] = $dir;

		$this->assertStringStartsWith( WP_CONTENT_DIR . '/dbdm-private-', $dir );
		$this->assertStringStartsNotWith( WP_PLUGIN_DIR, $dir );
		$this->assertTrue( DBDM_Emergency::private_dir_writable() );
	}

	/* --- Ripristino snapshot (bug 17, 46) ---------------------------------- */

	private function snapshot( array $state ) {
		$all   = DBDM_Snapshots::get_all();
		$all[] = array_merge(
			array(
				'id'             => 'snap_test_' . count( $all ),
				'trigger'        => 'manual',
				'timestamp'      => time(),
				'active_plugins' => array(),
			),
			$state
		);
		dbdm_integration_write_snapshots( $all );
		return end( $all )['id'];
	}

	public function test_tema_figlio_senza_padre_non_viene_ripristinato(): void {
		$this->cleanup[] = DBDM_Emergency::private_dir();
		$root            = get_theme_root();
		$this->cleanup[] = "$root/dbdm-orfano";
		mkdir( "$root/dbdm-orfano" );
		file_put_contents( "$root/dbdm-orfano/style.css", "/*\nTheme Name: Orfano\nTemplate: dbdm-padre-cancellato\n*/" );
		search_theme_directories( true );
		$before = get_option( 'stylesheet' );

		$messages = DBDM_Snapshots::restore( $this->snapshot( array( 'stylesheet' => 'dbdm-orfano', 'template' => 'dbdm-orfano' ) ), array( 'theme' ) );

		$this->assertSame( 'err', $messages[0][0] );
		$this->assertStringContainsString( 'dbdm-padre-cancellato', $messages[0][1] );
		$this->assertSame( $before, get_option( 'stylesheet' ) );
	}

	public function test_ripristino_con_switch_theme_e_autoload_invariato(): void {
		$this->cleanup[] = DBDM_Emergency::private_dir();
		$themes = array_keys( wp_get_themes() );
		$target = current( array_diff( $themes, array( get_option( 'stylesheet' ) ) ) );
		if ( ! $target ) {
			$this->markTestSkipped( 'Serve un secondo tema installato.' );
		}
		$id = $this->snapshot( array( 'stylesheet' => $target, 'template' => 'manomesso-nello-snapshot' ) );

		$messages = DBDM_Snapshots::restore( $id, array( 'plugins', 'theme' ) );

		$this->assertSame( 'ok', end( $messages )[0] );
		$this->assertSame( $target, get_option( 'stylesheet' ) );
		// Il padre viene dal tema, non dallo snapshot.
		$this->assertSame( wp_get_theme( $target )->get_template(), get_option( 'template' ) );
		wp_cache_delete( 'alloptions', 'options' );
		$alloptions = wp_load_alloptions();
		foreach ( array( 'active_plugins', 'stylesheet', 'template' ) as $option ) {
			$this->assertArrayHasKey( $option, $alloptions, $option );
		}
	}
}

/**
 * Scrive snapshots.json come fa il plugin.
 */
function dbdm_integration_write_snapshots( $data ) {
	file_put_contents( DBDM_Snapshots::storage_path(), wp_json_encode( array_values( $data ) ) );
}
