<?php
/**
 * Azioni dell'emergency sul database e sui file: DBDM_Em_Repository e
 * DBDM_Em_Actions su PDO SQLite in memoria (in produzione MySQL), con
 * plugin, temi, wp-config.php e cartella privata in una cartella temporanea.
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class EmergencyActionsTest extends TestCase {

	/** @var string wp-content finto. */
	private $content;

	/** @var string */
	private $private;

	/** @var string */
	private $config;

	/** @var PDO */
	private $pdo;

	/** @var DBDM_Em_Repository */
	private $repo;

	protected function set_up() {
		parent::set_up();
		if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			$this->markTestSkipped( 'pdo_sqlite non disponibile' );
		}
		$this->content = sys_get_temp_dir() . '/dbdm-em-actions-' . uniqid();
		$this->private = $this->content . '/dbdm-private-abcdef0123456789/';
		$this->config  = $this->content . '/wp-config.php';
		mkdir( $this->private, 0750, true );
		mkdir( $this->content . '/plugins/a', 0755, true );
		mkdir( $this->content . '/plugins/b', 0755, true );
		// Temi in una cartella diversa da wp-content/themes (bug 21).
		mkdir( $this->content . '/temi', 0755, true );
		touch( $this->content . '/plugins/a/a.php' );
		touch( $this->content . '/plugins/b/b.php' );
		copy( DBDM_TEST_ROOT . '/tests/fixtures/wp-config/standard.php', $this->config );

		$this->pdo = new PDO( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		$this->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
		$this->repo = new DBDM_Em_Repository( $this->pdo, 'wp_' );
		$this->seed(
			array(
				'active_plugins'                       => serialize( array( 'a/a.php', 'b/b.php' ) ),
				'template'                             => 'rotto',
				'stylesheet'                           => 'rotto',
				'_transient_x'                         => '1',
				'_transient_timeout_x'                 => '1',
				'_site_transient_y'                    => '1',
				'xtransientxfinto'                     => 'resta',
				'dbdm_debug_log_path'                  => '',
			)
		);
	}

	protected function tear_down() {
		if ( $this->content && is_dir( $this->content ) ) {
			$rm = function ( $dir ) use ( &$rm ) {
				foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
					is_dir( "$dir/$item" ) ? $rm( "$dir/$item" ) : unlink( "$dir/$item" );
				}
				rmdir( $dir );
			};
			$rm( $this->content );
		}
		parent::tear_down();
	}

	private function seed( array $options ) {
		$stmt = $this->pdo->prepare( 'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)' );
		foreach ( $options as $name => $value ) {
			$stmt->execute( array( $name, $value, 'yes' ) );
		}
	}

	private function raw( $name ) {
		$stmt = $this->pdo->prepare( 'SELECT option_value FROM wp_options WHERE option_name = ?' );
		$stmt->execute( array( $name ) );
		return $stmt->fetchColumn();
	}

	private function theme( $slug, $template = '' ) {
		mkdir( $this->content . '/temi/' . $slug );
		file_put_contents( $this->content . '/temi/' . $slug . '/style.css', "/*\nTheme Name: $slug\n" . ( $template ? "Template: $template\n" : '' ) . '*/' );
	}

	private function run_action( $action, array $post = array() ) {
		$actions = new DBDM_Em_Actions(
			$this->repo,
			new DBDM_Em_Logger( $this->private . 'emergency-access.log', '203.0.113.5', 'test' ),
			array(
				'config_path' => $this->config,
				'private_dir' => $this->private,
				'content_dir' => $this->content,
				'plugins_dir' => $this->content . '/plugins',
				'themes_dir'  => $this->content . '/temi',
			)
		);
		return $actions->run( $action, new DBDM_Em_Request( array(), $post, array( 'REQUEST_METHOD' => 'POST' ) ) );
	}

	private function access_log() {
		return (string) @file_get_contents( $this->private . 'emergency-access.log' );
	}

	/* --- Repository ---------------------------------------------------------- */

	public function test_lettura_delle_opzioni(): void {
		$this->seed( array( 'oggetto' => 'O:8:"stdClass":0:{}', 'falso' => 'b:0;' ) );

		$this->assertSame( array( 'a/a.php', 'b/b.php' ), $this->repo->get_option( 'active_plugins' ) );
		$this->assertSame( 'rotto', $this->repo->get_option( 'stylesheet' ) );
		$this->assertSame( 'def', $this->repo->get_option( 'assente', 'def' ) );
		$this->assertSame( 'O:8:"stdClass":0:{}', $this->repo->get_option( 'oggetto' ), 'oggetti mai istanziati' );
		$this->assertFalse( $this->repo->get_option( 'falso' ) );
	}

	public function test_scrittura_delle_opzioni(): void {
		$this->repo->update_option( 'active_plugins', array( 'a/a.php' ) );
		$this->repo->update_option( 'stylesheet', 'tt' );
		$this->repo->update_option( 'assente', 'x' );

		$this->assertSame( serialize( array( 'a/a.php' ) ), $this->raw( 'active_plugins' ) );
		$this->assertSame( 'tt', $this->raw( 'stylesheet' ) );
		$this->assertFalse( $this->raw( 'assente' ), 'UPDATE: un\'opzione mancante non viene creata' );
	}

	public function test_plugin_attivi_non_validi(): void {
		$this->pdo->exec( "UPDATE wp_options SET option_value = 'rotto' WHERE option_name = 'active_plugins'" );
		$this->assertNull( $this->repo->active_plugins() );
	}

	public function test_prefisso_delle_tabelle(): void {
		$this->pdo->exec( 'CREATE TABLE altro_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
		$this->pdo->exec( "INSERT INTO altro_options (option_name, option_value) VALUES ('stylesheet', 'altro')" );
		$this->assertSame( 'altro', ( new DBDM_Em_Repository( $this->pdo, 'altro_' ) )->get_option( 'stylesheet' ) );
	}

	/* --- Plugin -------------------------------------------------------------- */

	public function test_disattiva_tutti_i_plugin(): void {
		$notices = $this->run_action( 'disable_all_plugins' );

		$this->assertSame( array( array( 'ok', 'Tutti i plugin sono stati disattivati.' ) ), $notices );
		$this->assertSame( array(), $this->repo->get_option( 'active_plugins' ) );
		$this->assertStringContainsString( 'ACTION | IP=203.0.113.5 | UA=test | disable_all_plugins', $this->access_log() );
	}

	public function test_disattiva_un_plugin(): void {
		$notices = $this->run_action( 'disable_plugin', array( 'plugin' => 'a/a.php' ) );

		$this->assertSame( array( array( 'ok', 'Plugin disattivato: a/a.php' ) ), $notices );
		$this->assertSame( array( 'b/b.php' ), $this->repo->get_option( 'active_plugins' ) );
	}

	public function test_disattiva_un_plugin_senza_slug_o_con_array(): void {
		$this->assertSame( array(), $this->run_action( 'disable_plugin' ) );
		$this->assertSame( array(), $this->run_action( 'disable_plugin', array( 'plugin' => array( 'a/a.php' ) ) ) );
		$this->assertSame( array( 'a/a.php', 'b/b.php' ), $this->repo->get_option( 'active_plugins' ) );
	}

	/* --- Tema ---------------------------------------------------------------- */

	public function test_tema_default_piu_recente(): void {
		$this->theme( 'twentytwentyone' );
		$this->theme( 'twentytwentyfour' );

		$notices = $this->run_action( 'switch_to_default_theme' );

		$this->assertSame( array( array( 'ok', 'Tema cambiato a: twentytwentyfour' ) ), $notices );
		$this->assertSame( 'twentytwentyfour', $this->raw( 'template' ) );
		$this->assertSame( 'twentytwentyfour', $this->raw( 'stylesheet' ) );
	}

	public function test_senza_temi_default_il_primo_con_style_css(): void {
		mkdir( $this->content . '/temi/vuoto' );
		$this->theme( 'mio' );
		$this->assertSame( 'mio', DBDM_Em_Actions::default_theme( $this->content . '/temi' ) );
	}

	/**
	 * Bug 28: il tema scelto poteva essere quello attivo (rotto) o un child
	 * theme (che dipende da un padre).
	 */
	public function test_tema_di_ripiego_mai_quello_attivo(): void {
		$this->theme( 'twentytwentyfour' );
		$this->theme( 'twentytwentyone' );
		$this->pdo->exec( "UPDATE wp_options SET option_value = 'twentytwentyfour' WHERE option_name IN ('stylesheet', 'template')" );

		$this->assertSame( array( array( 'ok', 'Tema cambiato a: twentytwentyone' ) ), $this->run_action( 'switch_to_default_theme' ) );
		$this->assertSame( 'twentytwentyone', $this->raw( 'stylesheet' ) );
	}

	public function test_tema_di_ripiego_mai_il_padre_del_tema_attivo(): void {
		$this->theme( 'twentytwentyfive' );
		$this->theme( 'figlio', 'twentytwentyfive' );
		$this->theme( 'altro' );
		$this->pdo->exec( "UPDATE wp_options SET option_value = 'figlio' WHERE option_name = 'stylesheet'" );
		$this->pdo->exec( "UPDATE wp_options SET option_value = 'twentytwentyfive' WHERE option_name = 'template'" );

		$this->run_action( 'switch_to_default_theme' );
		$this->assertSame( 'altro', $this->raw( 'stylesheet' ) );
	}

	public function test_tema_di_ripiego_mai_un_child_theme(): void {
		$this->theme( 'aaa-figlio', 'padre' );
		$this->theme( 'padre' );

		$this->run_action( 'switch_to_default_theme' );
		$this->assertSame( 'padre', $this->raw( 'stylesheet' ) );
		$this->assertSame( 'padre', $this->raw( 'template' ) );
	}

	public function test_un_tema_default_senza_style_css_e_saltato(): void {
		mkdir( $this->content . '/temi/twentytwentyfive' );
		$this->theme( 'twentytwentythree' );

		$this->run_action( 'switch_to_default_theme' );
		$this->assertSame( 'twentytwentythree', $this->raw( 'stylesheet' ) );
	}

	public function test_nessun_tema_disponibile(): void {
		$notices = $this->run_action( 'switch_to_default_theme' );

		$this->assertSame( 'err', $notices[0][0] );
		$this->assertSame( 'rotto', $this->raw( 'stylesheet' ) );
		$this->assertNull( DBDM_Em_Actions::default_theme( $this->content . '/non-esiste' ) );
	}

	/* --- Transient ----------------------------------------------------------- */

	public function test_svuota_i_transient(): void {
		$notices = $this->run_action( 'clear_transients' );

		$this->assertSame( array( array( 'ok', '3 transient eliminati.' ) ), $notices );
		$this->assertFalse( $this->raw( '_transient_x' ) );
		$this->assertFalse( $this->raw( '_site_transient_y' ) );
		$this->assertSame( 'resta', $this->raw( 'xtransientxfinto' ), 'l\'underscore del LIKE non fa da jolly' );
	}

	/* --- Costanti ------------------------------------------------------------ */

	public function test_toggle_di_una_costante_con_backup(): void {
		$before  = file_get_contents( $this->config );
		$notices = $this->run_action( 'toggle_const', array( 'const' => 'SCRIPT_DEBUG', 'enable' => '1' ) );

		$this->assertSame( array( array( 'ok', 'SCRIPT_DEBUG impostata a true' ) ), $notices );
		$this->assertSame( true, DBDM_Standalone_Config::effective_defines( file_get_contents( $this->config ) )['SCRIPT_DEBUG'] );
		$this->assertSame( $before, file_get_contents( $this->private . 'wp-config.dbdm-bak' ) );
	}

	public function test_wp_debug_log_attivato_nella_cartella_privata(): void {
		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '1' ) );
		$this->assertSame( $this->private . 'debug.log', DBDM_Standalone_Config::effective_defines( file_get_contents( $this->config ) )['WP_DEBUG_LOG'] );

		// Già acceso su un percorso non pubblico: riaccenderlo non cambia nulla.
		$this->repo->update_option( 'dbdm_debug_log_path', '/srv/log/wp.log' );
		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '1' ) );
		$this->assertSame( $this->private . 'debug.log', DBDM_Standalone_Config::effective_defines( file_get_contents( $this->config ) )['WP_DEBUG_LOG'] );

		// Spento e riacceso: vale il percorso ricordato.
		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '0' ) );
		$this->repo->update_option( 'dbdm_debug_log_path', '/srv/log/wp.log' );
		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '1' ) );
		$this->assertSame( '/srv/log/wp.log', DBDM_Standalone_Config::effective_defines( file_get_contents( $this->config ) )['WP_DEBUG_LOG'] );
	}

	private function log_define() {
		return DBDM_Standalone_Config::effective_defines( file_get_contents( $this->config ) )['WP_DEBUG_LOG'];
	}

	/**
	 * Bug 20: spegnendo WP_DEBUG_LOG dall'emergency il percorso
	 * personalizzato andava perso (il pannello lo ricorda in
	 * dbdm_debug_log_path, che può non esistere ancora).
	 */
	public function test_wp_debug_log_spento_e_riacceso_conserva_il_percorso(): void {
		$this->pdo->exec( "DELETE FROM wp_options WHERE option_name = 'dbdm_debug_log_path'" );
		DBDM_Standalone_Config::set_constants( $this->config, array( 'WP_DEBUG_LOG' => "'/srv/log/custom.log'" ) );

		$this->assertSame( array( array( 'ok', 'WP_DEBUG_LOG impostata a false' ) ), $this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '0' ) ) );
		$this->assertFalse( $this->log_define() );
		$this->assertSame( '/srv/log/custom.log', $this->repo->get_option( 'dbdm_debug_log_path' ) );

		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '1' ) );
		$this->assertSame( '/srv/log/custom.log', $this->log_define() );
	}

	public function test_il_percorso_pubblico_non_viene_ricordato(): void {
		DBDM_Standalone_Config::set_constants( $this->config, array( 'WP_DEBUG_LOG' => var_export( $this->content . '/debug.log', true ) ) );
		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '0' ) );
		$this->assertSame( '', $this->repo->get_option( 'dbdm_debug_log_path' ) );

		$this->repo->update_option( 'dbdm_debug_log_path', $this->content . '/debug.log' );
		$this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG_LOG', 'enable' => '1' ) );
		$this->assertSame( $this->private . 'debug.log', $this->log_define(), 'un valore ricordato che porta al log pubblico è ignorato' );
	}

	public function test_costante_non_gestita_ignorata(): void {
		$before = file_get_contents( $this->config );
		$this->assertSame( array(), $this->run_action( 'toggle_const', array( 'const' => 'DB_PASSWORD', 'enable' => '1' ) ) );
		$this->assertSame( $before, file_get_contents( $this->config ) );
	}

	public function test_errore_di_scrittura_riportato(): void {
		unlink( $this->config );
		$notices = $this->run_action( 'toggle_const', array( 'const' => 'WP_DEBUG', 'enable' => '1' ) );
		$this->assertSame( array( array( 'err', 'Errore: wp-config.php non trovato.' ) ), $notices );
	}

	/* --- debug.log ----------------------------------------------------------- */

	public function test_svuota_il_debug_log(): void {
		file_put_contents( $this->content . '/debug.log', "riga\n" );
		$this->assertSame( array( array( 'ok', 'debug.log svuotato.' ) ), $this->run_action( 'clear_log' ) );
		$this->assertSame( '', file_get_contents( $this->content . '/debug.log' ) );
	}

	public function test_svuota_un_debug_log_assente(): void {
		$this->assertSame( 'err', $this->run_action( 'clear_log' )[0][0] );
	}

	/* --- Snapshot ------------------------------------------------------------ */

	private function snapshots( array $snaps ) {
		file_put_contents( $this->private . 'snapshots.json', json_encode( $snaps ) );
	}

	public function test_ripristino_di_uno_snapshot(): void {
		$this->theme( 'padre' );
		$this->theme( 'figlio', 'padre' );
		$this->snapshots(
			array(
				array( 'id' => 'snap_1', 'active_plugins' => array( 'b/b.php', '../../../wp-config.php' ), 'stylesheet' => 'figlio' ),
			)
		);

		$notices = $this->run_action( 'restore_snapshot', array( 'snap_id' => 'snap_1', 'restore_plugins' => '1', 'restore_theme' => '1' ) );

		$this->assertSame(
			array(
				array( 'ok', 'Plugin attivi ripristinati: 1 plugin.' ),
				array( 'err', 'Non ripristinati (non più presenti o non validi): ../../../wp-config.php' ),
				array( 'ok', 'Tema ripristinato: figlio' ),
			),
			$notices
		);
		$this->assertSame( array( 'b/b.php' ), $this->repo->get_option( 'active_plugins' ) );
		$this->assertSame( 'figlio', $this->raw( 'stylesheet' ) );
		$this->assertSame( 'padre', $this->raw( 'template' ) );
	}

	public function test_ripristino_solo_dei_plugin(): void {
		$this->theme( 'tt' );
		$this->snapshots( array( array( 'id' => 'snap_1', 'active_plugins' => array( 'a/a.php' ), 'stylesheet' => 'tt' ) ) );

		$this->run_action( 'restore_snapshot', array( 'snap_id' => 'snap_1', 'restore_plugins' => '1' ) );

		$this->assertSame( array( 'a/a.php' ), $this->repo->get_option( 'active_plugins' ) );
		$this->assertSame( 'rotto', $this->raw( 'stylesheet' ) );
	}

	public function test_ripristino_del_tema_senza_padre_rifiutato(): void {
		$this->theme( 'orfano', 'manca' );
		$this->snapshots( array( array( 'id' => 'snap_1', 'stylesheet' => 'orfano' ) ) );

		$notices = $this->run_action( 'restore_snapshot', array( 'snap_id' => 'snap_1', 'restore_theme' => '1' ) );

		$this->assertSame( array( array( 'err', 'Tema non ripristinato (tema padre non installato: manca): orfano' ) ), $notices );
		$this->assertSame( 'rotto', $this->raw( 'stylesheet' ) );
	}

	/**
	 * @dataProvider ripristini_non_validi
	 */
	public function test_ripristino_con_parametri_non_validi( $post, $snaps, $message ): void {
		if ( null !== $snaps ) {
			$this->snapshots( $snaps );
		}
		$this->assertSame( array( array( 'err', $message ) ), $this->run_action( 'restore_snapshot', $post ) );
		$this->assertSame( array( 'a/a.php', 'b/b.php' ), $this->repo->get_option( 'active_plugins' ) );
	}

	public function ripristini_non_validi() {
		$snaps = array( array( 'id' => 'snap_1', 'active_plugins' => array() ) );
		return array(
			'senza id'           => array( array( 'restore_plugins' => '1' ), $snaps, 'Parametri ripristino incompleti.' ),
			'niente da ripristinare' => array( array( 'snap_id' => 'snap_1' ), $snaps, 'Parametri ripristino incompleti.' ),
			'id come array'      => array( array( 'snap_id' => array( 'snap_1' ), 'restore_plugins' => '1' ), $snaps, 'Parametri ripristino incompleti.' ),
			'senza snapshot'     => array( array( 'snap_id' => 'snap_1', 'restore_plugins' => '1' ), null, 'Nessuno snapshot disponibile.' ),
			'id sconosciuto'     => array( array( 'snap_id' => 'snap_9', 'restore_plugins' => '1' ), $snaps, 'Snapshot non trovato.' ),
		);
	}

	/* --- Errori -------------------------------------------------------------- */

	public function test_azione_sconosciuta(): void {
		$this->assertSame( array(), $this->run_action( 'drop_database' ) );
	}

	public function test_eccezione_del_database_riportata_e_registrata(): void {
		$this->pdo->exec( 'DROP TABLE wp_options' );
		$notices = $this->run_action( 'disable_all_plugins' );

		$this->assertSame( 'err', $notices[0][0] );
		$this->assertStringStartsWith( 'Errore: ', $notices[0][1] );
		$this->assertStringContainsString( 'ACTION_ERR', $this->access_log() );
	}
}
