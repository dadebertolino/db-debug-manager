<?php
/**
 * Tab Costanti.
 */
if (!defined('ABSPATH')) exit;

$descriptions = array(
    'WP_DEBUG' => array(
        'label' => __('Abilita la modalità debug di WordPress. Richiesta per attivare le altre opzioni.', 'db-debug-manager'),
        'warn'  => false,
    ),
    'WP_DEBUG_LOG' => array(
        'label' => __('Scrive errori e warning nel file wp-content/debug.log.', 'db-debug-manager'),
        'warn'  => false,
    ),
    'WP_DEBUG_DISPLAY' => array(
        'label' => __('Mostra gli errori direttamente nelle pagine. DA TENERE DISATTIVATO IN PRODUZIONE.', 'db-debug-manager'),
        'warn'  => true,
    ),
    'SCRIPT_DEBUG' => array(
        'label' => __('Carica le versioni non minificate di script e stili di WordPress. Utile in sviluppo.', 'db-debug-manager'),
        'warn'  => false,
    ),
    'SAVEQUERIES' => array(
        'label' => __('Salva tutte le query SQL eseguite in $wpdb->queries. Impatta le performance: usare solo in debug.', 'db-debug-manager'),
        'warn'  => true,
    ),
);
?>

<?php if (!$writable): ?>
    <div class="db-ui-alert db-ui-alert-danger">
        <span class="db-ui-alert-icon">🔒</span>
        <span>
            <strong><?php esc_html_e('wp-config.php non è scrivibile.', 'db-debug-manager'); ?></strong><br>
            <?php printf(esc_html__('Percorso rilevato: %s', 'db-debug-manager'), '<code>' . esc_html($config_path ?: 'non trovato') . '</code>'); ?><br>
            <?php esc_html_e('Modifica i permessi del file (consigliato 0644) per poter salvare le impostazioni da qui.', 'db-debug-manager'); ?>
        </span>
    </div>
<?php endif; ?>

<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Stato costanti di debug', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dbdm_save_constants">
            <?php wp_nonce_field('dbdm_save_constants'); ?>

            <table class="db-ui-table dbdm-const-table">
                <thead>
                    <tr>
                        <th style="width:40px;"><?php esc_html_e('Attiva', 'db-debug-manager'); ?></th>
                        <th><?php esc_html_e('Costante', 'db-debug-manager'); ?></th>
                        <th><?php esc_html_e('Stato attuale', 'db-debug-manager'); ?></th>
                        <th><?php esc_html_e('Descrizione', 'db-debug-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (DBDM_Config::MANAGED as $const):
                    $s = $status[$const];
                    $is_on = $s['defined'] && $s['value'] === true;
                    $desc = $descriptions[$const];
                ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                name="dbdm[<?php echo esc_attr($const); ?>]"
                                value="1"
                                <?php checked($is_on); ?>
                                <?php disabled(!$writable); ?>>
                        </td>
                        <td><code><?php echo esc_html($const); ?></code></td>
                        <td>
                            <?php if ($is_on): ?>
                                <span class="db-ui-badge db-ui-badge-success"><?php esc_html_e('Attiva', 'db-debug-manager'); ?></span>
                            <?php elseif ($s['defined']): ?>
                                <span class="db-ui-badge db-ui-badge-muted"><?php esc_html_e('Definita ma false', 'db-debug-manager'); ?></span>
                            <?php else: ?>
                                <span class="db-ui-badge db-ui-badge-muted"><?php esc_html_e('Non definita', 'db-debug-manager'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="dbdm-desc">
                            <?php echo esc_html($desc['label']); ?>
                            <?php if ($desc['warn']): ?>
                                <span class="db-ui-badge db-ui-badge-warning"><?php esc_html_e('Attenzione', 'db-debug-manager'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="dbdm-form-footer">
                <button type="submit" class="db-ui-btn db-ui-btn-primary" <?php disabled(!$writable); ?>>
                    <?php esc_html_e('Salva modifiche', 'db-debug-manager'); ?>
                </button>
                <span class="db-ui-text-muted"><?php esc_html_e('Le modifiche hanno effetto al prossimo caricamento pagina (backup automatico di wp-config.php in private/wp-config.dbdm-bak).', 'db-debug-manager'); ?></span>
            </div>
        </form>
    </div>
</div>

<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Informazioni sistema', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body">
        <table class="db-ui-table">
            <tbody>
                <tr><td><strong>wp-config.php</strong></td><td><code><?php echo esc_html($config_path ?: '—'); ?></code></td></tr>
                <tr><td><strong><?php esc_html_e('Scrivibile', 'db-debug-manager'); ?></strong></td><td><?php echo $writable ? '✅' : '❌'; ?></td></tr>
                <tr><td><strong>debug.log</strong></td><td><code><?php echo esc_html($log_path); ?></code></td></tr>
                <tr><td><strong><?php esc_html_e('Log presente', 'db-debug-manager'); ?></strong></td><td><?php echo $log_exists ? '✅ (' . esc_html($log_size) . ')' : '—'; ?></td></tr>
                <tr><td><strong>PHP</strong></td><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
                <tr><td><strong>WordPress</strong></td><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
