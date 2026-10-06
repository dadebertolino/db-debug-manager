<?php
/**
 * wp-config.php dell'immagine Docker ufficiale "wordpress" (e quindi di
 * wp-env): credenziali lette dalle variabili d'ambiente con getenv_docker().
 * Corpus dei test di DB Debug Manager (bug 10 del piano).
 */

if ( ! function_exists( 'getenv_docker' ) ) {
	// https://github.com/docker-library/wordpress/issues/588 (WP-CLI will load this file 2x)
	function getenv_docker( $env, $default ) {
		if ( $fileEnv = getenv( $env . '_FILE' ) ) {
			return rtrim( file_get_contents( $fileEnv ), "\r\n" );
		} elseif ( ( $val = getenv( $env ) ) !== false ) {
			return $val;
		} else {
			return $default;
		}
	}
}

define( 'DB_NAME', getenv_docker( 'WORDPRESS_DB_NAME', 'wordpress' ) );
define( 'DB_USER', getenv_docker( 'WORDPRESS_DB_USER', 'example username' ) );
define( 'DB_PASSWORD', getenv_docker( 'WORDPRESS_DB_PASSWORD', 'example password' ) );
define( 'DB_HOST', getenv_docker( 'WORDPRESS_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', getenv_docker( 'WORDPRESS_DB_CHARSET', 'utf8' ) );
define( 'DB_COLLATE', getenv_docker( 'WORDPRESS_DB_COLLATE', '' ) );

$table_prefix = getenv_docker( 'WORDPRESS_TABLE_PREFIX', 'wp_' );

define( 'WP_DEBUG', !!getenv_docker( 'WORDPRESS_DEBUG', '' ) );

if ( $configExtra = getenv_docker( 'WORDPRESS_CONFIG_EXTRA', '' ) ) {
	eval( $configExtra );
}

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
