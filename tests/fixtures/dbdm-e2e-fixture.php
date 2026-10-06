<?php
/**
 * Plugin Name: DBDM E2E Fixture
 * Description: Condizioni di test deterministiche per gli E2E di DB Debug
 *              Manager. Attivo SOLO in ambiente wp-env (mu-plugin). NON fa
 *              parte del pacchetto distribuito.
 *
 * Il Debug Manager scrive wp-config.php, debug.log e una cartella privata:
 * ogni test deve partire da uno stato noto e non lasciare tracce. La fixture:
 *
 *  - REST POST /dbdm-e2e/v1/reset → ripristina wp-config.php dalla copia
 *    "dorata" presa al primo reset (lo stato in cui wp-env lo ha generato),
 *    cancella opzioni e transient del plugin, debug.log e cartella privata,
 *    e prepara su richiesta l'accesso d'emergenza (vedi dbdm_e2e_reset()).
 *  - REST GET  /dbdm-e2e/v1/state → stato lato server per le asserzioni:
 *    contenuto di wp-config.php, valori EFFETTIVI delle costanti (letti da
 *    una richiesta separata, perché quelle della richiesta corrente sono già
 *    definite), permessi, estensioni, cartella privata, opzioni.
 *  - GET /?dbdm_e2e_constants=1 → JSON delle costanti di debug di QUESTA
 *    richiesta (usato dallo stato tramite una richiesta interna).
 *
 * @package DBDM\Tests\Fixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DBDM_E2E_PASSWORD   = 'Emergenza-E2E-2026';
const DBDM_E2E_DIR_TOKEN  = '0123456789abcdef';
const DBDM_E2E_CONSTANTS  = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES' );

/**
 * Costanti di debug della richiesta corrente, per la lettura "dall'esterno".
 */
/**
 * Un avviso PHP su richiesta: finisce nel debug.log attivo (per verificare
 * dove WordPress scrive il log).
 */
if ( isset( $_GET['dbdm_e2e_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	add_action(
		'init',
		function () {
			trigger_error( 'DBDM E2E avviso di prova', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			exit( 'ok' );
		}
	);
}

if ( isset( $_GET['dbdm_e2e_constants'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$dbdm_e2e_values = array();
	foreach ( DBDM_E2E_CONSTANTS as $dbdm_e2e_name ) {
		$dbdm_e2e_values[ $dbdm_e2e_name ] = defined( $dbdm_e2e_name ) ? constant( $dbdm_e2e_name ) : null;
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $dbdm_e2e_values );
	exit;
}

/**
 * Percorsi usati dalla fixture.
 *
 * @return array{config:string,golden:string,log:string,plugin:string}
 */
function dbdm_e2e_paths() {
	return array(
		'config' => ABSPATH . 'wp-config.php',
		'golden' => WP_CONTENT_DIR . '/dbdm-e2e/golden-wp-config.php',
		'log'    => WP_CONTENT_DIR . '/debug.log',
		'plugin' => WP_PLUGIN_DIR . '/db-debug-manager',
	);
}

/**
 * Copia dorata di wp-config.php: presa una volta, al primo reset dopo
 * l'avvio di wp-env. La cartella è protetta come quella del plugin.
 *
 * @return string Contenuto dorato.
 */
function dbdm_e2e_golden() {
	$paths = dbdm_e2e_paths();
	if ( ! file_exists( $paths['golden'] ) ) {
		wp_mkdir_p( dirname( $paths['golden'] ) );
		file_put_contents( dirname( $paths['golden'] ) . '/.htaccess', "Require all denied\n" );
		file_put_contents( dirname( $paths['golden'] ) . '/index.php', "<?php\n// Silence is golden.\n" );
		copy( $paths['config'], $paths['golden'] );
	}
	return (string) file_get_contents( $paths['golden'] );
}

/**
 * Varianti di wp-config.php ricavate dalla copia dorata.
 *
 *  - golden:  come l'ha scritto wp-env (credenziali con getenv_docker);
 *  - literal: credenziali scritte come stringhe, come su un hosting comune;
 *  - hosting: define con commento in coda, define condizionale, blocco
 *             commentato con define (bug 1 e 4 del piano);
 *  - public_log: WP_DEBUG e WP_DEBUG_LOG = true (log in wp-content).
 *
 * Le varianti del corpus (commenti, condizionali, CRLF…) si aggiungono qui
 * nelle fasi successive.
 *
 * @param string $variant
 * @return string|WP_Error
 */
function dbdm_e2e_wp_config( $variant ) {
	$golden = dbdm_e2e_golden();
	switch ( $variant ) {
		case 'golden':
			return $golden;
		case 'literal':
			foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $name ) {
				$golden = preg_replace(
					"/define\(\s*'{$name}',\s*getenv_docker\(.*\)\s*\);/",
					"define( '{$name}', " . var_export( constant( $name ), true ) . ' );',
					$golden
				);
			}
			global $table_prefix;
			return preg_replace(
				'/\$table_prefix\s*=\s*getenv_docker\(.*\);/',
				'$table_prefix = ' . var_export( $table_prefix, true ) . ';',
				$golden
			);
		case 'public_log':
			// Sito con il log pubblico di WordPress (wp-content/debug.log).
			$golden = preg_replace( "/define\(\s*'WP_DEBUG',\s*false\s*\);/", "define( 'WP_DEBUG', true );", $golden, 1 );
			return preg_replace( "/define\(\s*'WP_DEBUG_LOG',\s*false\s*\);/", "define( 'WP_DEBUG_LOG', true );", $golden, 1 );
		case 'hosting':
			$golden = preg_replace( "/define\(\s*'WP_DEBUG',\s*false\s*\);/", "define( 'WP_DEBUG', false ); // impostato dall'hosting", $golden, 1 );
			$golden = preg_replace( "/define\(\s*'SCRIPT_DEBUG',\s*false\s*\);/", "defined( 'SCRIPT_DEBUG' ) || define( 'SCRIPT_DEBUG', false );", $golden, 1 );
			return str_replace(
				"/* That's all, stop editing!",
				"/* define( 'WP_DEBUG', true );\n   define( 'SAVEQUERIES', true ); */\n\n/* That's all, stop editing!",
				$golden
			);
	}
	return new WP_Error( 'dbdm_e2e_variant', 'Variante di wp-config sconosciuta: ' . $variant, array( 'status' => 400 ) );
}

/**
 * Cartelle private del plugin (posizione attuale e future).
 *
 * @return string[]
 */
function dbdm_e2e_private_dirs() {
	$paths = dbdm_e2e_paths();
	return array_merge(
		glob( $paths['plugin'] . '/private*', GLOB_ONLYDIR ) ?: array(),
		glob( WP_CONTENT_DIR . '/dbdm-private*', GLOB_ONLYDIR ) ?: array()
	);
}

/**
 * Cancella una cartella e il suo contenuto (file nascosti compresi).
 *
 * @param string $dir
 */
function dbdm_e2e_rmdir( $dir ) {
	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? dbdm_e2e_rmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

/**
 * Riporta il sito a uno stato noto.
 *
 * $args (tutti opzionali):
 *  - wp_config  string  Variante di wp-config.php (default 'golden').
 *  - emergency  bool|array  Accesso d'emergenza pronto all'uso: password
 *               DBDM_E2E_PASSWORD, abilitato, token della cartella privata
 *               fisso. Come array accetta `password`, `enabled`, `trust_proxy`.
 *  - log        string  Contenuto iniziale di debug.log (default: assente).
 *  - snapshots  array   Contenuto di snapshots.json nella cartella privata
 *               (richiede emergency, che la crea).
 *  - themes     string[] Temi di prova da creare: 'orfano' (child theme il
 *               cui padre non esiste). Rimossi a ogni reset.
 *
 * @param array $args
 * @return array|WP_Error Stato risultante (vedi dbdm_e2e_state()).
 */
function dbdm_e2e_reset( $args = array() ) {
	global $wpdb;

	$args  = is_array( $args ) ? $args : array();
	$paths = dbdm_e2e_paths();

	// wp-config.php.
	$config = dbdm_e2e_wp_config( isset( $args['wp_config'] ) ? (string) $args['wp_config'] : 'golden' );
	if ( is_wp_error( $config ) ) {
		return $config;
	}
	if ( false === file_put_contents( $paths['config'], $config ) ) {
		return new WP_Error( 'dbdm_e2e_config', 'wp-config.php non scrivibile dal server web.', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $paths['config'], true );
	}

	// Opzioni e transient del plugin (anche quelli dell'updater).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'dbdm\\_%'
		    OR option_name LIKE '\\_transient\\_dbdm\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_dbdm\\_%'"
	);
	wp_cache_flush();

	// debug.log e cartelle private (backup, snapshot, log e rate limit
	// dell'emergency).
	if ( file_exists( $paths['log'] ) ) {
		unlink( $paths['log'] );
	}
	if ( isset( $args['log'] ) ) {
		file_put_contents( $paths['log'], (string) $args['log'] );
	}
	foreach ( dbdm_e2e_private_dirs() as $dir ) {
		dbdm_e2e_rmdir( $dir );
	}

	// Plugin attivo (uno spec può averlo disattivato).
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! is_plugin_active( 'db-debug-manager/db-debug-manager.php' ) ) {
		activate_plugin( 'db-debug-manager/db-debug-manager.php' );
	}

	// Temi di prova.
	foreach ( glob( get_theme_root() . '/dbdm-e2e-*', GLOB_ONLYDIR ) ?: array() as $dir ) {
		dbdm_e2e_rmdir( $dir );
	}
	if ( isset( $args['themes'] ) && in_array( 'orfano', (array) $args['themes'], true ) ) {
		wp_mkdir_p( get_theme_root() . '/dbdm-e2e-orfano' );
		file_put_contents( get_theme_root() . '/dbdm-e2e-orfano/style.css', "/*\nTheme Name: DBDM E2E Orfano\nTemplate: dbdm-e2e-padre-cancellato\n*/\n" );
	}

	// Accesso d'emergenza.
	if ( ! empty( $args['emergency'] ) ) {
		$em = is_array( $args['emergency'] ) ? $args['emergency'] : array();
		update_option( 'dbdm_private_dir_token', DBDM_E2E_DIR_TOKEN, false );
		update_option( 'dbdm_emergency_hash', password_hash( isset( $em['password'] ) ? (string) $em['password'] : DBDM_E2E_PASSWORD, PASSWORD_DEFAULT ), false );
		update_option( 'dbdm_emergency_enabled', array_key_exists( 'enabled', $em ) ? ( $em['enabled'] ? '1' : '0' ) : '1', false );
		update_option( 'dbdm_emergency_trust_proxy', ! empty( $em['trust_proxy'] ) ? '1' : '0', false );
		update_option( 'dbdm_emergency_epoch', 'e2e-' . wp_rand(), false );
		// Crea la cartella privata (e ne salva il percorso) come il pannello.
		$private = DBDM_Emergency::private_dir();
		if ( isset( $args['snapshots'] ) && is_array( $args['snapshots'] ) ) {
			file_put_contents( $private . 'snapshots.json', wp_json_encode( $args['snapshots'] ) );
		}
	}

	return dbdm_e2e_state();
}

/**
 * Costanti effettive lette da una richiesta interna (nuovo processo PHP,
 * quindi wp-config.php riletto). Il container risponde su 127.0.0.1:80;
 * l'host del sito arriva nell'header.
 *
 * @return array|null
 */
function dbdm_e2e_effective_constants() {
	$home     = wp_parse_url( home_url() );
	$host     = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	$response = wp_remote_get(
		'http://127.0.0.1/?dbdm_e2e_constants=1&nocache=' . wp_rand(),
		array(
			'headers' => array( 'Host' => $host ),
			'timeout' => 10,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'error' => $response->get_error_message() );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : array( 'error' => 'Risposta non JSON: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
}

/**
 * Stato lato server per le asserzioni.
 *
 * @return array
 */
function dbdm_e2e_state() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$paths = dbdm_e2e_paths();

	$private = array();
	foreach ( dbdm_e2e_private_dirs() as $dir ) {
		$files = array();
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$files[ $item ] = substr( sprintf( '%o', fileperms( $dir . '/' . $item ) ), -4 );
			}
		}
		$private[ str_replace( WP_CONTENT_DIR, 'wp-content', $dir ) ] = $files;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'dbdm\\_%'", OBJECT_K );

	$private_dir = (string) get_option( 'dbdm_private_dir_path', '' );
	$backup      = $private_dir ? $private_dir . '/wp-config.dbdm-bak' : '';

	return array(
		'wp_config'          => (string) file_get_contents( $paths['config'] ),
		'golden_sha1'        => file_exists( $paths['golden'] ) ? sha1_file( $paths['golden'] ) : null,
		'backup_sha1'        => $backup && file_exists( $backup ) ? sha1_file( $backup ) : null,
		'private_dir'        => $private_dir ? str_replace( WP_CONTENT_DIR, 'wp-content', $private_dir ) : '',
		'plugin_active'      => is_plugin_active( 'db-debug-manager/db-debug-manager.php' ),
		'wp_config_writable' => is_writable( $paths['config'] ),
		'plugin_writable'    => is_writable( $paths['plugin'] ),
		'content_writable'   => is_writable( WP_CONTENT_DIR ),
		'pdo_mysql'          => extension_loaded( 'pdo_mysql' ),
		'php'                => PHP_VERSION,
		'constants'          => dbdm_e2e_effective_constants(),
		'debug_log'          => file_exists( $paths['log'] ) ? filesize( $paths['log'] ) : null,
		// Oggetti anche se vuoti: in JSON {} e non [].
		'private'            => (object) $private,
		'options'            => (object) array_map(
			function ( $row ) {
				return $row->option_value;
			},
			$options
		),
	);
}

/**
 * Endpoint REST. Senza autenticazione di proposito: il mu-plugin è montato
 * solo da .wp-env.json e non esiste nel pacchetto distribuito. Usare
 * ?rest_route= nei test, così non dipende dai permalink.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'dbdm-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					return rest_ensure_response( dbdm_e2e_reset( (array) $request->get_json_params() ) );
				},
			)
		);
		// Cambio password dell'emergency come dal pannello (nuova epoca).
		register_rest_route(
			'dbdm-e2e/v1',
			'/emergency-password',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					$result = DBDM_Emergency::set_password( (string) $request->get_param( 'password' ) );
					if ( null !== $request->get_param( 'enabled' ) ) {
						DBDM_Emergency::set_enabled( (bool) $request->get_param( 'enabled' ) );
					}
					// Come dopo un aggiornamento via FTP: cartella privata mai creata.
					if ( $request->get_param( 'drop_private' ) ) {
						foreach ( dbdm_e2e_private_dirs() as $dir ) {
							dbdm_e2e_rmdir( $dir );
						}
						delete_option( 'dbdm_private_dir_path' );
						delete_option( 'dbdm_private_dir_token' );
					}
					return rest_ensure_response( $result );
				},
			)
		);
		// Disattivazione del plugin come dalla pagina Plugin (hook compresi).
		register_rest_route(
			'dbdm-e2e/v1',
			'/deactivate',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					deactivate_plugins( 'db-debug-manager/db-debug-manager.php' );
					return rest_ensure_response( dbdm_e2e_state() );
				},
			)
		);
		register_rest_route(
			'dbdm-e2e/v1',
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return rest_ensure_response( dbdm_e2e_state() );
				},
			)
		);
	}
);
