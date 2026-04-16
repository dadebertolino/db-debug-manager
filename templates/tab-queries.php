<?php
/**
 * Tab Query SQL.
 */
if (!defined('ABSPATH')) exit;

$saveq_on = defined('SAVEQUERIES') && SAVEQUERIES;
?>

<?php if (!$saveq_on): ?>
    <div class="db-ui-alert db-ui-alert-info">
        <span class="db-ui-alert-icon">ℹ️</span>
        <span>
            <?php esc_html_e('SAVEQUERIES non è attiva. Abilitala nella tab Costanti per registrare le query SQL eseguite su ciascuna pagina frontend.', 'db-debug-manager'); ?>
        </span>
    </div>
<?php endif; ?>

<div class="db-ui-card">
    <div class="db-ui-card-header dbdm-log-header">
        <h3><?php esc_html_e('Ultimo snapshot query frontend', 'db-debug-manager'); ?></h3>
        <div class="dbdm-log-meta">
            <?php if ($snapshot): ?>
                <span class="db-ui-badge db-ui-badge-primary"><?php echo esc_html($snapshot['num']); ?> <?php esc_html_e('query', 'db-debug-manager'); ?></span>
                <span class="db-ui-badge db-ui-badge-muted"><?php echo esc_html(number_format($snapshot['total_time'] * 1000, 2)); ?> ms</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="db-ui-card-body">
        <?php if (!$snapshot): ?>
            <div class="db-ui-empty">
                <span class="db-ui-empty-icon">🔎</span>
                <span class="db-ui-empty-text">
                    <?php esc_html_e('Nessuno snapshot disponibile.', 'db-debug-manager'); ?><br>
                    <?php esc_html_e('Visita una pagina del sito con SAVEQUERIES attiva e ricarica questa scheda.', 'db-debug-manager'); ?>
                </span>
            </div>
        <?php else: ?>
            <div class="dbdm-snap-meta">
                <strong><?php esc_html_e('URL', 'db-debug-manager'); ?>:</strong> <code><?php echo esc_html($snapshot['url']); ?></code>
                &nbsp;·&nbsp;
                <strong><?php esc_html_e('Ora', 'db-debug-manager'); ?>:</strong> <?php echo esc_html($snapshot['time']); ?>
            </div>

            <div class="dbdm-log-search">
                <input type="text" id="dbdm-q-filter" placeholder="<?php esc_attr_e('Filtra SQL (es: wp_options, SELECT, JOIN)...', 'db-debug-manager'); ?>">
            </div>

            <table class="db-ui-table dbdm-q-table" id="dbdm-q-table">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th style="width:90px;"><?php esc_html_e('Tempo', 'db-debug-manager'); ?></th>
                        <th><?php esc_html_e('SQL', 'db-debug-manager'); ?></th>
                        <th><?php esc_html_e('Caller', 'db-debug-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($snapshot['queries'] as $i => $q):
                    $time_ms = $q['time'] * 1000;
                    $slow = $time_ms > 50;
                ?>
                    <tr class="<?php echo $slow ? 'dbdm-q-slow' : ''; ?>">
                        <td><?php echo (int) ($i + 1); ?></td>
                        <td><?php echo esc_html(number_format($time_ms, 2)); ?> ms</td>
                        <td><code class="dbdm-q-sql"><?php echo esc_html($q['sql']); ?></code></td>
                        <td><code class="dbdm-q-stack"><?php echo esc_html($q['stack']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
