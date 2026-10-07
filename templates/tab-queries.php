<?php
/**
 * Tab Query SQL.
 */
if (!defined('ABSPATH')) exit;

$saveq_on   = defined('SAVEQUERIES') && SAVEQUERIES;
$monitor_left = DBDM_Queries::remaining(get_current_user_id());
?>

<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Registrazione delle query', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body">
        <p style="margin-top:0;">
            <?php esc_html_e('Vengono registrate solo le pagine del sito che visiti tu, da amministratore collegato, mentre la registrazione è attiva: mai le visite degli altri utenti, il login, le chiamate REST o AJAX.', 'db-debug-manager'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dbdm_query_monitor">
            <?php wp_nonce_field('dbdm_query_monitor'); ?>
            <?php if ($monitor_left > 0): ?>
                <span class="db-ui-badge db-ui-badge-success"><?php echo esc_html(sprintf(__('Attiva ancora per %d minuti', 'db-debug-manager'), (int) ceil($monitor_left / 60))); ?></span>
                <button type="submit" class="db-ui-btn db-ui-btn-secondary"><?php esc_html_e('Ferma la registrazione', 'db-debug-manager'); ?></button>
            <?php else: ?>
                <input type="hidden" name="start" value="1">
                <button type="submit" class="db-ui-btn db-ui-btn-primary"><?php esc_html_e('Registra le mie pagine per 30 minuti', 'db-debug-manager'); ?></button>
            <?php endif; ?>
        </form>
    </div>
</div>

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
                    <?php esc_html_e('Con SAVEQUERIES attiva e la registrazione accesa, visita una pagina del sito e ricarica questa scheda.', 'db-debug-manager'); ?>
                </span>
            </div>
        <?php else: ?>
            <div class="dbdm-snap-meta">
                <strong><?php esc_html_e('URL', 'db-debug-manager'); ?>:</strong> <code><?php echo esc_html(DBDM_Log::to_utf8($snapshot['url'])); ?></code>
                &nbsp;·&nbsp;
                <strong><?php esc_html_e('Ora', 'db-debug-manager'); ?>:</strong> <?php echo esc_html($snapshot['time']); ?>
            </div>

            <div class="dbdm-log-search">
                <input type="text" id="dbdm-q-filter" aria-label="<?php esc_attr_e('Filtra le query', 'db-debug-manager'); ?>" placeholder="<?php esc_attr_e('Filtra SQL (es: wp_options, SELECT, JOIN)...', 'db-debug-manager'); ?>">
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
                <?php
                foreach ($snapshot['queries'] as $i => $q):
                    $time_ms = $q['time'] * 1000;
                    $slow = $time_ms > 50;
                ?>
                    <tr class="<?php echo $slow ? 'dbdm-q-slow' : ''; ?>">
                        <td><?php echo (int) ($i + 1); ?></td>
                        <td><?php echo esc_html(number_format($time_ms, 2)); ?> ms</td>
                        <td><code class="dbdm-q-sql"><?php echo esc_html(DBDM_Log::to_utf8($q['sql'])); ?></code></td>
                        <td><code class="dbdm-q-stack"><?php echo esc_html(DBDM_Log::to_utf8($q['stack'])); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
