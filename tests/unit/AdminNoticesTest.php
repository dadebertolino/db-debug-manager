<?php
/**
 * Avvisi del pannello (bug 48, 54): decisi dagli handler in base all'esito,
 * conservati per l'utente, mai presi dalla query string.
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class AdminNoticesTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbdm_test_reset();
		$GLOBALS['__dbdm_user_id'] = 7;
	}

	public function test_avvisi_per_utente_letti_una_volta(): void {
		DBDM_Admin::flash( 'success', 'Fatto.' );
		DBDM_Admin::flash( 'error', 'Non riuscito.' );

		$GLOBALS['__dbdm_user_id'] = 8;
		$this->assertSame( array(), DBDM_Admin::take_notices(), 'un altro utente non li vede' );

		$GLOBALS['__dbdm_user_id'] = 7;
		$this->assertSame( array( array( 'success', 'Fatto.' ), array( 'error', 'Non riuscito.' ) ), DBDM_Admin::take_notices() );
		$this->assertSame( array(), DBDM_Admin::take_notices(), 'mostrati una sola volta' );
	}

	public function test_tipo_sconosciuto_trattato_come_errore(): void {
		DBDM_Admin::flash( '<script>', 'x' );
		$this->assertSame( array( array( 'error', 'x' ) ), DBDM_Admin::take_notices() );
	}

	/**
	 * Bug 54: la pagina mostrava il testo di ?err= (contenuto arbitrario via
	 * link); ora nessun template legge messaggi dalla query string.
	 */
	public function test_nessun_messaggio_dalla_query_string(): void {
		foreach ( glob( DBDM_TEST_ROOT . '/templates/*.php' ) as $template ) {
			$this->assertDoesNotMatchRegularExpression( '/\$_GET\[\s*[\'"](err|snap_err)[\'"]\s*\]/', file_get_contents( $template ), basename( $template ) );
		}
	}
}
