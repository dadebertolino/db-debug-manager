<?php
/**
 * DB Debug Manager — Emergency Standalone Access
 *
 * Accesso al debug del sito quando WordPress non si avvia.
 * Questo file NON carica wp-load.php: si connette al DB direttamente via PDO.
 *
 * URL: https://<sito>/wp-content/plugins/db-debug-manager/emergency.php
 *
 * Sicurezza:
 * - Password hash (password_hash) letta dalla tabella wp_options
 * - Rate limit 5 tentativi / 15 min per IP
 * - Log di tutti i tentativi
 * - Sessione 30 minuti, cookie HttpOnly+SameSite
 * - Deve essere esplicitamente abilitato dall'admin (OPTION_ENABLED=true)
 *
 * 2.0.0: la logica sta nelle classi di inc/emergency/ (richiesta, sessione,
 * database, azioni, pagine); questo file è solo il punto d'ingresso.
 *
 * @author Davide Bertolino
 */

// Niente output se chiamato da CLI o da include sbagliato.
if (php_sapi_name() === 'cli') { die('CLI not allowed.'); }

// Errori silenziati nel response ma loggati.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/inc/class-standalone-config.php';
require_once __DIR__ . '/inc/class-emergency-guard.php';
foreach (array('request', 'session', 'repository', 'logger', 'status', 'actions', 'view', 'app') as $dbdm_em_class) {
    require_once __DIR__ . '/inc/emergency/class-em-' . $dbdm_em_class . '.php';
}

$dbdm_em_app = new DBDM_Em_App(__DIR__);
$dbdm_em_app->run(DBDM_Em_Request::from_globals());
exit;
