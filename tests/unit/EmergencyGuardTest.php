<?php
/**
 * DBDM_Emergency_Guard: IP e proxy (bug 16), limite dei tentativi (bug 11,
 * 29), impronta della sessione (bug 13), unserialize sicuro (bug 32),
 * validazione degli snapshot da ripristinare (bug 17).
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class EmergencyGuardTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/dbdm-guard-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tear_down() {
		$rm = function ( $dir ) use ( &$rm ) {
			chmod( $dir, 0755 );
			foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
				is_dir( "$dir/$item" ) ? $rm( "$dir/$item" ) : unlink( "$dir/$item" );
			}
			rmdir( $dir );
		};
		$rm( $this->dir );
		parent::tear_down();
	}

	/* --- IP (bug 16) -------------------------------------------------------- */

	public function test_senza_proxy_vale_solo_remote_addr(): void {
		$server = array(
			'REMOTE_ADDR'           => '203.0.113.5',
			'HTTP_X_FORWARDED_FOR'  => '1.1.1.1',
			'HTTP_CF_CONNECTING_IP' => '2.2.2.2',
		);
		$this->assertSame( '203.0.113.5', DBDM_Emergency_Guard::client_ip( $server, false ) );
	}

	public function test_con_proxy_vale_l_ultimo_hop_e_mai_cf_connecting_ip(): void {
		$server = array(
			'REMOTE_ADDR'           => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR'  => '6.6.6.6, 198.51.100.7',
			'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
		);
		$this->assertSame( '198.51.100.7', DBDM_Emergency_Guard::client_ip( $server, true ) );

		unset( $server['HTTP_X_FORWARDED_FOR'] );
		$this->assertSame( '10.0.0.1', DBDM_Emergency_Guard::client_ip( $server, true ) );
	}

	public function test_valori_non_validi(): void {
		$this->assertSame( '10.0.0.1', DBDM_Emergency_Guard::client_ip( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'non-ip' ), true ) );
		$this->assertSame( '0.0.0.0', DBDM_Emergency_Guard::client_ip( array( 'REMOTE_ADDR' => array( 'x' ) ), false ) );
	}

	public function test_ipv6_raggruppato_per_64(): void {
		$this->assertSame( '2001:db8:1:2::/64', DBDM_Emergency_Guard::rate_key( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) );
		$this->assertSame( DBDM_Emergency_Guard::rate_key( '2001:db8:1:2::1' ), DBDM_Emergency_Guard::rate_key( '2001:db8:1:2:ffff::9' ) );
		$this->assertSame( '203.0.113.5', DBDM_Emergency_Guard::rate_key( '203.0.113.5' ) );
	}

	/* --- Limite dei tentativi (bug 11, 29) --------------------------------- */

	public function test_cinque_tentativi_poi_blocco_di_15_minuti_dall_ultimo(): void {
		$file = $this->dir . '/rl.json';
		$t    = 1000000;

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( DBDM_Emergency_Guard::reserve_attempt( $file, 'ip', $t + $i * 60 )['allowed'], "tentativo $i" );
		}
		$blocked = DBDM_Emergency_Guard::reserve_attempt( $file, 'ip', $t + 300 );
		$this->assertFalse( $blocked['allowed'] );
		$this->assertSame( 840, $blocked['retry_after'] );

		// Il blocco conta dall'ULTIMO tentativo (t+240), non dal primo.
		$this->assertSame( 60, DBDM_Emergency_Guard::locked_for( $file, 'ip', $t + 240 + 840 ) );
		$this->assertFalse( DBDM_Emergency_Guard::reserve_attempt( $file, 'ip', $t + 240 + 899 )['allowed'] );
		$this->assertTrue( DBDM_Emergency_Guard::reserve_attempt( $file, 'ip', $t + 240 + 901 )['allowed'] );
	}

	public function test_chiavi_indipendenti_e_reset(): void {
		$file = $this->dir . '/rl.json';
		for ( $i = 0; $i < 5; $i++ ) {
			DBDM_Emergency_Guard::reserve_attempt( $file, 'a', 100 );
		}
		$this->assertFalse( DBDM_Emergency_Guard::reserve_attempt( $file, 'a', 101 )['allowed'] );
		$this->assertTrue( DBDM_Emergency_Guard::reserve_attempt( $file, 'b', 101 )['allowed'] );

		DBDM_Emergency_Guard::reset( $file, 'a' );
		$this->assertSame( 0, DBDM_Emergency_Guard::locked_for( $file, 'a', 102 ) );
	}

	public function test_file_non_utilizzabile_nega_l_accesso(): void {
		chmod( $this->dir, 0555 );

		$r = DBDM_Emergency_Guard::reserve_attempt( $this->dir . '/rl.json', 'ip' );

		$this->assertFalse( $r['allowed'] );
		$this->assertSame( 'storage', $r['error'] );
	}

	public function test_file_corrotto_ricomincia_da_zero(): void {
		file_put_contents( $this->dir . '/rl.json', '{non json' );
		$this->assertTrue( DBDM_Emergency_Guard::reserve_attempt( $this->dir . '/rl.json', 'ip', 10 )['allowed'] );
	}

	/* --- Sessione (bug 13) e unserialize (bug 32) ---------------------------- */

	public function test_impronta_cambia_con_password_ed_epoca(): void {
		$base = DBDM_Emergency_Guard::session_fingerprint( 'hash', 'e1' );

		$this->assertSame( $base, DBDM_Emergency_Guard::session_fingerprint( 'hash', 'e1' ) );
		$this->assertNotSame( $base, DBDM_Emergency_Guard::session_fingerprint( 'hash2', 'e1' ) );
		$this->assertNotSame( $base, DBDM_Emergency_Guard::session_fingerprint( 'hash', 'e2' ) );
	}

	public function test_unserialize_senza_oggetti(): void {
		$this->assertSame( array( 'a/a.php' ), DBDM_Emergency_Guard::maybe_unserialize( serialize( array( 'a/a.php' ) ) ) );
		$this->assertFalse( DBDM_Emergency_Guard::maybe_unserialize( 'b:0;' ) );
		$this->assertNull( DBDM_Emergency_Guard::maybe_unserialize( 'N;' ) );
		$this->assertSame( 'testo', DBDM_Emergency_Guard::maybe_unserialize( 'testo' ) );

		// Un oggetto serializzato (anche dentro un array) non viene istanziato.
		$evil = DBDM_Emergency_Guard::maybe_unserialize( 'O:8:"stdClass":0:{}' );
		$this->assertSame( 'O:8:"stdClass":0:{}', $evil );
		$nested = DBDM_Emergency_Guard::maybe_unserialize( 'a:1:{i:0;O:8:"stdClass":0:{}}' );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $nested[0] );
	}

	/* --- Snapshot (bug 17) ----------------------------------------------------- */

	private function make_plugins() {
		$dir = $this->dir . '/plugins';
		mkdir( "$dir/akismet", 0755, true );
		touch( "$dir/akismet/akismet.php" );
		touch( "$dir/hello.php" );
		return $dir;
	}

	public function test_plugin_ripristinabili(): void {
		$dir = $this->make_plugins();

		list( $valid, $skipped ) = DBDM_Emergency_Guard::restorable_plugins(
			array( 'akismet/akismet.php', 'hello.php', 'akismet', '../../wp-config.php', 'rimosso/rimosso.php', array( 'x' ), 'hello.php' ),
			$dir
		);

		$this->assertSame( array( 'akismet/akismet.php', 'hello.php' ), $valid );
		$this->assertSame( array( 'akismet', '../../wp-config.php', 'rimosso/rimosso.php', 'array' ), $skipped );
		$this->assertSame( array( array(), array() ), DBDM_Emergency_Guard::restorable_plugins( 'non array', $dir ) );
	}

	private function make_themes() {
		$dir = $this->dir . '/themes';
		mkdir( "$dir/padre", 0755, true );
		file_put_contents( "$dir/padre/style.css", "/*\nTheme Name: Padre\n*/" );
		mkdir( "$dir/figlio" );
		file_put_contents( "$dir/figlio/style.css", "/*\nTheme Name: Figlio\nTemplate: padre\n*/" );
		mkdir( "$dir/orfano" );
		file_put_contents( "$dir/orfano/style.css", "/*\nTheme Name: Orfano\n * Template: cancellato\n*/" );
		mkdir( "$dir/vuoto" );
		return $dir;
	}

	public function test_tema_ripristinabile_con_padre_dal_tema(): void {
		$dir = $this->make_themes();

		$this->assertSame(
			array( 'ok' => true, 'stylesheet' => 'figlio', 'template' => 'padre', 'error' => '' ),
			DBDM_Emergency_Guard::restorable_theme( 'figlio', $dir )
		);
		$this->assertSame( 'padre', DBDM_Emergency_Guard::restorable_theme( 'padre', $dir )['template'] );
	}

	/**
	 * @dataProvider provide_temi_non_validi
	 */
	public function test_temi_non_ripristinabili( $slug, $error ): void {
		$r = DBDM_Emergency_Guard::restorable_theme( $slug, $this->make_themes() );

		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( $error, $r['error'] );
	}

	public function provide_temi_non_validi() {
		return array(
			'padre mancante' => array( 'orfano', 'tema padre non installato: cancellato' ),
			'senza style'    => array( 'vuoto', 'non installato' ),
			'inesistente'    => array( 'mai-esistito', 'non installato' ),
			'risalita'       => array( '../plugins', 'slug non valido' ),
			'non stringa'    => array( array( 'x' ), 'slug non valido' ),
		);
	}
}
