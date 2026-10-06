<?php
/**
 * Smoke test dell'infrastruttura di integrazione: plugin caricato e hook
 * registrati. Se questi falliscono, i risultati degli altri integration
 * test non sono attendibili.
 *
 * @package DBDM\Tests\Integration
 */

class InfraIntegrationTest extends WP_UnitTestCase {

	public function test_il_plugin_e_caricato(): void {
		$this->assertTrue( class_exists( 'DB_Debug_Manager' ) );
		$this->assertTrue( defined( 'DBDM_VERSION' ) );
	}

	public function test_hook_degli_snapshot_e_del_monitor_query(): void {
		$this->assertSame( 10, has_action( 'upgrader_process_complete', array( 'DBDM_Snapshots', 'on_upgrade_complete' ) ) );
		$this->assertSame( 999, has_action( 'shutdown', array( DBDM_Queries::instance(), 'capture_snapshot' ) ) );
	}

	public function test_le_opzioni_si_scrivono_e_si_leggono(): void {
		update_option( DBDM_Emergency::OPTION_ENABLED, '0' );
		$this->assertSame( '0', get_option( DBDM_Emergency::OPTION_ENABLED ) );
	}
}
