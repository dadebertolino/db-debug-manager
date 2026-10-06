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
}
