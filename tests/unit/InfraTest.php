<?php
/**
 * Smoke test dell'infrastruttura unit: se questi falliscono, i risultati
 * degli altri test non sono attendibili (stub WordPress, caricamento classi
 * o corpus di wp-config rotti).
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class InfraTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbdm_test_reset();
	}

	/**
	 * Contenuto di un wp-config.php del corpus.
	 */
	private function corpus( $name ) {
		return file_get_contents( DBDM_TEST_ROOT . '/tests/fixtures/wp-config/' . $name . '.php' );
	}

	/**
	 * True se il codice PHP è sintatticamente valido (senza eseguirlo né
	 * chiamare il binario php).
	 */
	private function parses( $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
			return true;
		} catch ( ParseError $e ) {
			return false;
		}
	}

	public function test_le_classi_del_plugin_sono_caricate(): void {
		foreach ( array( 'DBDM_Admin', 'DBDM_Config', 'DBDM_Emergency', 'DBDM_Log', 'DBDM_Queries', 'DBDM_Snapshots', 'DBDM_Standalone_Config', 'DB_GitHub_Updater' ) as $class ) {
			$this->assertTrue( class_exists( $class ), $class );
		}
	}

	public function test_i_filtri_rispettano_la_priorita(): void {
		add_filter( 'dbdm_test', function ( $v ) {
			return $v . 'b';
		}, 20 );
		add_filter( 'dbdm_test', function ( $v ) {
			return $v . 'a';
		}, 5 );

		$this->assertSame( 'ab', apply_filters( 'dbdm_test', '' ) );
	}

	public function test_il_corpus_di_wp_config_e_php_valido(): void {
		foreach ( glob( DBDM_TEST_ROOT . '/tests/fixtures/wp-config/*.php' ) as $file ) {
			$this->assertTrue( $this->parses( file_get_contents( $file ) ), basename( $file ) );
		}
	}

	public function test_parser_e_writer_sul_wp_config_standard(): void {
		$dir = sys_get_temp_dir() . '/dbdm-infra-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/wp-config.php', $this->corpus( 'standard' ) );

		$creds = DBDM_Standalone_Config::parse_credentials( $dir . '/wp-config.php' );
		$this->assertSame( 'wp_user', $creds['user'] );
		$this->assertSame( 'wp_', $creds['prefix'] );

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $this->corpus( 'standard' ), 'WP_DEBUG', 'true' );
		$this->assertTrue( $this->parses( $out ) );
		$this->assertStringContainsString( "define('WP_DEBUG', true);", $out );

		unlink( $dir . '/wp-config.php' );
		rmdir( $dir );
	}
}
