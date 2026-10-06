<?php
/**
 * wp-config.php "da hosting": tutti i casi che il parser e il writer di
 * DB Debug Manager devono gestire (bug 1, 4, 22, 23 del piano).
 */

/*
 * Blocco commentato con define: non vanno mai letti né "decommentati".
 * define('WP_DEBUG', true);
 * define('DB_NAME', 'staging');
 */
/* define('WP_DEBUG_LOG', true);
   define('SCRIPT_DEBUG', true); */
// define('WP_DEBUG_DISPLAY', true);
# define('SAVEQUERIES', true);

define( 'DB_NAME', 'produzione' );
define( 'DB_USER', 'utente' );
define( 'DB_PASSWORD', 'ab\cd\'e);f' ); // backslash, apice e ");" nel valore
define( 'DB_HOST', 'localhost:/var/run/mysqld/mysqld.sock' );
define( 'DB_CHARSET', '' );

$table_prefix = 'xyz_';

define( 'WP_DEBUG', false ); // gestito dall'hosting
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', false );
if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) define( 'WP_DEBUG_DISPLAY', false );
define(
	'SCRIPT_DEBUG',
	false
);

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
