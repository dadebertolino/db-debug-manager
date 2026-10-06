<?php
/**
 * DB Debug Manager — eseguito da WordPress quando il plugin viene eliminato.
 *
 * @since 2.0.0
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

require_once __DIR__ . '/inc/class-standalone-config.php';
require_once __DIR__ . '/inc/class-uninstall.php';

DBDM_Uninstall::run();
