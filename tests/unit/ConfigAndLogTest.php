<?php
/**
 * Lato WordPress di costanti e log: stato mostrato nel pannello (bug 6, 7).
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
}
