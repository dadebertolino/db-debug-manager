<?php
/**
 * DBDM_Standalone_Config: lettura e scrittura di wp-config.php, condivise da
 * pannello ed emergency.php (bug 1–5, 9, 10, 22–25 del piano).
 *
 * @package DBDM\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class StandaloneConfigTest extends TestCase {

	const MANAGED = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES' );

	/** @var string */
	private $dir;

	protected function set_up() {
		parent::set_up();
		dbdm_test_reset();
		$this->dir = sys_get_temp_dir() . '/dbdm-sc-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tear_down() {
		foreach ( array( 'WORDPRESS_DB_NAME', 'WORDPRESS_DB_PASSWORD', 'WORDPRESS_DB_PASSWORD_FILE', 'WORDPRESS_TABLE_PREFIX' ) as $var ) {
			putenv( $var );
		}
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		chmod( $dir, 0755 );
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			is_dir( "$dir/$item" ) ? $this->rrmdir( "$dir/$item" ) : unlink( "$dir/$item" );
		}
		rmdir( $dir );
	}

	private function corpus( $name ) {
		return file_get_contents( DBDM_TEST_ROOT . '/tests/fixtures/wp-config/' . $name . '.php' );
	}

	private function corpus_names() {
		return array_map(
			function ( $f ) {
				return basename( $f, '.php' );
			},
			glob( DBDM_TEST_ROOT . '/tests/fixtures/wp-config/*.php' )
		);
	}

	private function write_config( $content, $name = 'wp-config.php' ) {
		file_put_contents( $this->dir . '/' . $name, $content );
		return $this->dir . '/' . $name;
	}

	private function parses( $code ) {
		return true === DBDM_Standalone_Config::php_lint_string( $code );
	}

	/**
	 * Valori di NAME nelle define non commentate.
	 */
	private function live_values( $code, $name ) {
		$out = array();
		foreach ( DBDM_Standalone_Config::find_defines( $code ) as $d ) {
			if ( $d['name'] === $name ) {
				$out[] = array( $d['simple'], $d['value'] );
			}
		}
		return $out;
	}

	/* --- Lettura delle credenziali (bug 10, 22–24) ------------------------ */

	public function test_credenziali_del_wp_config_standard(): void {
		$creds = DBDM_Standalone_Config::parse_credentials( $this->write_config( $this->corpus( 'standard' ) ) );

		$this->assertSame(
			array(
				'name'    => 'wordpress',
				'user'    => 'wp_user',
				'pass'    => 'S3cr3t!pass',
				'host'    => 'localhost',
				'charset' => 'utf8mb4',
				'prefix'  => 'wp_',
			),
			$creds
		);
	}

	public function test_credenziali_docker_dalle_variabili_d_ambiente(): void {
		putenv( 'WORDPRESS_DB_NAME=dal_container' );
		putenv( 'WORDPRESS_TABLE_PREFIX=env_' );

		$creds = DBDM_Standalone_Config::parse_credentials( $this->write_config( $this->corpus( 'docker' ) ) );

		$this->assertSame( 'dal_container', $creds['name'] );
		// Variabile assente: vale il default di getenv_docker().
		$this->assertSame( 'example username', $creds['user'] );
		$this->assertSame( 'mysql', $creds['host'] );
		$this->assertSame( 'utf8', $creds['charset'] );
		$this->assertSame( 'env_', $creds['prefix'] );
	}

	public function test_credenziali_docker_da_file_secret(): void {
		file_put_contents( $this->dir . '/secret', "dal-secret\n" );
		putenv( 'WORDPRESS_DB_PASSWORD_FILE=' . $this->dir . '/secret' );

		$creds = DBDM_Standalone_Config::parse_credentials( $this->write_config( $this->corpus( 'docker' ) ) );

		$this->assertSame( 'dal-secret', $creds['pass'] );
	}

	public function test_credenziali_con_commenti_e_valori_difficili(): void {
		$creds = DBDM_Standalone_Config::parse_credentials( $this->write_config( $this->corpus( 'edge-cases' ) ) );

		// Il define commentato con 'staging' non conta.
		$this->assertSame( 'produzione', $creds['name'] );
		// Solo \' e \\ sono escape in una stringa tra apici singoli.
		$this->assertSame( 'ab\cd\'e);f', $creds['pass'] );
		$this->assertSame( 'xyz_', $creds['prefix'] );
		// DB_CHARSET vuoto: il default di WordPress.
		$this->assertSame( 'utf8mb4', $creds['charset'] );
	}

	public function test_vince_la_prima_define_incondizionata(): void {
		$creds = DBDM_Standalone_Config::parse_credentials(
			$this->write_config(
				"<?php\nif ( \$staging ) { define( 'DB_NAME', 'staging' ); }\n"
				. "define( 'DB_NAME', 'prima' );\ndefine( 'DB_NAME', 'seconda' );\n"
				. "define( 'DB_USER', 'u' ); define( 'DB_PASSWORD', 'p' ); define( 'DB_HOST', 'h' );\n"
				. "\$table_prefix = 'a_';\n/* \$table_prefix = 'commentato_'; */\n\$table_prefix = 'ultimo_';\n"
			)
		);

		$this->assertSame( 'prima', $creds['name'] );
		// Per una variabile vince l'ultima assegnazione.
		$this->assertSame( 'ultimo_', $creds['prefix'] );
	}

	public function test_credenziali_non_valutabili(): void {
		$path = $this->write_config( "<?php\ndefine( 'DB_NAME', \"db_\$suffix\" );\ndefine( 'DB_USER', 'u' ); define( 'DB_PASSWORD', 'p' ); define( 'DB_HOST', 'h' );\n" );

		$this->assertFalse( DBDM_Standalone_Config::parse_credentials( $path ) );
		$this->assertFalse( DBDM_Standalone_Config::parse_credentials( $this->dir . '/assente.php' ) );
	}

	/**
	 * @dataProvider provide_host
	 */
	public function test_build_dsn( $host, $expected ): void {
		$this->assertSame(
			$expected . ';dbname=wp;charset=utf8mb4',
			DBDM_Standalone_Config::build_dsn(
				array(
					'host'    => $host,
					'name'    => 'wp',
					'charset' => '',
				)
			)
		);
	}

	public function provide_host() {
		return array(
			'host'           => array( 'db.example', 'mysql:host=db.example' ),
			'host e porta'   => array( 'db.example:3307', 'mysql:host=db.example;port=3307' ),
			'socket'         => array( 'localhost:/var/run/mysqld/mysqld.sock', 'mysql:unix_socket=/var/run/mysqld/mysqld.sock' ),
			'solo socket'    => array( '/tmp/mysql.sock', 'mysql:unix_socket=/tmp/mysql.sock' ),
			'ipv6 e porta'   => array( '[::1]:3306', 'mysql:host=::1;port=3306' ),
			'persistente'    => array( 'p:db.example', 'mysql:host=db.example' ),
		);
	}

	/* --- Scrittura (bug 1, 4, 5, 9) ---------------------------------------- */

	/**
	 * Su ogni file del corpus, ogni costante gestita e ogni valore: PHP
	 * valido, nessuna define commentata toccata, valore atteso in tutte le
	 * define vive e al più una incondizionata.
	 */
	public function test_corpus_ogni_costante_ogni_valore(): void {
		foreach ( $this->corpus_names() as $file ) {
			$original = $this->corpus( $file );
			foreach ( self::MANAGED as $const ) {
				foreach ( array( true, false ) as $value ) {
					$label = "$file / $const = " . var_export( $value, true );
					$out   = DBDM_Standalone_Config::replace_or_insert_constant( $original, $const, $value ? 'true' : 'false' );

					$this->assertTrue( $this->parses( $out ), $label );
					$live = $this->live_values( $out, $const );
					$this->assertNotEmpty( $live, $label );
					$this->assertLessThanOrEqual( 1, count( array_filter( array_column( $live, 0 ) ) ), $label );
					foreach ( $live as $define ) {
						$this->assertSame( $value, $define[1], $label );
					}
					// I commenti restano identici.
					preg_match_all( '#/\*.*?\*/|//[^\n]*|\#[^\n]*#s', $original, $before );
					foreach ( $before[0] as $comment ) {
						$this->assertStringContainsString( $comment, $out, $label );
					}
				}
			}
		}
	}

	public function test_blocco_commentato_non_viene_decommentato(): void {
		$code = "<?php\n/* define('WP_DEBUG', true);\n   define('WP_DEBUG_LOG', true); */\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n";

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $code, 'WP_DEBUG', 'false' );

		$this->assertTrue( $this->parses( $out ) );
		$this->assertStringContainsString( "/* define('WP_DEBUG', true);\n   define('WP_DEBUG_LOG', true); */", $out );
		$this->assertStringContainsString( "define( 'WP_DEBUG', false );\n\n/* That's all", $out );
	}

	public function test_commento_in_coda_conservato(): void {
		$out = DBDM_Standalone_Config::replace_or_insert_constant( "<?php\ndefine( 'WP_DEBUG', false ); // dall'hosting\n", 'WP_DEBUG', 'true' );

		$this->assertSame( "<?php\ndefine( 'WP_DEBUG', true ); // dall'hosting\n", $out );
	}

	public function test_define_condizionale_mantiene_la_condizione(): void {
		$code = "<?php\ndefined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', false );\nif ( ! defined( 'X' ) ) define( 'X', 1 ); else define( 'X', 2 );\n";

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $code, 'WP_DEBUG_LOG', 'true' );
		$out = DBDM_Standalone_Config::replace_or_insert_constant( $out, 'X', '3' );

		$this->assertSame( "<?php\ndefined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', true );\nif ( ! defined( 'X' ) ) define( 'X', 3 ); else define( 'X', 3 );\n", $out );
	}

	public function test_define_su_piu_righe(): void {
		$out = DBDM_Standalone_Config::replace_or_insert_constant( "<?php\ndefine(\n\t'SCRIPT_DEBUG',\n\tfalse\n);\n", 'SCRIPT_DEBUG', 'true' );

		$this->assertSame( "<?php\ndefine(\n\t'SCRIPT_DEBUG',\n\ttrue\n);\n", $out );
	}

	public function test_duplicati_incondizionati_rimossi(): void {
		// Lasciati dalle versioni precedenti: PHP usa il primo e avvisa.
		$code = "<?php\n// define('WP_DEBUG', true);\ndefine('WP_DEBUG', false);\n\ndefine('WP_DEBUG', true);\n/* That's all, stop editing! */\n";

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $code, 'WP_DEBUG', 'true' );

		$this->assertSame( "<?php\n// define('WP_DEBUG', true);\ndefine('WP_DEBUG', true);\n\n/* That's all, stop editing! */\n", $out );
	}

	public function test_inserimento_prima_di_that_s_all(): void {
		$out = DBDM_Standalone_Config::replace_or_insert_constant( $this->corpus( 'standard' ), 'SAVEQUERIES', 'true' );

		$this->assertStringContainsString( "define( 'SAVEQUERIES', true );\n\n/* That's all, stop editing!", $out );
	}

	public function test_inserimento_prima_del_require_senza_marker(): void {
		$code = "<?php\ndefine( 'DB_NAME', 'wp' );\n  require ABSPATH . 'wp-settings.php';\n";

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $code, 'WP_DEBUG', 'true' );

		$this->assertSame( "<?php\ndefine( 'DB_NAME', 'wp' );\ndefine( 'WP_DEBUG', true );\n\n  require ABSPATH . 'wp-settings.php';\n", $out );
	}

	public function test_inserimento_in_coda_senza_marker_ne_require(): void {
		$out = DBDM_Standalone_Config::replace_or_insert_constant( "<?php\ndefine( 'DB_NAME', 'wp' );\n", 'WP_DEBUG', 'true' );

		$this->assertSame( "<?php\ndefine( 'DB_NAME', 'wp' );\n\ndefine( 'WP_DEBUG', true );\n", $out );
	}

	public function test_crlf_conservati(): void {
		$code = "<?php\r\ndefine( 'DB_NAME', 'wp' );\r\n/* That's all, stop editing! */\r\n";

		$out = DBDM_Standalone_Config::replace_or_insert_constant( $code, 'WP_DEBUG', 'true' );

		$this->assertSame( "<?php\r\ndefine( 'DB_NAME', 'wp' );\r\ndefine( 'WP_DEBUG', true );\r\n\r\n/* That's all, stop editing! */\r\n", $out );
	}

	public function test_nome_della_costante_sensibile_alle_maiuscole(): void {
		$out = DBDM_Standalone_Config::replace_or_insert_constant( "<?php\ndefine( 'wp_debug', 1 );\n", 'WP_DEBUG', 'true' );

		$this->assertStringContainsString( "define( 'wp_debug', 1 );", $out );
		$this->assertStringContainsString( "define( 'WP_DEBUG', true );", $out );
	}

	public function test_lint_nel_processo(): void {
		$this->assertTrue( DBDM_Standalone_Config::php_lint_string( "<?php\ndefine( 'A', true );\n" ) );
		$this->assertStringContainsString( 'riga 2', DBDM_Standalone_Config::php_lint_string( "<?php\ndefine( 'A', true;\n" ) );
	}

	/* --- set_constants: backup e scrittura (bug 2, 3) ---------------------- */

	public function test_un_solo_backup_dello_stato_originale(): void {
		$original = $this->corpus( 'standard' );
		$path     = $this->write_config( $original );
		chmod( $path, 0640 );

		$result = DBDM_Standalone_Config::set_constants(
			$path,
			array(
				'WP_DEBUG'     => 'true',
				'WP_DEBUG_LOG' => 'true',
				'SAVEQUERIES'  => 'true',
			),
			$this->dir . '/backup.bak'
		);

		$this->assertTrue( $result );
		$this->assertSame( $original, file_get_contents( $this->dir . '/backup.bak' ) );
		$this->assertSame( array( 'WP_DEBUG' => true, 'WP_DEBUG_LOG' => true, 'SAVEQUERIES' => true ), array_intersect_key( DBDM_Standalone_Config::effective_defines( file_get_contents( $path ) ), array_flip( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'SAVEQUERIES' ) ) ) );
		clearstatcache();
		$this->assertSame( 0640, fileperms( $path ) & 0777 );
		// Nessun file temporaneo rimasto.
		$this->assertSame( array( 'backup.bak', 'wp-config.php' ), array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) ) );
	}

	public function test_nessuna_modifica_nessun_backup(): void {
		$path = $this->write_config( $this->corpus( 'standard' ) );

		$this->assertTrue( DBDM_Standalone_Config::set_constants( $path, array( 'WP_DEBUG' => 'false' ), $this->dir . '/backup.bak' ) );
		$this->assertFileDoesNotExist( $this->dir . '/backup.bak' );
	}

	public function test_backup_impossibile_blocca_la_scrittura(): void {
		$original = $this->corpus( 'standard' );
		$path     = $this->write_config( $original );
		mkdir( $this->dir . '/privata', 0555 );

		$result = DBDM_Standalone_Config::set_constants( $path, array( 'WP_DEBUG' => 'true' ), $this->dir . '/privata/backup.bak' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Backup', $result );
		$this->assertSame( $original, file_get_contents( $path ) );
	}

	public function test_errore_di_sintassi_non_scrive_nulla(): void {
		$original = $this->corpus( 'standard' );
		$path     = $this->write_config( $original );

		$result = DBDM_Standalone_Config::set_constants( $path, array( 'WP_DEBUG' => 'true;' ), $this->dir . '/backup.bak' );

		$this->assertStringContainsString( 'sintassi', $result );
		$this->assertSame( $original, file_get_contents( $path ) );
		$this->assertFileDoesNotExist( $this->dir . '/backup.bak' );
	}

	public function test_cartella_non_scrivibile_scrittura_in_place(): void {
		$path = $this->write_config( $this->corpus( 'standard' ) );
		chmod( $this->dir, 0555 );

		$result = DBDM_Standalone_Config::set_constants( $path, array( 'WP_DEBUG' => 'true' ) );

		chmod( $this->dir, 0755 );
		$this->assertTrue( $result );
		$this->assertTrue( DBDM_Standalone_Config::effective_defines( file_get_contents( $path ) )['WP_DEBUG'] );
	}

	public function test_file_non_scrivibile(): void {
		$path = $this->write_config( $this->corpus( 'standard' ) );
		chmod( $path, 0444 );

		$this->assertSame( 'wp-config.php non scrivibile.', DBDM_Standalone_Config::set_constants( $path, array( 'WP_DEBUG' => 'true' ) ) );
	}

	public function test_set_bool_constant_compatibile(): void {
		$path = $this->write_config( $this->corpus( 'standard' ) );

		$this->assertTrue( DBDM_Standalone_Config::set_bool_constant( $path, 'SAVEQUERIES', true ) );
		$this->assertTrue( DBDM_Standalone_Config::effective_defines( file_get_contents( $path ) )['SAVEQUERIES'] );
	}

	/* --- Ricerca di wp-config.php (bug 25) --------------------------------- */

	public function test_find_wp_config(): void {
		$root = $this->dir . '/sito';
		mkdir( "$root/wp/wp-content/plugins/db-debug-manager", 0755, true );
		touch( "$root/wp/wp-settings.php" );
		$plugin = "$root/wp/wp-content/plugins/db-debug-manager";

		$this->assertFalse( DBDM_Standalone_Config::find_wp_config( $plugin ) );

		// Un livello sopra ABSPATH (configurazione supportata da WordPress).
		touch( "$root/wp-config.php" );
		$this->assertSame( "$root/wp-config.php", DBDM_Standalone_Config::find_wp_config( $plugin ) );

		// Nella root di WordPress ha la precedenza.
		touch( "$root/wp/wp-config.php" );
		$this->assertSame( "$root/wp/wp-config.php", DBDM_Standalone_Config::find_wp_config( $plugin ) );
	}

	public function test_find_wp_config_non_sale_oltre_un_altra_installazione(): void {
		$root = $this->dir . '/sito';
		mkdir( "$root/wp/wp-content/plugins/db-debug-manager", 0755, true );
		touch( "$root/wp/wp-settings.php" );
		touch( "$root/wp-config.php" );
		touch( "$root/wp-settings.php" ); // Un'altra installazione sopra.

		$this->assertFalse( DBDM_Standalone_Config::find_wp_config( "$root/wp/wp-content/plugins/db-debug-manager" ) );
	}
}
