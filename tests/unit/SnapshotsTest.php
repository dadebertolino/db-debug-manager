<?php
/**
 * Snapshot: posti per tipo e deduplica (bug 45).
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class SnapshotsTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbdm_test_reset();
	}

	private function snap( $id, $trigger ) {
		return array( 'id' => $id, 'trigger' => $trigger );
	}

	/**
	 * Bug 45: gli snapshot automatici (uno per aggiornamento) espellevano i
	 * manuali dai 5 posti comuni.
	 */
	public function test_gli_automatici_non_espellono_i_manuali(): void {
		$all = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$all[] = $this->snap( "m$i", DBDM_Snapshots::TRIGGER_MANUAL );
		}
		for ( $i = 1; $i <= 8; $i++ ) {
			$all[] = $this->snap( "a$i", 0 === $i % 2 ? DBDM_Snapshots::TRIGGER_PRE_UPGRADE : DBDM_Snapshots::TRIGGER_EMERGENCY );
		}

		$kept = array_column( dbdm_test_call_private( 'DBDM_Snapshots', 'prune', array( $all ) ), 'id' );

		$this->assertSame( array( 'm1', 'm2', 'm3', 'm4', 'm5', 'a4', 'a5', 'a6', 'a7', 'a8' ), $kept, 'ordine conservato, 5 per gruppo, i più recenti' );
	}

	public function test_i_manuali_oltre_il_limite_espellono_solo_manuali(): void {
		$all = array( $this->snap( 'a1', DBDM_Snapshots::TRIGGER_UPGRADE ) );
		for ( $i = 1; $i <= 7; $i++ ) {
			$all[] = $this->snap( "m$i", DBDM_Snapshots::TRIGGER_MANUAL );
		}

		$kept = array_column( dbdm_test_call_private( 'DBDM_Snapshots', 'prune', array( $all ) ), 'id' );

		$this->assertSame( array( 'a1', 'm3', 'm4', 'm5', 'm6', 'm7' ), $kept );
	}

	private function state( array $over = array() ) {
		return array_merge(
			array(
				'active_plugins'  => array( 'a/a.php', 'b/b.php' ),
				'stylesheet'      => 'tt',
				'template'        => 'tt',
				'plugin_versions' => array( 'a/a.php' => array( 'name' => 'A', 'version' => '1.0' ) ),
				'theme_versions'  => array( 'tt' => array( 'name' => 'TT', 'version' => '1.0' ) ),
				'wp_version'      => '6.6',
			),
			$over
		);
	}

	/**
	 * Bug 45: la deduplica ignorava temi e core.
	 */
	public function test_stato_uguale_considera_temi_e_core(): void {
		$equal = function ( $a, $b ) {
			return dbdm_test_call_private( 'DBDM_Snapshots', 'states_equal', array( $a, $b ) );
		};
		$this->assertTrue( $equal( $this->state(), $this->state( array( 'active_plugins' => array( 'b/b.php', 'a/a.php' ) ) ) ), 'ordine dei plugin irrilevante' );
		$this->assertFalse( $equal( $this->state(), $this->state( array( 'theme_versions' => array( 'tt' => array( 'name' => 'TT', 'version' => '1.1' ) ) ) ) ) );
		$this->assertFalse( $equal( $this->state(), $this->state( array( 'wp_version' => '6.7' ) ) ) );
		$this->assertFalse( $equal( $this->state(), $this->state( array( 'plugin_versions' => array( 'a/a.php' => array( 'name' => 'A', 'version' => '1.1' ) ) ) ) ) );
	}

	/**
	 * Bug 47: plugin attivi in rete nel confronto.
	 */
	public function test_diff_dei_plugin_di_rete(): void {
		$a = $this->state( array( 'network_plugins' => array( 'r/r.php' ) ) );
		$b = $this->state( array( 'network_plugins' => array( 's/s.php' ) ) );

		$diff = DBDM_Snapshots::diff( $a, $b );
		$this->assertSame( array( 's/s.php' ), $diff['network_activated'] );
		$this->assertSame( array( 'r/r.php' ), $diff['network_deactivated'] );
		$this->assertFalse( DBDM_Snapshots::diff_is_empty( $diff ) );
		$this->assertFalse( dbdm_test_call_private( 'DBDM_Snapshots', 'states_equal', array( $a, $b ) ) );
		$this->assertTrue( DBDM_Snapshots::diff_is_empty( DBDM_Snapshots::diff( $this->state(), $this->state() ) ), 'snapshot senza network_plugins (precedenti alla 2.0.0)' );
	}

	/**
	 * Bug 56: lettura-modifica-scrittura di snapshots.json sotto lock, così
	 * due richieste insieme non perdono uno snapshot.
	 */
	public function test_modifica_degli_snapshot_sotto_lock(): void {
		$lock = DBDM_Emergency::private_dir() . 'snapshots.lock';
		$held = null;

		$result = dbdm_test_call_private(
			'DBDM_Snapshots',
			'mutate',
			array(
				function ( $all ) use ( $lock, &$held ) {
					$fp   = fopen( $lock, 'c' );
					$held = ! flock( $fp, LOCK_EX | LOCK_NB );
					fclose( $fp );
					$all[] = array( 'id' => 'x', 'trigger' => 'manual' );
					return array( $all, 'fatto' );
				},
			)
		);

		$this->assertTrue( $held, 'lock tenuto durante la modifica' );
		$this->assertSame( 'fatto', $result );
		$this->assertSame( array( 'x' ), array_column( DBDM_Snapshots::get_all(), 'id' ) );

		$fp = fopen( $lock, 'c' );
		$this->assertTrue( flock( $fp, LOCK_EX | LOCK_NB ), 'lock rilasciato' );
		fclose( $fp );
	}
}
