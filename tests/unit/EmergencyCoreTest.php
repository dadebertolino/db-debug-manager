<?php
/**
 * Classi dell'emergency senza database: richiesta, sessione e CSRF, log
 * degli accessi, stato del sito, cartella privata, pagine.
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class EmergencyCoreTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/dbdm-em-core-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tear_down() {
		$rm = function ( $dir ) use ( &$rm ) {
			foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
				is_dir( "$dir/$item" ) ? $rm( "$dir/$item" ) : unlink( "$dir/$item" );
			}
			rmdir( $dir );
		};
		$rm( $this->dir );
		parent::tear_down();
	}

	/* --- Richiesta ----------------------------------------------------------- */

	public function test_azione_dal_post_prima_della_query_string_e_ripulita(): void {
		$r = new DBDM_Em_Request( array( 'a' => 'logout' ), array( 'a' => 'clear_LOG<x>' ), array() );
		$this->assertSame( 'clear_x', $r->action() );

		$r = new DBDM_Em_Request( array( 'a' => 'logout' ), array(), array() );
		$this->assertSame( 'logout', $r->action() );

		$r = new DBDM_Em_Request( array( 'a' => array( 'logout' ) ), array(), array() );
		$this->assertSame( '', $r->action() );
	}

	public function test_campi_post_tipizzati(): void {
		$r = new DBDM_Em_Request(
			array(),
			array( 'csrf' => array( 'x' ), 'plugin' => 'a/a.php', 'enable' => '1', 'off' => '0' ),
			array( 'REQUEST_METHOD' => 'POST' )
		);
		$this->assertTrue( $r->is_post() );
		$this->assertTrue( $r->has_post( 'csrf' ) );
		$this->assertSame( '', $r->post_string( 'csrf' ) );
		$this->assertSame( 'a/a.php', $r->post_string( 'plugin' ) );
		$this->assertSame( '', $r->post_string( 'assente' ) );
		$this->assertTrue( $r->post_flag( 'enable' ) );
		$this->assertFalse( $r->post_flag( 'off' ) );
		$this->assertFalse( ( new DBDM_Em_Request( array(), array(), array( 'REQUEST_METHOD' => 'GET' ) ) )->is_post() );
	}

	public function test_percorsi_ip_e_https(): void {
		$r = new DBDM_Em_Request(
			array(),
			array(),
			array(
				'REQUEST_URI'          => '/wp-content/plugins/db-debug-manager/emergency.php?a=logout&x=1',
				'SCRIPT_NAME'          => '/wp-content/plugins/db-debug-manager/emergency.php',
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
				'HTTPS'                => 'on',
			)
		);
		$this->assertSame( '/wp-content/plugins/db-debug-manager/emergency.php', $r->path() );
		$this->assertSame( '/wp-content/plugins/db-debug-manager/', $r->script_dir() );
		$this->assertSame( '10.0.0.1', $r->ip( false ) );
		$this->assertSame( '198.51.100.7', $r->ip( true ) );
		$this->assertTrue( $r->is_https() );
		$this->assertSame( '-', $r->user_agent() );
	}

	/**
	 * Bug 35: dietro un proxy TLS (o con HTTPS=off di IIS) il cookie di
	 * sessione non riceveva il flag secure, o lo riceveva a torto.
	 *
	 * @dataProvider richieste_https
	 */
	public function test_https_anche_dietro_un_proxy( $server, $https ): void {
		$this->assertSame( $https, ( new DBDM_Em_Request( array(), array(), $server ) )->is_https() );
	}

	public function richieste_https() {
		return array(
			'HTTPS on'                  => array( array( 'HTTPS' => 'on' ), true ),
			'HTTPS off (IIS)'           => array( array( 'HTTPS' => 'off' ), false ),
			'porta 443'                 => array( array( 'SERVER_PORT' => '443' ), true ),
			'X-Forwarded-Proto https'   => array( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ), true ),
			'X-Forwarded-Proto catena'  => array( array( 'HTTP_X_FORWARDED_PROTO' => 'HTTPS, http' ), true ),
			'X-Forwarded-Proto http'    => array( array( 'HTTP_X_FORWARDED_PROTO' => 'http' ), false ),
			'X-Forwarded-SSL'           => array( array( 'HTTP_X_FORWARDED_SSL' => 'on' ), true ),
			'http semplice'             => array( array( 'SERVER_PORT' => '80' ), false ),
		);
	}

	/**
	 * Bug 35: niente header contro framing, indicizzazione e cache.
	 */
	public function test_header_di_sicurezza(): void {
		$headers = DBDM_Em_App::security_headers();
		foreach ( array(
			'X-Frame-Options: DENY',
			"Content-Security-Policy: frame-ancestors 'none'",
			'X-Robots-Tag: noindex, nofollow',
			'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
			'Referrer-Policy: no-referrer',
			'X-Content-Type-Options: nosniff',
		) as $expected ) {
			$this->assertContains( $expected, $headers );
		}
	}

	/* --- Sessione e CSRF ----------------------------------------------------- */

	public function test_token_csrf_stabile_e_confronto_rigoroso(): void {
		$store   = array();
		$session = new DBDM_Em_Session( $store );
		$token   = $session->csrf_token();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$this->assertSame( $token, $session->csrf_token() );
		$this->assertSame( $token, $store['dbdm_csrf'], 'scritto nell\'array della sessione' );
		$this->assertTrue( $session->csrf_check( $token ) );
		$this->assertFalse( $session->csrf_check( '' ) );
		$this->assertFalse( $session->csrf_check( strtoupper( $token ) ) );
		$this->assertFalse( $session->csrf_check( array( $token ) ) );

		$empty = array();
		$this->assertFalse( ( new DBDM_Em_Session( $empty ) )->csrf_check( '' ), 'senza token in sessione' );
	}

	public function test_login_e_scadenza_dopo_30_minuti(): void {
		$store   = array();
		$session = new DBDM_Em_Session( $store );
		$session->csrf_token();
		$this->assertFalse( $session->is_authed( 'fp', 1000 ) );

		$session->login( 'fp', 1000 );
		$this->assertArrayNotHasKey( 'dbdm_csrf', $store, 'il token del login non vale più' );
		$this->assertTrue( $session->is_authed( 'fp', 1000 + 1800 ) );
		$this->assertFalse( $session->is_authed( 'fp', 1000 + 1801 ) );
		$this->assertSame( array(), $store, 'sessione scaduta svuotata' );
	}

	public function test_impronta_cambiata_chiude_la_sessione(): void {
		$store   = array();
		$session = new DBDM_Em_Session( $store );
		$session->login( 'vecchia', 1000 );

		$this->assertFalse( $session->is_authed( 'nuova', 1001 ) );
		$this->assertSame( array(), $store );
	}

	/* --- Logout (bug 38) ---------------------------------------------------- */

	public function test_logout_solo_in_post_con_token_valido(): void {
		$store   = array();
		$session = new DBDM_Em_Session( $store );
		$token   = $session->csrf_token();
		$post    = array( 'REQUEST_METHOD' => 'POST' );

		$this->assertTrue( DBDM_Em_App::is_logout( new DBDM_Em_Request( array(), array( 'a' => 'logout', 'csrf' => $token ), $post ), $session ) );
		$this->assertFalse( DBDM_Em_App::is_logout( new DBDM_Em_Request( array( 'a' => 'logout' ), array(), array( 'REQUEST_METHOD' => 'GET' ) ), $session ), 'GET (link o immagine da un altro sito)' );
		$this->assertFalse( DBDM_Em_App::is_logout( new DBDM_Em_Request( array(), array( 'a' => 'logout', 'csrf' => 'altro' ), $post ), $session ), 'token non valido' );
		$this->assertFalse( DBDM_Em_App::is_logout( new DBDM_Em_Request( array(), array( 'a' => 'clear_log', 'csrf' => $token ), $post ), $session ) );
	}

	/* --- Log degli accessi --------------------------------------------------- */

	public function test_riga_del_log_degli_accessi(): void {
		$file   = $this->dir . '/emergency-access.log';
		$logger = new DBDM_Em_Logger( $file, '203.0.113.5', str_repeat( 'u', 200 ) );
		$logger->log( 'LOGIN_FAIL', '', 0 );
		$logger->log( 'ACTION', 'clear_transients: 3', 60 );

		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		$this->assertSame( '[1970-01-01 00:00:00] LOGIN_FAIL | IP=203.0.113.5 | UA=' . str_repeat( 'u', 120 ) . ' | ', $lines[0] );
		$this->assertStringEndsWith( '| clear_transients: 3', $lines[1] );
	}

	/**
	 * Bug 36: un a capo nello User-Agent o nel dettaglio (uno slug inviato
	 * con il modulo) creava righe false nel log.
	 */
	public function test_righe_del_log_non_falsificabili(): void {
		$file   = $this->dir . '/emergency-access.log';
		$logger = new DBDM_Em_Logger( $file, '203.0.113.5', "Mozilla\n[2026-01-01 00:00:00] LOGIN_SUCCESS | IP=1.2.3.4" );
		$logger->log( 'ACTION', "disable_plugin: x\r\n[2026-01-01] LOGIN_SUCCESS\t\x00", 0 );

		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'UA=Mozilla\n[2026-01-01 00:00:00] LOGIN_SUCCESS', $lines[0] );
		$this->assertStringEndsWith( '| disable_plugin: x\r\n[2026-01-01] LOGIN_SUCCESS\t\x00', $lines[0] );
	}

	/**
	 * Bug 36: il log degli accessi cresceva senza limite.
	 */
	public function test_rotazione_del_log_degli_accessi(): void {
		$file   = $this->dir . '/emergency-access.log';
		$logger = new DBDM_Em_Logger( $file, '203.0.113.5', 'ua', 200 );
		for ( $i = 0; $i < 6; $i++ ) {
			$logger->log( 'LOGIN_FAIL', 'tentativo ' . $i, 0 );
		}

		$this->assertLessThanOrEqual( 200 + 100, filesize( $file ) );
		$this->assertFileExists( $file . '.1', 'una sola copia precedente' );
		$this->assertFileDoesNotExist( $file . '.2' );
		$this->assertStringEndsWith( "tentativo 5\n", file_get_contents( $file ), 'l\'ultima riga è nel file corrente' );
	}

	/* --- Stato del sito ------------------------------------------------------ */

	public function test_percorso_del_debug_log(): void {
		$config = $this->dir . '/wp-config.php';
		file_put_contents( $config, "<?php\ndefine( 'WP_DEBUG_LOG', true );\n" );
		$this->assertSame( '/wp-content/debug.log', DBDM_Em_Status::debug_log_path( $config, '/wp-content' ) );

		file_put_contents( $config, "<?php\ndefine( 'WP_DEBUG_LOG', '/srv/log/wp.log' );\n" );
		$this->assertSame( '/srv/log/wp.log', DBDM_Em_Status::debug_log_path( $config, '/wp-content' ) );

		$this->assertSame( '/wp-content/debug.log', DBDM_Em_Status::debug_log_path( $this->dir . '/assente.php', '/wp-content' ) );
	}

	public function test_coda_di_un_file(): void {
		$file = $this->dir . '/x.log';
		file_put_contents( $file, 'abcdefghij' );
		$this->assertSame( 'ghij', DBDM_Em_Status::tail_bytes( $file, 4 ) );
		$this->assertSame( 'abcdefghij', DBDM_Em_Status::tail_bytes( $file, 100 ) );
		$this->assertSame( '', DBDM_Em_Status::tail_bytes( $this->dir . '/assente.log', 4 ) );
	}

	public function test_stato_delle_costanti(): void {
		$status = DBDM_Em_Status::constants_status(
			"<?php\ndefine( 'WP_DEBUG', true );\ndefine('WP_DEBUG_DISPLAY', false);\ndefine( 'WP_DEBUG_LOG', '/srv/wp.log' );\ndefine( 'SAVEQUERIES', '' );\n"
		);
		$this->assertSame(
			array(
				'WP_DEBUG'         => true,
				'WP_DEBUG_LOG'     => true,
				'WP_DEBUG_DISPLAY' => false,
				'SCRIPT_DEBUG'     => null,
				'SAVEQUERIES'      => false,
			),
			$status
		);
		$this->assertSame( array_fill_keys( DBDM_Em_Status::MANAGED_CONSTANTS, null ), DBDM_Em_Status::constants_status( false ) );
	}

	/**
	 * Bug 31: lo stato veniva letto con una regex (solo true/false/stringhe,
	 * anche dentro i commenti).
	 */
	public function test_stato_delle_costanti_come_le_vede_php(): void {
		putenv( 'DBDM_TEST_SAVEQUERIES=1' );
		$status = DBDM_Em_Status::constants_status(
			"<?php\n" .
			"/* define( 'WP_DEBUG_DISPLAY', true ); */\n" .
			"define( 'WP_DEBUG', 1 );\n" .
			"defined( 'SCRIPT_DEBUG' ) || define( 'SCRIPT_DEBUG', true );\n" .
			"define( 'SAVEQUERIES', getenv( 'DBDM_TEST_SAVEQUERIES' ) );\n" .
			"define( 'WP_DEBUG_LOG', \$percorso );\n"
		);
		putenv( 'DBDM_TEST_SAVEQUERIES' );

		$this->assertSame(
			array(
				'WP_DEBUG'         => true,
				'WP_DEBUG_LOG'     => 'unknown',
				'WP_DEBUG_DISPLAY' => null,
				'SCRIPT_DEBUG'     => true,
				'SAVEQUERIES'      => true,
			),
			$status
		);
	}

	public function test_costante_dal_valore_non_determinabile_nella_dashboard(): void {
		$store = array();
		$view  = new DBDM_Em_View( new DBDM_Em_Session( $store ) );
		$html  = $this->render( function () use ( $view ) {
			$view->dashboard(
				array(),
				array(
					'log_content'     => '',
					'log_size'        => 0,
					'active_plugins'  => array(),
					'cur_theme'       => 'tt',
					'consts_status'   => array( 'WP_DEBUG_LOG' => 'unknown' ),
					'php_error_log'   => '',
					'php_log_content' => '',
					'snapshots'       => array(),
				)
			);
		} );
		$this->assertStringContainsString( '<span class="tag tag-warn">da verificare</span>', $html );
	}

	/**
	 * Bug 27: con una object cache persistente le modifiche fatte
	 * dall'emergency possono non avere effetto: va detto.
	 */
	public function test_rileva_la_object_cache_persistente(): void {
		$this->assertFalse( DBDM_Em_Status::has_object_cache( $this->dir ) );
		file_put_contents( $this->dir . '/object-cache.php', '<?php' );
		$this->assertTrue( DBDM_Em_Status::has_object_cache( $this->dir ) );
	}

	public function test_avviso_object_cache_nella_dashboard(): void {
		$store = array();
		$view  = new DBDM_Em_View( new DBDM_Em_Session( $store ) );
		$data  = array(
			'log_content'     => '',
			'log_size'        => 0,
			'active_plugins'  => array(),
			'cur_theme'       => 'tt',
			'consts_status'   => array(),
			'php_error_log'   => '',
			'php_log_content' => '',
			'snapshots'       => array(),
			'object_cache'    => true,
		);
		$html = $this->render( function () use ( $view, $data ) {
			$view->dashboard( array(), $data );
		} );
		$this->assertStringContainsString( 'object cache persistente', $html );

		$data['object_cache'] = false;
		$html = $this->render( function () use ( $view, $data ) {
			$view->dashboard( array(), $data );
		} );
		$this->assertStringNotContainsString( 'object cache persistente', $html );
	}

	/**
	 * Bug 37: l'error log di PHP può essere quello di tutto il server
	 * (hosting condiviso): solo le voci con i percorsi di questo sito.
	 */
	public function test_error_log_solo_voci_del_sito(): void {
		$log = "[06-Oct-2026 10:00:00 UTC] PHP Warning:  x in /srv/altro/index.php on line 3\n" .
			"[06-Oct-2026 10:00:01 UTC] PHP Fatal error:  Uncaught Error: y in /srv/sito/wp-content/plugins/rotto/rotto.php:12\n" .
			"Stack trace:\n" .
			"#0 {main}\n" .
			"  thrown in /srv/sito/wp-content/plugins/rotto/rotto.php on line 12\n" .
			"[06-Oct-2026 10:00:02 UTC] PHP Notice:  z in /srv/altro/b.php on line 1\n" .
			"riga senza data in /srv/sito-bis/x.php\n" .
			"[06-Oct-2026 10:00:03 UTC] PHP Warning:  w in /srv/temi/tt/functions.php on line 9\n";

		$this->assertSame(
			"[06-Oct-2026 10:00:01 UTC] PHP Fatal error:  Uncaught Error: y in /srv/sito/wp-content/plugins/rotto/rotto.php:12\n" .
			"Stack trace:\n" .
			"#0 {main}\n" .
			"  thrown in /srv/sito/wp-content/plugins/rotto/rotto.php on line 12\n" .
			"[06-Oct-2026 10:00:03 UTC] PHP Warning:  w in /srv/temi/tt/functions.php on line 9\n",
			DBDM_Em_Status::site_entries( $log, array( '/srv/sito', '/srv/temi/' ) )
		);
		$this->assertSame( '', DBDM_Em_Status::site_entries( $log, array() ) );
	}

	public function test_snapshot_dal_piu_recente(): void {
		$file = $this->dir . '/snapshots.json';
		$this->assertSame( array(), DBDM_Em_Status::snapshots( $file ) );

		file_put_contents( $file, '[{"id":"a"},{"id":"b"}]' );
		$this->assertSame( array( array( 'id' => 'b' ), array( 'id' => 'a' ) ), DBDM_Em_Status::snapshots( $file ) );

		file_put_contents( $file, '{rotto' );
		$this->assertSame( array(), DBDM_Em_Status::snapshots( $file ) );
	}

	/* --- Cartella privata ---------------------------------------------------- */

	public function test_cartella_privata_dal_percorso_o_dal_token(): void {
		$token = 'abcdef0123456789';
		$dir   = $this->dir . '/dbdm-private-' . $token;
		mkdir( $dir );

		$this->assertSame( $dir . '/', DBDM_Em_App::resolve_private_dir( $dir, '', $this->dir ) );
		$this->assertSame( $dir . '/', DBDM_Em_App::resolve_private_dir( $dir . '/', '', $this->dir ) );
		$this->assertSame( $dir . '/', DBDM_Em_App::resolve_private_dir( '', $token, $this->dir ) );
		$this->assertSame( $dir . '/', DBDM_Em_App::resolve_private_dir( '/non/esiste/dbdm-private-' . $token, $token, $this->dir ), 'ripiego sul token' );
	}

	public function test_cartella_privata_manomessa_o_assente(): void {
		mkdir( $this->dir . '/altra' );
		$this->assertSame( '', DBDM_Em_App::resolve_private_dir( $this->dir . '/altra', '', $this->dir ), 'nome non valido' );
		$this->assertSame( '', DBDM_Em_App::resolve_private_dir( '', '../../etc', $this->dir ), 'token non valido' );
		$this->assertSame( '', DBDM_Em_App::resolve_private_dir( '', 'abcdef0123456789', $this->dir ), 'cartella assente' );
		$this->assertSame( '', DBDM_Em_App::resolve_private_dir( array(), null, $this->dir ) );
	}

	/* --- Percorsi del sito (bug 21) ----------------------------------------- */

	public function test_percorsi_del_sito_salvati_da_wordpress(): void {
		foreach ( array( 'content', 'plugins', 'themes' ) as $d ) {
			mkdir( $this->dir . '/' . $d );
		}
		$saved = array(
			'content_dir' => $this->dir . '/content',
			'plugins_dir' => $this->dir . '/plugins',
			'themes_dir'  => $this->dir . '/themes/',
		);
		$this->assertSame(
			array(
				'content_dir' => $this->dir . '/content',
				'plugins_dir' => $this->dir . '/plugins',
				'themes_dir'  => $this->dir . '/themes',
			),
			DBDM_Em_App::site_paths( $saved, '/srv/wp/wp-content/plugins/db-debug-manager' )
		);
	}

	public function test_percorsi_del_sito_di_ripiego(): void {
		$fallback = array(
			'content_dir' => '/srv/wp/wp-content',
			'plugins_dir' => '/srv/wp/wp-content/plugins',
			'themes_dir'  => '/srv/wp/wp-content/themes',
		);
		$plugin   = '/srv/wp/wp-content/plugins/db-debug-manager';
		$this->assertSame( $fallback, DBDM_Em_App::site_paths( null, $plugin ), 'opzione assente' );
		$this->assertSame( $fallback, DBDM_Em_App::site_paths( 'a:0:{}', $plugin ), 'non un elenco' );
		$this->assertSame( $fallback, DBDM_Em_App::site_paths( array( 'content_dir' => $this->dir . '/manca', 'themes_dir' => array(), 'plugins_dir' => 'relativo' ), $plugin ), 'cartelle inesistenti o non valide' );
	}

	/* --- Flusso: errori del database (bug 34) ------------------------------ */

	/**
	 * Installazione finta: wp-config.php e cartella del plugin.
	 *
	 * @return string Cartella del plugin.
	 */
	private function site() {
		$plugin = $this->dir . '/wp/wp-content/plugins/db-debug-manager';
		mkdir( $plugin, 0755, true );
		copy( DBDM_TEST_ROOT . '/tests/fixtures/wp-config/standard.php', $this->dir . '/wp/wp-config.php' );
		return $plugin;
	}

	private function dispatch( DBDM_Em_App $app ) {
		$store = array();
		return $this->render( function () use ( $app, &$store ) {
			$app->dispatch( new DBDM_Em_Request( array(), array(), array( 'REQUEST_METHOD' => 'GET' ) ), new DBDM_Em_Session( $store ) );
		} );
	}

	/**
	 * Risponde con $app e restituisce [pagina, error log di PHP].
	 */
	private function dispatch_logged( DBDM_Em_App $app ) {
		$log      = $this->dir . '/php-errors.log';
		$previous = ini_set( 'error_log', $log );
		try {
			$html = $this->dispatch( $app );
		} finally {
			ini_set( 'error_log', $previous );
		}
		return array( $html, file_exists( $log ) ? file_get_contents( $log ) : '' );
	}

	private function sqlite() {
		if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			$this->markTestSkipped( 'pdo_sqlite non disponibile' );
		}
		return new PDO( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	}

	/**
	 * Bug 37: prima del login un messaggio unico; il motivo solo nel log.
	 */
	private function assert_unavailable( $html, $log, $reason ) {
		$this->assertStringContainsString( 'Accesso d&#039;emergenza non disponibile.', $html );
		$this->assertStringNotContainsString( $reason, $html );
		$this->assertStringContainsString( 'DB Debug Manager emergency: ' . $reason, $log );
	}

	public function test_database_irraggiungibile(): void {
		$app = new DBDM_Em_App( $this->site(), function () {
			return false;
		} );
		list( $html, $log ) = $this->dispatch_logged( $app );
		$this->assert_unavailable( $html, $log, 'connessione al database fallita' );
	}

	public function test_emergency_disattivato_senza_rivelarlo(): void {
		$pdo = $this->sqlite();
		$pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
		$pdo->exec( "INSERT INTO wp_options (option_name, option_value) VALUES ('dbdm_emergency_enabled', '')" );
		$app = new DBDM_Em_App( $this->site(), function () use ( $pdo ) {
			return $pdo;
		} );
		list( $html, $log ) = $this->dispatch_logged( $app );
		$this->assert_unavailable( $html, $log, 'accesso disattivato dal pannello' );

		$pdo->exec( "UPDATE wp_options SET option_value = '1' WHERE option_name = 'dbdm_emergency_enabled'" );
		list( $html, $log ) = $this->dispatch_logged( $app );
		$this->assert_unavailable( $html, $log, 'nessuna password configurata' );
	}

	/**
	 * Bug 34: con un prefisso delle tabelle sbagliato la PDOException non
	 * era gestita: risposta 500 vuota.
	 */
	public function test_errore_del_database_pagina_senza_dettagli(): void {
		// Nessuna tabella: come un $table_prefix che non corrisponde.
		$pdo = $this->sqlite();
		$app = new DBDM_Em_App( $this->site(), function () use ( $pdo ) {
			return $pdo;
		} );
		list( $html, $log ) = $this->dispatch_logged( $app );

		$this->assert_unavailable( $html, $log, 'errore del database (controlla $table_prefix in wp-config.php)' );
		$this->assertStringNotContainsString( 'no such table', $html );
		$this->assertStringContainsString( 'no such table', $log );
	}

	/* --- Pagine -------------------------------------------------------------- */

	private function render( callable $fn ) {
		ob_start();
		$fn();
		return ob_get_clean();
	}

	public function test_pagine_con_output_escapato_e_token_della_sessione(): void {
		$store = array();
		$view  = new DBDM_Em_View( new DBDM_Em_Session( $store ) );

		$error = $this->render( function () use ( $view ) {
			$view->error( '<script>x</script>' );
		} );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $error );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;', $error );

		$login = $this->render( function () use ( $view ) {
			$view->login( 'Password errata.' );
		} );
		$this->assertStringContainsString( 'name="csrf" value="' . $store['dbdm_csrf'] . '"', $login );
		$this->assertStringContainsString( 'Password errata.', $login );
		$this->assertStringNotContainsString( 'a=logout', $login . $error );
	}

	public function test_il_token_del_login_e_quello_dopo_la_scadenza_della_sessione(): void {
		$store   = array();
		$session = new DBDM_Em_Session( $store );
		$view    = new DBDM_Em_View( $session );
		$session->csrf_token();
		$session->login( 'fp', 1000 );
		$session->is_authed( 'fp', 9999 ); // Scaduta: sessione svuotata.

		$login = $this->render( function () use ( $view ) {
			$view->login();
		} );
		$this->assertTrue( $session->csrf_check( preg_match( '/name="csrf" value="([a-f0-9]+)"/', $login, $m ) ? $m[1] : '' ) );
	}

	public function test_dashboard(): void {
		$store = array();
		$view  = new DBDM_Em_View( new DBDM_Em_Session( $store ) );
		$html  = $this->render( function () use ( $view ) {
			$view->dashboard(
				array( array( 'ok', 'Fatto.' ), array( 'err', 'No <b>' ), array( 'warn', 'Niente da fare.' ) ),
				array(
					'log_content'     => "PHP Notice: <img src=x>\n",
					'log_size'        => 2048,
					'active_plugins'  => array( 'a/a.php', 'b/b.php' ),
					'cur_theme'       => 'twentytwentyfive',
					'consts_status'   => array( 'WP_DEBUG' => true, 'SAVEQUERIES' => null ),
					'php_error_log'   => '',
					'php_log_content' => '',
					'snapshots'       => array( array( 'id' => 'snap_1', 'trigger' => 'manual', 'timestamp' => 0, 'active_plugins' => array( 'a/a.php' ), 'stylesheet' => 'tt', 'note' => 'nota' ) ),
				)
			);
		} );
		$this->assertStringContainsString( '<div class="notice notice-ok">Fatto.</div>', $html );
		$this->assertStringContainsString( '<div class="notice notice-err">No &lt;b&gt;</div>', $html );
		$this->assertStringContainsString( '<div class="notice notice-warn">Niente da fare.</div>', $html );
		$this->assertStringContainsString( 'name="plugin" value="' . bin2hex( 'a/a.php' ) . '"', $html, 'slug in esadecimale (bug 30)' );
		$this->assertStringContainsString( 'PHP Notice: &lt;img src=x&gt;', $html );
		$this->assertStringContainsString( 'Plugin attivi (2)', $html );
		$this->assertStringContainsString( 'Snapshot disponibili (1)', $html );
		$this->assertStringContainsString( '2.0 KB', $html );
		$this->assertSame( 1 + 2 + 4 + 2 + 1, substr_count( $html, 'name="csrf" value="' . $store['dbdm_csrf'] . '"' ), 'un token per ogni modulo' );
		$this->assertStringNotContainsString( 'a=logout', $html, 'niente logout via GET (bug 38)' );
		$this->assertSame( 1, preg_match( '#<form method="post"[^>]*>\s*<input type="hidden" name="csrf" value="[a-f0-9]{32}">\s*<input type="hidden" name="a" value="logout">#', $html ) );
	}

	/**
	 * Bug 19: con un byte UTF-8 non valido (Latin-1, coda tagliata a metà
	 * carattere) htmlspecialchars() restituiva '' e il pannello restava vuoto.
	 */
	public function test_log_con_utf8_non_valido_resta_leggibile(): void {
		$store   = array();
		$view    = new DBDM_Em_View( new DBDM_Em_Session( $store ) );
		$php_log = $this->dir . '/php.log';
		touch( $php_log );
		$html = $this->render( function () use ( $view, $php_log ) {
			$view->dashboard(
				array(),
				array(
					'log_content'     => "\xA8 metà carattere\nPHP Warning: caff\xE8 <b>\n",
					'log_size'        => 100,
					'active_plugins'  => array( "latin\xE9/x.php" ),
					'cur_theme'       => "tema\xE9",
					'consts_status'   => array(),
					'php_error_log'   => $php_log,
					'php_log_content' => "errore \xFF finale",
					'snapshots'       => array(),
				)
			);
		} );
		$this->assertStringContainsString( "\u{FFFD} metà carattere\nPHP Warning: caff\u{FFFD} &lt;b&gt;", $html );
		$this->assertStringContainsString( "errore \u{FFFD} finale", $html );
		$this->assertStringContainsString( "<code>latin\u{FFFD}/x.php</code>", $html );
		$this->assertStringContainsString( "<code>tema\u{FFFD}</code>", $html );
	}

	/**
	 * Bug 18: lo slug finiva in una stringa JS tra apici dentro onsubmit;
	 * l'entità &#039; viene decodificata dal browser prima di eseguire il JS,
	 * quindi un apostrofo chiudeva la stringa.
	 */
	public function test_slug_con_apostrofo_nel_confirm(): void {
		$store = array();
		$view  = new DBDM_Em_View( new DBDM_Em_Session( $store ) );
		$slug  = "o'x/x.php\");alert(1);//<\xff";
		$html  = $this->render( function () use ( $view, $slug ) {
			$view->dashboard(
				array(),
				array(
					'log_content'     => '',
					'log_size'        => 0,
					'active_plugins'  => array( $slug ),
					'cur_theme'       => 'tt',
					'consts_status'   => array(),
					'php_error_log'   => '',
					'php_log_content' => '',
					'snapshots'       => array(),
				)
			);
		} );

		$this->assertSame( 1, preg_match( '/<form[^>]*onsubmit="([^"]*)"[^>]*>\s*<input[^>]*>\s*<input[^>]*value="disable_plugin"/', $html, $m ) );
		// Il JS che il browser esegue: l'attributo con le entità decodificate.
		$js = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$this->assertSame( 1, preg_match( '/^return confirm\((.*)\);$/s', $js, $arg ), $js );
		$this->assertSame( "Disattivare o'x/x.php\");alert(1);//<\u{FFFD}?", json_decode( $arg[1] ), 'un solo argomento stringa, col testo intero' );
	}
}
