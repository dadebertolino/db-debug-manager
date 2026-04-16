<?php
/**
 * Tab Debug Log.
 */
if (!defined('ABSPATH')) exit;

$log_content = $log_exists ? DBDM_Log::tail(500) : '';
?>

<div class="db-ui-card">
    <div class="db-ui-card-header dbdm-log-header">
        <h3><?php esc_html_e('Debug Log', 'db-debug-manager'); ?></h3>
        <div class="dbdm-log-meta">
            <?php if ($log_exists): ?>
                <span class="db-ui-badge db-ui-badge-primary"><?php echo esc_html($log_size); ?></span>
            <?php else: ?>
                <span class="db-ui-badge db-ui-badge-muted"><?php esc_html_e('Nessun log', 'db-debug-manager'); ?></span>
            <?php endif; ?>

            <label class="dbdm-inline-label">
                <?php esc_html_e('Righe:', 'db-debug-manager'); ?>
                <select id="dbdm-log-lines">
                    <option value="100">100</option>
                    <option value="500" selected>500</option>
                    <option value="1000">1000</option>
                    <option value="5000">5000</option>
                </select>
            </label>

            <label class="dbdm-inline-label">
                <input type="checkbox" id="dbdm-auto-refresh">
                <?php esc_html_e('Auto-refresh 5s', 'db-debug-manager'); ?>
            </label>

            <button type="button" class="db-ui-btn db-ui-btn-sm" id="dbdm-refresh-btn">🔄 <?php esc_html_e('Aggiorna', 'db-debug-manager'); ?></button>

            <?php if ($log_exists): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="dbdm_download_log">
                    <?php wp_nonce_field('dbdm_download_log'); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-sm">⬇️ <?php esc_html_e('Scarica', 'db-debug-manager'); ?></button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" id="dbdm-clear-form">
                    <input type="hidden" name="action" value="dbdm_clear_log">
                    <?php wp_nonce_field('dbdm_clear_log'); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-sm db-ui-btn-danger">🗑 <?php esc_html_e('Svuota', 'db-debug-manager'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="db-ui-card-body">
        <?php if (!$log_exists): ?>
            <div class="db-ui-empty">
                <span class="db-ui-empty-icon">📋</span>
                <span class="db-ui-empty-text">
                    <?php esc_html_e('Nessun file debug.log trovato.', 'db-debug-manager'); ?><br>
                    <?php esc_html_e('Abilita WP_DEBUG e WP_DEBUG_LOG nella tab Costanti per iniziare a registrare gli errori.', 'db-debug-manager'); ?>
                </span>
            </div>
        <?php else: ?>
            <div class="dbdm-log-search">
                <input type="text" id="dbdm-log-filter" placeholder="<?php esc_attr_e('Filtra righe (es: Fatal, Notice, Warning)...', 'db-debug-manager'); ?>">
            </div>
            <pre id="dbdm-log-viewer" class="dbdm-log-viewer"><?php echo esc_html($log_content); ?></pre>
        <?php endif; ?>
    </div>
</div>
