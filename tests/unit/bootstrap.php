<?php
/**
 * Bootstrap dei test UNIT.
 *
 * La logica del Debug Manager che non tocca WordPress (parser e writer di
 * wp-config.php, coda del log, diff degli snapshot) è PHP puro; il resto usa
 * un piccolo insieme di funzioni WordPress (option, filtri, sanitize/escape,
 * i18n). Le stubbiamo qui così il job unit resta leggero: niente MySQL,
 * niente WP test suite. Ciò che richiede WordPress vero (scrittura reale di
 * wp-config.php, aggiornamenti, multisite) sta negli integration test e
 * negli E2E.
 *
 * Gli stub dei filtri rispettano priorità e numero di argomenti, come in
 * WordPress.
 *
 * @package DBDM\Tests
 */

// Marcatore per i file del plugin che controllano ABSPATH.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Percorso dei sorgenti del plugin (root del repo).
define( 'DBDM_TEST_ROOT', dirname( __DIR__, 2 ) );

foreach ( array(
	'DBDM_VERSION'     => 'test',
	'DBDM_PLUGIN_FILE' => DBDM_TEST_ROOT . '/db-debug-manager.php',
	'DBDM_PLUGIN_DIR'  => DBDM_TEST_ROOT . '/',
	'DBDM_PLUGIN_URL'  => 'https://debug.example/wp-content/plugins/db-debug-manager/',
	'DBDM_SLUG'        => 'db-debug-manager',
) as $dbdm_const => $dbdm_value ) {
	if ( ! defined( $dbdm_const ) ) {
		define( $dbdm_const, $dbdm_value );
	}
}
// Con WP_DEBUG i moduli segnalano i contratti violati con _doing_it_wrong,
// che lo stub registra in $GLOBALS['__dbdm_doing_it_wrong']: i test possono
// verificare che il plugin da correggere venga indicato.
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'YEAR_IN_SECONDS'   => 31536000,
) as $dbdm_const => $dbdm_value ) {
	if ( ! defined( $dbdm_const ) ) {
		define( $dbdm_const, $dbdm_value );
	}
}

/* -----------------------------------------------------------------------------
 * Stato globale simulato: option table, transient, filtri/azioni, chiamate
 * a _doing_it_wrong. Azzerato da dbdm_test_reset() fra un test e l'altro.
 * -------------------------------------------------------------------------- */

$GLOBALS['__dbdm_options']       = array();
$GLOBALS['__dbdm_transients']    = array();
$GLOBALS['__dbdm_filters']       = array();
$GLOBALS['__dbdm_doing_it_wrong'] = array();

/* --- Option e transient --------------------------------------------------- */

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__dbdm_options'] )
			? $GLOBALS['__dbdm_options'][ $key ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__dbdm_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__dbdm_options'] ) ) {
			return false;
		}
		$GLOBALS['__dbdm_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['__dbdm_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['__dbdm_transients'] )
			? $GLOBALS['__dbdm_transients'][ $key ]
			: false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['__dbdm_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__dbdm_transients'][ $key ] );
		return true;
	}
}

/* --- Filtri e azioni (priorità e accepted_args come in WordPress) --------- */

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__dbdm_filters'][ $hook ][ (int) $priority ][] = array( $cb, (int) $accepted_args );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $cb, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $cb, $priority = 10 ) {
		if ( empty( $GLOBALS['__dbdm_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['__dbdm_filters'][ $hook ][ $priority ] as $i => $entry ) {
			if ( $entry[0] === $cb ) {
				unset( $GLOBALS['__dbdm_filters'][ $hook ][ $priority ][ $i ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $cb, $priority = 10 ) {
		return remove_filter( $hook, $cb, $priority );
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $cb = false ) {
		if ( empty( $GLOBALS['__dbdm_filters'][ $hook ] ) ) {
			return false;
		}
		if ( false === $cb ) {
			return true;
		}
		foreach ( $GLOBALS['__dbdm_filters'][ $hook ] as $priority => $entries ) {
			foreach ( $entries as $entry ) {
				if ( $entry[0] === $cb ) {
					return $priority;
				}
			}
		}
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$args = array_slice( func_get_args(), 1 );
		if ( empty( $GLOBALS['__dbdm_filters'][ $hook ] ) ) {
			return $value;
		}
		$by_priority = $GLOBALS['__dbdm_filters'][ $hook ];
		ksort( $by_priority );
		foreach ( $by_priority as $entries ) {
			foreach ( $entries as $entry ) {
				$args[0] = call_user_func_array( $entry[0], array_slice( $args, 0, max( 1, $entry[1] ) ) );
			}
		}
		return $args[0];
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {
		$args = array_slice( func_get_args(), 1 );
		if ( empty( $GLOBALS['__dbdm_filters'][ $hook ] ) ) {
			return;
		}
		$by_priority = $GLOBALS['__dbdm_filters'][ $hook ];
		ksort( $by_priority );
		foreach ( $by_priority as $entries ) {
			foreach ( $entries as $entry ) {
				call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
			}
		}
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames -- stesso nome di WordPress.
		return true;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames -- stesso nome di WordPress.
		return false;
	}
}
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function, $message, $version ) {
		$GLOBALS['__dbdm_doing_it_wrong'][] = array( $function, $message, $version );
	}
}

/* --- i18n ----------------------------------------------------------------- */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

/* --- Sanitize ed escape --------------------------------------------------- */

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		if ( is_array( $str ) || is_object( $str ) ) {
			return '';
		}
		$str = wp_strip_all_tags( (string) $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		if ( is_array( $str ) || is_object( $str ) ) {
			return '';
		}
		return wp_strip_all_tags( (string) $str );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str, $remove_breaks = false ) {
		$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
		$str = strip_tags( $str );
		if ( $remove_breaks ) {
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		}
		return trim( $str );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) {
		return esc_html( $t );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $t, $d = 'default' ) {
		return esc_attr( $t );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		$u = esc_url_raw( $u );
		return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) {
		$u = trim( (string) $u );
		// Stub minimale: accetta http/https/mailto, come i protocolli WP di default.
		if ( '' === $u || ! preg_match( '#^(https?://|mailto:)#i', $u ) ) {
			return '';
		}
		return $u;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		return preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $html );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}

/* --- Sito, date, ambiente ------------------------------------------------- */

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://debug.example' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return home_url( $path );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $key = 'name' ) {
		return 'version' === $key ? '6.6' : 'Sito Di Test';
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$tz = get_option( 'timezone_string' );
		return new DateTimeZone( $tz ? $tz : 'UTC' );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		$now = new DateTimeImmutable( 'now', $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone() );
		if ( 'timestamp' === $type || 'U' === $type ) {
			return $gmt ? $now->getTimestamp() : $now->getTimestamp() + $now->getOffset();
		}
		return $now->format( 'mysql' === $type ? 'Y-m-d H:i:s' : $type );
	}
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $ts = false, $gmt = false ) {
		return gmdate( $format ? $format : 'Y-m-d', false === $ts ? time() : (int) $ts );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null, $tz = null ) {
		$dt = new DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) );
		return $dt->setTimezone( $tz ? $tz : wp_timezone() )->format( $format );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'dbdm-test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['__dbdm_is_admin'] );
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
	}
}

/* --- Post e tipi di contenuto (store minimale) ----------------------------- */

$GLOBALS['__dbdm_posts']      = array();
$GLOBALS['__dbdm_post_types'] = array( 'post', 'page' );

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $type ) {
		return in_array( $type, $GLOBALS['__dbdm_post_types'], true );
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array() ) {
		return array_combine( $GLOBALS['__dbdm_post_types'], $GLOBALS['__dbdm_post_types'] );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return isset( $GLOBALS['__dbdm_posts'][ (int) $id ] ) ? $GLOBALS['__dbdm_posts'][ (int) $id ] : null;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return array();
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $single ? '' : array();
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $id ) {
		$post = get_post( $id );
		return $post && 'revision' === $post->post_type && false === strpos( $post->post_name, 'autosave' ) ? (int) $post->post_parent : false;
	}
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $id ) {
		$post = get_post( $id );
		return $post && 'revision' === $post->post_type && false !== strpos( $post->post_name, 'autosave' ) ? (int) $post->post_parent : false;
	}
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		return is_array( $data ) ? array_map( 'esc_sql', $data ) : addslashes( (string) $data );
	}
}

/**
 * Registra un post finto nello store (get_post, revisioni, autosalvataggi).
 *
 * @param int   $id
 * @param array $fields post_type, post_status, post_name, post_parent.
 * @return object
 */
function dbdm_test_add_post( $id, array $fields = array() ) {
	$post = (object) array_merge(
		array(
			'ID'          => (int) $id,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_name'   => 'post-' . $id,
			'post_parent' => 0,
		),
		$fields
	);
	$GLOBALS['__dbdm_posts'][ (int) $id ] = $post;
	return $post;
}

/* -----------------------------------------------------------------------------
 * Helper per i test.
 * -------------------------------------------------------------------------- */

/**
 * Reset dello stato globale fra un test e l'altro: option, transient, filtri,
 * _doing_it_wrong e le cache statiche delle classi del plugin.
 */
function dbdm_test_reset() {
	$GLOBALS['__dbdm_options']        = array();
	$GLOBALS['__dbdm_transients']     = array();
	$GLOBALS['__dbdm_filters']        = array();
	$GLOBALS['__dbdm_doing_it_wrong'] = array();
	$GLOBALS['__dbdm_is_admin']       = false;
	$GLOBALS['__dbdm_posts']          = array();
	$GLOBALS['__dbdm_post_types']     = array( 'post', 'page' );

	// Cache statiche delle classi: in produzione durano una richiesta, qui
	// vanno svuotate a ogni test. Le classi le aggiungono qui man mano.
	foreach ( array() as $class => $property ) {
		if ( property_exists( $class, $property ) ) {
			dbdm_test_set_static( $class, $property, null );
		}
	}
}

/**
 * Invoca un metodo privato/protetto statico via Reflection. Serve a testare
 * logica non pubblica (es. format_value) senza cambiarne la visibilità.
 *
 * @param string $class
 * @param string $method
 * @param array  $args
 * @return mixed
 */
function dbdm_test_call_private( $class, $method, array $args = array() ) {
	$ref = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	return $ref->invokeArgs( null, $args );
}

/**
 * Imposta una proprietà statica privata (cache delle classi).
 *
 * @param string $class
 * @param string $property
 * @param mixed  $value
 */
function dbdm_test_set_static( $class, $property, $value ) {
	$ref = new ReflectionProperty( $class, $property );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	$ref->setValue( null, $value );
}

// Carica i sorgenti sotto test: le classi sono solo definizioni, nessun
// codice viene eseguito al require. emergency.php e db-debug-manager.php no:
// eseguono codice al caricamento.
foreach ( glob( DBDM_TEST_ROOT . '/inc/class-*.php' ) as $dbdm_file ) {
	require_once $dbdm_file;
}

// Autoload Composer per PHPUnit e polyfill.
require_once DBDM_TEST_ROOT . '/vendor/autoload.php';
