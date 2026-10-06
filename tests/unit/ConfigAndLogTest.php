<?php
/**
 * Costanti e log: stato mostrato nel pannello (bug 6, 7), dettagli della
 * scrittura di wp-config.php (bug 9).
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ConfigAndLogTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbdm_test_reset();
	}

	/**
	 * Bug 6: `define( 'WP_DEBUG', 1 )` risultava spenta (solo === true era
	 * "attiva") e salvando un'altra costante veniva scritta false.
	 * Bug 7: WP_DEBUG_LOG '1' / 'true' era mostrata come "file custom".
	 *
	 * @dataProvider stati
	 */
	public function test_stato_di_una_costante( $const, $defined, $value, $on, $custom_path ): void {
		$this->assertSame(
			array( 'on' => $on, 'custom_path' => $custom_path ),
			DBDM_Config::constant_state( $const, array( 'defined' => $defined, 'value' => $value ) )
		);
	}

	public function stati() {
		return array(
			'non definita'            => array( 'WP_DEBUG', false, null, false, '' ),
			'true'                    => array( 'WP_DEBUG', true, true, true, '' ),
			'1'                       => array( 'WP_DEBUG', true, 1, true, '' ),
			'stringa 1'               => array( 'SCRIPT_DEBUG', true, '1', true, '' ),
			'false'                   => array( 'WP_DEBUG', true, false, false, '' ),
			'0'                       => array( 'SAVEQUERIES', true, 0, false, '' ),
			'stringa vuota'           => array( 'WP_DEBUG_DISPLAY', true, '', false, '' ),
			'log true'                => array( 'WP_DEBUG_LOG', true, true, true, '' ),
			'log 1'                   => array( 'WP_DEBUG_LOG', true, 1, true, '' ),
			'log stringa 1'           => array( 'WP_DEBUG_LOG', true, '1', true, '' ),
			'log stringa true'        => array( 'WP_DEBUG_LOG', true, 'TRUE', true, '' ),
			'log stringa 0'           => array( 'WP_DEBUG_LOG', true, '0', false, '' ),
			'log percorso'            => array( 'WP_DEBUG_LOG', true, '/srv/log/wp.log', true, '/srv/log/wp.log' ),
			'percorso su altra costante' => array( 'WP_DEBUG', true, '/srv/x', true, '' ),
		);
	}

	/* --- Bug 9: dettagli della scrittura ------------------------------------ */

	public function test_crlf_conservato_in_modifica_e_inserimento(): void {
		$crlf = "<?php\r\ndefine( 'DB_NAME', 'wp' );\r\ndefine( 'WP_DEBUG', false );\r\n\$table_prefix = 'wp_';\r\n/* That's all, stop editing! Happy publishing. */\r\nrequire_once ABSPATH . 'wp-settings.php';\r\n";

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $crlf, 'WP_DEBUG', 'true' );
		$out = DBDM_Standalone_Config::replace_or_insert_constant( $out, 'SCRIPT_DEBUG', 'true' );

		$this->assertSame( 0, preg_match( "/(?<!\r)\n/", $out ), 'nessun a capo senza \\r' );
		$this->assertStringContainsString( "define( 'WP_DEBUG', true );\r\n", $out );
		$this->assertStringContainsString( "define( 'SCRIPT_DEBUG', true );\r\n", $out );
		$this->assertTrue( DBDM_Standalone_Config::php_lint_string( $out ) );
	}

	public function test_nome_della_costante_sensibile_alle_maiuscole(): void {
		$src = "<?php\ndefine( 'wp_debug', false );\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$out = DBDM_Standalone_Config::replace_or_insert_constant( $src, 'WP_DEBUG', 'true' );

		$this->assertStringContainsString( "define( 'wp_debug', false );", $out, 'un\'altra costante: non toccata' );
		$this->assertTrue( DBDM_Standalone_Config::effective_defines( $out )['WP_DEBUG'] );
	}

	public function test_percorso_con_apici_e_backslash_scritto_letteralmente(): void {
		$path   = "C:\\logs\\l'app\\debug.log";
		$source = dbdm_test_call_private( 'DBDM_Config', 'format_value', array( $path ) );
		$out    = DBDM_Standalone_Config::replace_or_insert_constant( "<?php\n", 'WP_DEBUG_LOG', $source );

		$this->assertTrue( DBDM_Standalone_Config::php_lint_string( $out ) );
		$this->assertSame( $path, DBDM_Standalone_Config::effective_defines( $out )['WP_DEBUG_LOG'] );
	}

	/* --- Bug 50: Update URI e DISALLOW_FILE_MODS ---------------------------- */

	public function test_update_uri_verso_il_repository_dell_updater(): void {
		$header = file_get_contents( DBDM_PLUGIN_FILE, false, null, 0, 2048 );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Update URI:\s*(\S+)\s*$/m', $header, $m ), 'senza Update URI uno slug omonimo su wordpress.org può proporre aggiornamenti' );
		$this->assertSame( 'https://github.com/dadebertolino/db-debug-manager', $m[1] );
	}

	public function test_con_disallow_file_mods_wp_config_non_si_tocca(): void {
		add_filter( 'file_mod_allowed', function ( $allowed, $context ) {
			return 'dbdm_wp_config' === $context ? false : $allowed;
		}, 10, 2 );

		$this->assertFalse( DBDM_Config::file_mods_allowed() );
		$this->assertFalse( DBDM_Config::is_writable() );
		$result = DBDM_Config::set_constants( array( 'WP_DEBUG' => true ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dbdm_file_mods_disallowed', $result->get_error_code() );
	}

	/* --- Bug 52: coda del log ----------------------------------------------- */

	private function lines( $from, $to, $eol = "\n" ) {
		$out = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$out[] = 'riga ' . $i;
		}
		return implode( $eol, $out );
	}

	public function test_tail_restituisce_esattamente_n_righe(): void {
		$log = DBDM_Log::public_path();
		file_put_contents( $log, $this->lines( 1, 30 ) );
		$this->assertSame( $this->lines( 21, 30 ), DBDM_Log::tail( 10 ), 'senza a capo finale' );

		file_put_contents( $log, $this->lines( 1, 30 ) . "\n" );
		$this->assertSame( $this->lines( 21, 30 ), DBDM_Log::tail( 10 ), 'con a capo finale' );
		unlink( $log );
	}

	public function test_tail_su_piu_blocchi_e_file_corti(): void {
		$file = WP_CONTENT_DIR . '/coda.log';
		file_put_contents( $file, $this->lines( 1, 5000 ) . "\n" );
		$this->assertSame( $this->lines( 4901, 5000 ), DBDM_Log::tail_file( $file, 100 ) );
		$this->assertSame( $this->lines( 1, 5000 ), DBDM_Log::tail_file( $file, 10000 ) );

		file_put_contents( $file, '' );
		$this->assertSame( '', DBDM_Log::tail_file( $file, 10 ) );
		$this->assertSame( '', DBDM_Log::tail_file( $file . '.assente', 10 ) );
		unlink( $file );
	}

	public function test_tail_con_riga_enorme_entro_il_limite_di_memoria(): void {
		$file = WP_CONTENT_DIR . '/enorme.log';
		file_put_contents( $file, "prima\n" . str_repeat( 'x', 3 * 1048576 ) . "\nultima\n" );

		$tail = DBDM_Log::tail_file( $file, 10, 1048576 );

		$this->assertLessThanOrEqual( 1048576 + strlen( '…' ), strlen( $tail ) );
		$this->assertStringStartsWith( '…xxx', $tail, 'riga tagliata segnalata' );
		$this->assertStringEndsWith( "x\nultima", $tail );
		$this->assertStringNotContainsString( 'prima', $tail );
		unlink( $file );
	}
}
