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
            <span id="dbdm-log-size" class="db-ui-badge <?php echo $log_exists ? 'db-ui-badge-primary' : 'db-ui-badge-muted'; ?>"><?php echo $log_exists ? esc_html($log_size) : esc_html__('Nessun log', 'db-debug-manager'); ?></span>
            <span id="dbdm-log-status" class="db-ui-text-muted" role="status" aria-live="polite"></span>

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
            <p class="db-ui-text-muted" id="dbdm-log-missing">
                <?php esc_html_e('Nessun file debug.log trovato.', 'db-debug-manager'); ?>
                <?php esc_html_e('Abilita WP_DEBUG e WP_DEBUG_LOG nella tab Costanti per iniziare a registrare gli errori.', 'db-debug-manager'); ?>
            </p>
        <?php endif; ?>
        <!-- 2.0.0 (bug 57): viewer sempre presente, così aggiorna e auto-refresh funzionano anche se il log nasce dopo. -->
        <div class="dbdm-log-search">
            <input type="text" id="dbdm-log-filter" placeholder="<?php esc_attr_e('Filtra righe (es: Fatal, Notice, Warning)...', 'db-debug-manager'); ?>">
        </div>
        <pre id="dbdm-log-viewer" class="dbdm-log-viewer"><?php echo esc_html($log_content); ?></pre>
    </div>
</div>
