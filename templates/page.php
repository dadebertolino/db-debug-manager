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

    <?php
    $dbdm_icons = array('success' => '✅', 'warning' => '⚠️', 'error' => '❌');
    $dbdm_class = array('success' => 'success', 'warning' => 'warning', 'error' => 'danger');
    foreach (DBDM_Admin::take_notices() as $dbdm_notice):
        ?>
        <div class="db-ui-alert db-ui-alert-<?php echo esc_attr($dbdm_class[$dbdm_notice[0]]); ?>" role="<?php echo $dbdm_notice[0] === 'error' ? 'alert' : 'status'; ?>"><span class="db-ui-alert-icon" aria-hidden="true"><?php echo esc_html($dbdm_icons[$dbdm_notice[0]]); ?></span><span><?php echo esc_html($dbdm_notice[1]); ?></span></div>
    <?php endforeach; ?>

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
