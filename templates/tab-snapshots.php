<?php
/**
 * Tab Snapshots — preflight state capture & rollback.
 */
if (!defined('ABSPATH')) exit;

$snapshots = DBDM_Snapshots::get_all();
// Reverse: mostra dal più recente.
$snapshots = array_reverse($snapshots);

$trigger_labels = array(
    DBDM_Snapshots::TRIGGER_MANUAL    => array('Manuale', 'primary'),
    DBDM_Snapshots::TRIGGER_EMERGENCY => array('Emergency attivato', 'warning'),
    DBDM_Snapshots::TRIGGER_PRE_UPGRADE => array('Pre-aggiornamento', 'success'),
    DBDM_Snapshots::TRIGGER_UPGRADE   => array('Post-aggiornamento', 'success'),
);

?>

<div class="db-ui-alert db-ui-alert-info">
    <span class="db-ui-alert-icon">📸</span>
    <span>
        <strong><?php esc_html_e('Preflight snapshot.', 'db-debug-manager'); ?></strong>
        <?php esc_html_e('Registra la lista dei plugin attivi, il tema e le versioni installate. Se un aggiornamento rompe il sito, puoi ripristinare lo stato dei plugin attivi e del tema (i file invece restano come sono — i backup dei file richiedono un vero backup server).', 'db-debug-manager'); ?>
    </span>
</div>

<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Crea snapshot manuale', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dbdm_create_snapshot">
            <?php wp_nonce_field('dbdm_create_snapshot'); ?>
            <p>
                <label for="dbdm-snap-note"><?php esc_html_e('Nota (opzionale):', 'db-debug-manager'); ?></label><br>
                <input type="text" id="dbdm-snap-note" name="note" placeholder="<?php esc_attr_e('Es: prima update WooCommerce', 'db-debug-manager'); ?>" style="width:400px;" maxlength="200">
            </p>
            <button type="submit" class="db-ui-btn db-ui-btn-primary">📸 <?php esc_html_e('Crea snapshot adesso', 'db-debug-manager'); ?></button>
            <span class="db-ui-text-muted" style="margin-left:12px; font-size:12px;">
                <?php printf(esc_html__('Massimo %d snapshot, FIFO.', 'db-debug-manager'), absint(DBDM_Snapshots::MAX_SNAPSHOTS)); ?>
            </span>
        </form>
    </div>
</div>

<div class="db-ui-card">
    <div class="db-ui-card-header dbdm-log-header">
        <h3><?php esc_html_e('Snapshot disponibili', 'db-debug-manager'); ?></h3>
        <div class="dbdm-log-meta">
            <span class="db-ui-badge db-ui-badge-muted"><?php echo absint(count($snapshots)); ?> / <?php echo absint(DBDM_Snapshots::MAX_SNAPSHOTS); ?></span>
            <?php if (!empty($snapshots)): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Eliminare tutti gli snapshot?', 'db-debug-manager')); ?>');">
                    <input type="hidden" name="action" value="dbdm_clear_snapshots">
                    <?php wp_nonce_field('dbdm_clear_snapshots'); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-sm db-ui-btn-danger">🗑 <?php esc_html_e('Elimina tutti', 'db-debug-manager'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="db-ui-card-body">
        <?php if (empty($snapshots)): ?>
            <div class="db-ui-empty">
                <span class="db-ui-empty-icon">📸</span>
                <span class="db-ui-empty-text">
                    <?php esc_html_e('Nessuno snapshot ancora. Crea il primo manualmente, oppure verrà creato automaticamente quando attivi l\'emergency o quando WordPress completa un aggiornamento.', 'db-debug-manager'); ?>
                </span>
            </div>
        <?php else: ?>
            <div class="dbdm-snapshots">
                <?php
                foreach ($snapshots as $snap):
                    $diff = DBDM_Snapshots::diff_with_current($snap);
                    $is_current = DBDM_Snapshots::diff_is_empty($diff);
                    $trigger = $trigger_labels[$snap['trigger']] ?? array($snap['trigger'], 'muted');
                ?>
                    <div class="dbdm-snap-card">
                        <div class="dbdm-snap-head">
                            <div>
                                <strong><?php echo esc_html(wp_date('j M Y, H:i', $snap['timestamp'])); ?></strong>
                                <span class="db-ui-badge db-ui-badge-<?php echo esc_attr($trigger[1]); ?>"><?php echo esc_html($trigger[0]); ?></span>
                                <?php if ($is_current): ?>
                                    <span class="db-ui-badge db-ui-badge-muted"><?php esc_html_e('Stato corrente', 'db-debug-manager'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <code style="font-size:11px; color:var(--db-text-muted);"><?php echo esc_html(substr($snap['id'], 0, 14)); ?></code>
                            </div>
                        </div>

                        <?php if (!empty($snap['note'])): ?>
                            <div class="dbdm-snap-note"><?php echo esc_html($snap['note']); ?></div>
                        <?php endif; ?>

                        <div class="dbdm-snap-stats">
                            <span>📦 <?php echo count($snap['active_plugins'] ?? array()); ?> <?php esc_html_e('plugin attivi', 'db-debug-manager'); ?></span>
                            <span>🎨 <code><?php echo esc_html($snap['stylesheet']); ?></code></span>
                            <span>⚙️ WP <?php echo esc_html($snap['wp_version'] ?? '—'); ?></span>
                        </div>

                        <?php if (!$is_current): ?>
                            <div class="dbdm-snap-diff">
                                <strong><?php esc_html_e('Differenze rispetto ad adesso:', 'db-debug-manager'); ?></strong>
                                <ul>
                                    <?php if ($diff['theme_changed']): ?>
                                        <li>🎨 
                                        <?php
                                        printf(esc_html__('Tema cambiato: %1$s → %2$s', 'db-debug-manager'),
                                            '<code>' . esc_html($diff['theme_changed']['from']) . '</code>',
                                            '<code>' . esc_html($diff['theme_changed']['to']) . '</code>');
                                            ?>
                                            </li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['plugins_activated'])): ?>
                                        <li>✅ <?php printf(esc_html__('%d plugin attivati dopo lo snapshot', 'db-debug-manager'), count($diff['plugins_activated'])); ?>:
                                            <span class="dbdm-plugin-list">
                                            <?php
                                            echo esc_html(implode(', ', array_map(function ($p) {
 $d = dirname($p);
return ($d && $d !== '.') ? $d : $p;
}, $diff['plugins_activated'])));
?>
</span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['plugins_deactivated'])): ?>
                                        <li>⛔ <?php printf(esc_html__('%d plugin disattivati dopo lo snapshot', 'db-debug-manager'), count($diff['plugins_deactivated'])); ?>:
                                            <span class="dbdm-plugin-list">
                                            <?php
                                            echo esc_html(implode(', ', array_map(function ($p) {
 $d = dirname($p);
return ($d && $d !== '.') ? $d : $p;
}, $diff['plugins_deactivated'])));
?>
</span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['network_activated'])): ?>
                                        <li>🌐 <?php printf(esc_html__('%d plugin attivati in rete dopo lo snapshot', 'db-debug-manager'), count($diff['network_activated'])); ?>:
                                            <span class="dbdm-plugin-list"><?php echo esc_html(implode(', ', $diff['network_activated'])); ?></span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['network_deactivated'])): ?>
                                        <li>🌐 <?php printf(esc_html__('%d plugin disattivati in rete dopo lo snapshot', 'db-debug-manager'), count($diff['network_deactivated'])); ?>:
                                            <span class="dbdm-plugin-list"><?php echo esc_html(implode(', ', $diff['network_deactivated'])); ?></span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['plugins_updated'])): ?>
                                        <li>🔄 <?php printf(esc_html__('%d plugin aggiornati', 'db-debug-manager'), count($diff['plugins_updated'])); ?>:
                                            <ul style="margin:4px 0 0 20px;">
                                                <?php foreach ($diff['plugins_updated'] as $info): ?>
                                                    <li><code><?php echo esc_html($info['name']); ?></code> <?php echo esc_html($info['from']); ?> → <strong><?php echo esc_html($info['to']); ?></strong></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['plugins_installed'])): ?>
                                        <li>➕ <?php printf(esc_html__('%d plugin installati dopo', 'db-debug-manager'), count($diff['plugins_installed'])); ?></li>
                                    <?php endif; ?>

                                    <?php if (!empty($diff['plugins_removed'])): ?>
                                        <li>➖ <?php printf(esc_html__('%d plugin rimossi', 'db-debug-manager'), count($diff['plugins_removed'])); ?></li>
                                    <?php endif; ?>

                                    <?php if ($diff['wp_version_changed']): ?>
                                        <li>⚙️ WordPress: <?php echo esc_html($diff['wp_version_changed']['from']); ?> → <strong><?php echo esc_html($diff['wp_version_changed']['to']); ?></strong></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="dbdm-snap-actions">
                            <?php if (!$is_current): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex; gap:10px; align-items:center;" onsubmit="return confirm('<?php echo esc_js(__('Ripristinare questo snapshot? L\'azione modifica plugin attivi/tema nel database.', 'db-debug-manager')); ?>');">
                                    <input type="hidden" name="action" value="dbdm_restore_snapshot">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($snap['id']); ?>">
                                    <?php wp_nonce_field('dbdm_restore_snapshot'); ?>
                                    <label style="font-size:12px;"><input type="checkbox" name="restore_plugins" value="1" checked> <?php esc_html_e('Plugin attivi', 'db-debug-manager'); ?></label>
                                    <label style="font-size:12px;"><input type="checkbox" name="restore_theme" value="1" checked> <?php esc_html_e('Tema', 'db-debug-manager'); ?></label>
                                    <button type="submit" class="db-ui-btn db-ui-btn-sm db-ui-btn-primary">⏪ <?php esc_html_e('Ripristina', 'db-debug-manager'); ?></button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="dbdm_delete_snapshot">
                                <input type="hidden" name="id" value="<?php echo esc_attr($snap['id']); ?>">
                                <?php wp_nonce_field('dbdm_delete_snapshot'); ?>
                                <button type="submit" class="db-ui-btn db-ui-btn-sm">🗑</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
