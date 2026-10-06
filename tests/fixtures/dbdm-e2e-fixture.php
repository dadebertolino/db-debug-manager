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
 *  - literal: credenziali scritte come stringhe, come su un hosting comune.
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
			return $golden;
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

	// Accesso d'emergenza.
	if ( ! empty( $args['emergency'] ) ) {
		$em = is_array( $args['emergency'] ) ? $args['emergency'] : array();
		update_option( 'dbdm_private_dir_token', DBDM_E2E_DIR_TOKEN, false );
		update_option( 'dbdm_emergency_hash', password_hash( isset( $em['password'] ) ? (string) $em['password'] : DBDM_E2E_PASSWORD, PASSWORD_DEFAULT ), false );
		update_option( 'dbdm_emergency_enabled', array_key_exists( 'enabled', $em ) ? ( $em['enabled'] ? '1' : '0' ) : '1', false );
		update_option( 'dbdm_emergency_trust_proxy', ! empty( $em['trust_proxy'] ) ? '1' : '0', false );
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

	return array(
		'wp_config'          => (string) file_get_contents( $paths['config'] ),
		'wp_config_writable' => is_writable( $paths['config'] ),
		'plugin_writable'    => is_writable( $paths['plugin'] ),
		'content_writable'   => is_writable( WP_CONTENT_DIR ),
		'pdo_mysql'          => extension_loaded( 'pdo_mysql' ),
		'php'                => PHP_VERSION,
		'constants'          => dbdm_e2e_effective_constants(),
		'debug_log'          => file_exists( $paths['log'] ) ? filesize( $paths['log'] ) : null,
		'private'            => $private,
		'options'            => array_map(
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
