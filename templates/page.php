<?php
/**
 * Template pagina admin principale.
 * Variabili disponibili: $consts_status, $writable, $config_path, $log_path,
 * $log_exists, $log_size, $log_writable, $snapshot, $tab
 */
if (!defined('ABSPATH')) exit;

$base_url = DBDM_Admin::page_url();
?>
<div class="wrap dbdm-wrap">

    <div class="db-ui-page-header">
        <h1><?php esc_html_e('DB Debug Manager', 'db-debug-manager'); ?></h1>
        <div class="db-ui-actions">
            <span class="db-ui-badge db-ui-badge-muted">v<?php echo esc_html(DBDM_VERSION); ?></span>
        </div>
    </div>

    <?php if (isset($_GET['updated'])): ?>
        <?php if ($_GET['updated'] === '1'): ?>
            <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Impostazioni salvate. Ricarica la pagina per vedere lo stato aggiornato.', 'db-debug-manager'); ?></span></div>
        <?php else: ?>
            <div class="db-ui-alert db-ui-alert-danger"><span class="db-ui-alert-icon">⚠️</span><span><?php echo esc_html(isset($_GET['err']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['err']))) : __('Errore durante il salvataggio.', 'db-debug-manager')); ?></span></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['cleared'])): ?>
        <?php if ($_GET['cleared'] === '1'): ?>
            <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Log svuotato.', 'db-debug-manager'); ?></span></div>
        <?php else: ?>
            <div class="db-ui-alert db-ui-alert-danger"><span class="db-ui-alert-icon">⚠️</span><span><?php echo esc_html(isset($_GET['err']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['err']))) : __('Errore.', 'db-debug-manager')); ?></span></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['em_saved'])): ?>
        <?php if ($_GET['em_saved'] === '1'): ?>
            <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Impostazioni emergency salvate.', 'db-debug-manager'); ?></span></div>
        <?php else: ?>
            <div class="db-ui-alert db-ui-alert-danger"><span class="db-ui-alert-icon">⚠️</span><span><?php echo esc_html(isset($_GET['err']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['err']))) : __('Errore.', 'db-debug-manager')); ?></span></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['em_cleared'])): ?>
        <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Password emergency rimossa e accesso disattivato.', 'db-debug-manager'); ?></span></div>
    <?php endif; ?>

    <?php if (isset($_GET['em_log_cleared'])): ?>
        <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Log emergency svuotato.', 'db-debug-manager'); ?></span></div>
    <?php endif; ?>

    <?php if (isset($_GET['snap_created'])): ?>
        <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">📸</span><span><?php esc_html_e('Snapshot creato.', 'db-debug-manager'); ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['snap_deleted'])): ?>
        <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Snapshot eliminato.', 'db-debug-manager'); ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['snap_cleared'])): ?>
        <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">✅</span><span><?php esc_html_e('Tutti gli snapshot eliminati.', 'db-debug-manager'); ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['snap_restored'])): ?>
        <div class="db-ui-alert db-ui-alert-success"><span class="db-ui-alert-icon">⏪</span><span><?php esc_html_e('Ripristino completato. Controlla il sito.', 'db-debug-manager'); ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['snap_err'])): ?>
        <div class="db-ui-alert db-ui-alert-danger"><span class="db-ui-alert-icon">⚠️</span><span><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['snap_err'])))); ?></span></div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper dbdm-tabs">
        <a href="<?php echo esc_url($base_url . '&tab=config'); ?>" class="nav-tab <?php echo $tab === 'config' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Costanti', 'db-debug-manager'); ?></a>
        <a href="<?php echo esc_url($base_url . '&tab=log'); ?>" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Debug Log', 'db-debug-manager'); ?></a>
        <a href="<?php echo esc_url($base_url . '&tab=queries'); ?>" class="nav-tab <?php echo $tab === 'queries' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Query SQL', 'db-debug-manager'); ?></a>
        <a href="<?php echo esc_url($base_url . '&tab=snapshots'); ?>" class="nav-tab <?php echo $tab === 'snapshots' ? 'nav-tab-active' : ''; ?>">📸 <?php esc_html_e('Snapshots', 'db-debug-manager'); ?></a>
        <a href="<?php echo esc_url($base_url . '&tab=emergency'); ?>" class="nav-tab <?php echo $tab === 'emergency' ? 'nav-tab-active' : ''; ?>">🚨 <?php esc_html_e('Emergency', 'db-debug-manager'); ?></a>
    </h2>

    <?php if ($tab === 'config'): ?>
        <?php include DBDM_PLUGIN_DIR . 'templates/tab-config.php'; ?>
    <?php elseif ($tab === 'log'): ?>
        <?php include DBDM_PLUGIN_DIR . 'templates/tab-log.php'; ?>
    <?php elseif ($tab === 'queries'): ?>
        <?php include DBDM_PLUGIN_DIR . 'templates/tab-queries.php'; ?>
    <?php elseif ($tab === 'snapshots'): ?>
        <?php include DBDM_PLUGIN_DIR . 'templates/tab-snapshots.php'; ?>
    <?php elseif ($tab === 'emergency'): ?>
        <?php include DBDM_PLUGIN_DIR . 'templates/tab-emergency.php'; ?>
    <?php endif; ?>

</div>
