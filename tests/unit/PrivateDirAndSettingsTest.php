<?php
/**
 * Lato WordPress della Fase A: cartella privata fuori dal plugin (bug 39),
 * epoca delle sessioni emergency (bug 13, 14), costanti da scrivere e log
 * nella cartella privata (bug 8, 41), snapshot con UTF-8 non valido (bug 42).
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class PrivateDirAndSettingsTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbdm_test_reset();
		// Cartelle private nuove e vecchie (dentro il plugin) di altri test.
		foreach ( array_merge( glob( WP_CONTENT_DIR . '/dbdm-private-*' ), glob( DBDM_PLUGIN_DIR . 'private*', GLOB_ONLYDIR ) ) as $dir ) {
			$this->rrmdir( $dir );
		}
	}

	private function rrmdir( $dir ) {
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $item ) {
			is_dir( "$dir/$item" ) ? $this->rrmdir( "$dir/$item" ) : unlink( "$dir/$item" );
		}
		rmdir( $dir );
	}

	/* --- Cartella privata (bug 39) --------------------------------------- */

	public function test_cartella_privata_in_wp_content_fuori_dal_plugin(): void {
		$dir = DBDM_Emergency::private_dir();

		$this->assertMatchesRegularExpression( '#^' . preg_quote( WP_CONTENT_DIR, '#' ) . '/dbdm-private-[a-f0-9]{16}/$#', $dir );
		$this->assertStringStartsNotWith( DBDM_PLUGIN_DIR, $dir );
		$this->assertDirectoryExists( $dir );
		$this->assertSame( 0750, fileperms( $dir ) & 0777 );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $dir . '.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', file_get_contents( $dir . '.htaccess' ) );
		$this->assertFileExists( $dir . 'index.php' );
		// Percorso salvato per emergency.php, stesso token alla chiamata successiva.
		$this->assertSame( rtrim( $dir, '/' ), get_option( DBDM_Emergency::OPTION_DIR_PATH ) );
		$this->assertSame( $dir, DBDM_Emergency::private_dir() );
	}

	/**
	 * Bug 21: l'emergency ricavava wp-content, temi e plugin dalla propria
	 * posizione. WordPress salva i percorsi veri insieme alla cartella privata.
	 */
	public function test_percorsi_del_sito_salvati_per_l_emergency(): void {
		DBDM_Emergency::private_dir();

		$this->assertSame(
			array(
				'content_dir' => WP_CONTENT_DIR,
				'plugins_dir' => WP_CONTENT_DIR . '/plugins',
				'themes_dir'  => WP_CONTENT_DIR . '/temi-registrati',
			),
			get_option( DBDM_Emergency::OPTION_SITE_PATHS )
		);
	}

	public function test_svuota_log_degli_accessi_anche_la_copia_ruotata(): void {
		$log = DBDM_Emergency::log_path();
		file_put_contents( $log, "riga\n" );
		file_put_contents( $log . '.1', "vecchia\n" );

		DBDM_Emergency::clear_log();

		$this->assertFileDoesNotExist( $log );
		$this->assertFileDoesNotExist( $log . '.1' );
	}

	/**
	 * Bug 60: la regola Nginx suggerita proteggeva la vecchia cartella
	 * dentro il plugin; dalla 1.4.0 i file stanno in wp-content/dbdm-private-*.
	 */
	public function test_regola_nginx_sulla_cartella_privata(): void {
		$this->assertSame( 'location ^~ /wp-content/dbdm-private- { deny all; }', DBDM_Emergency::nginx_rule() );

		add_filter( 'content_url', function () {
			return 'https://debug.example/app/contenuti';
		} );
		$this->assertSame( 'location ^~ /app/contenuti/dbdm-private- { deny all; }', DBDM_Emergency::nginx_rule() );
	}

	public function test_migrazione_dalla_cartella_del_plugin(): void {
		$token = 'abcdef0123456789';
		update_option( DBDM_Emergency::OPTION_DIR_TOKEN, $token );
		$old = DBDM_PLUGIN_DIR . 'private-' . $token;
		mkdir( $old );
		file_put_contents( "$old/snapshots.json", '[]' );
		file_put_contents( "$old/wp-config.dbdm-bak", '<?php // backup' );

		$dir = DBDM_Emergency::private_dir();

		$this->assertSame( WP_CONTENT_DIR . "/dbdm-private-$token/", $dir );
		$this->assertSame( '<?php // backup', file_get_contents( $dir . 'wp-config.dbdm-bak' ) );
		$this->assertFileExists( $dir . 'snapshots.json' );
		$this->assertDirectoryDoesNotExist( $old );
	}

	public function test_migrazione_dalla_vecchia_private(): void {
		$old = DBDM_PLUGIN_DIR . 'private';
		mkdir( $old );
		file_put_contents( "$old/emergency-access.log", "riga\n" );

		$dir = DBDM_Emergency::private_dir();

		$this->assertSame( "riga\n", file_get_contents( $dir . 'emergency-access.log' ) );
		$this->assertDirectoryDoesNotExist( $old );
	}

	/* --- Epoca delle sessioni (bug 13, 14) ------------------------------- */

	public function test_l_epoca_cambia_con_password_abilitazione_e_disattivazione(): void {
		$epochs = array();
		$read   = function () {
			return get_option( DBDM_Emergency::OPTION_EPOCH );
		};

		DBDM_Emergency::set_password( 'Password-Lunga-123' );
		$epochs[] = $read();
		DBDM_Emergency::set_enabled( true );
		$epochs[] = $read();
		DBDM_Emergency::set_enabled( true ); // Nessun cambio: stessa epoca.
		$this->assertSame( end( $epochs ), $read() );
		DBDM_Emergency::on_plugin_deactivate();
		$epochs[] = $read();

		$this->assertFalse( DBDM_Emergency::is_enabled() );
		$this->assertCount( 3, array_unique( array_filter( $epochs ) ) );
	}

	/* --- Costanti da scrivere (bug 8, 41) ---------------------------------- */

	public function test_solo_le_costanti_che_cambiano(): void {
		// Nel processo dei test WP_DEBUG è true (bootstrap), le altre non definite.
		$this->assertSame( array( 'WP_DEBUG' => false ), DBDM_Admin::constants_to_write( array() ) );
		$this->assertSame( array( 'SAVEQUERIES' => true ), DBDM_Admin::constants_to_write( array( 'WP_DEBUG' => '1', 'SAVEQUERIES' => '1' ) ) );
	}

	public function test_il_log_attivato_va_nella_cartella_privata(): void {
		$out = DBDM_Admin::constants_to_write( array( 'WP_DEBUG_LOG' => '1' ) );

		$this->assertSame( DBDM_Emergency::private_dir() . 'debug.log', $out['WP_DEBUG_LOG'] );
		$this->assertStringStartsNotWith( WP_CONTENT_DIR . '/debug.log', $out['WP_DEBUG_LOG'] );
	}

	public function test_il_log_riusa_il_percorso_personalizzato_ricordato(): void {
		update_option( 'dbdm_debug_log_path', '/var/log/wp/sito.log' );
		$this->assertSame( '/var/log/wp/sito.log', DBDM_Admin::constants_to_write( array( 'WP_DEBUG_LOG' => '1' ) )['WP_DEBUG_LOG'] );

		// Un percorso ricordato che è quello pubblico non vale.
		update_option( 'dbdm_debug_log_path', 'true' );
		$this->assertSame( DBDM_Emergency::private_dir() . 'debug.log', DBDM_Admin::constants_to_write( array( 'WP_DEBUG_LOG' => '1' ) )['WP_DEBUG_LOG'] );
	}

	/**
	 * @dataProvider provide_valori_log
	 */
	public function test_resolve_path_come_wp_debug_mode( $value, $public ): void {
		$path = DBDM_Log::resolve_path( $value );
		$public ? $this->assertSame( WP_CONTENT_DIR . '/debug.log', $path ) : $this->assertSame( $value, $path );
	}

	public function provide_valori_log() {
		return array(
			'true'          => array( true, true ),
			'stringa 1'     => array( '1', true ),
			'stringa true'  => array( 'TRUE', true ),
			'vuota'         => array( '', true ),
			'percorso'      => array( '/var/log/wp.log', false ),
		);
	}

	public function test_capability_e_url_in_multisite(): void {
		$this->assertSame( 'manage_options', DBDM_Admin::cap() );
		$GLOBALS['__dbdm_multisite'] = true;
		$this->assertSame( 'manage_network_options', DBDM_Admin::cap() );
	}

	/* --- Snapshot con UTF-8 non valido (bug 42) ---------------------------- */

	public function test_utf8_non_valido_non_cancella_gli_snapshot(): void {
		$write = function ( $data ) {
			return dbdm_test_call_private( 'DBDM_Snapshots', 'write_all', array( $data ) );
		};
		$this->assertTrue( $write( array( array( 'id' => 'snap_1', 'note' => 'primo' ) ) ) );

		$this->assertTrue( $write( array( array( 'id' => 'snap_1', 'note' => 'primo' ), array( 'id' => 'snap_2', 'note' => "Caf\xe9" ) ) ) );

		$all = DBDM_Snapshots::get_all();
		$this->assertSame( array( 'snap_1', 'snap_2' ), array_column( $all, 'id' ) );
		$this->assertSame( "Caf\u{FFFD}", $all[1]['note'] );
		$this->assertSame( 0640, fileperms( DBDM_Snapshots::storage_path() ) & 0777 );
	}
}
