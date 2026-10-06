<?php
/**
 * Tab Emergency Access.
 */
if (!defined('ABSPATH')) exit;

$em_enabled    = DBDM_Emergency::is_enabled();
$has_pwd       = DBDM_Emergency::has_password();
$access_url    = DBDM_Emergency::access_url();
$log_entries   = DBDM_Emergency::get_log_entries(50);
?>

<?php
$dbdm_server = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
$dbdm_is_apache = (stripos($dbdm_server, 'apache') !== false || stripos($dbdm_server, 'litespeed') !== false);
if (!$dbdm_is_apache):
?>
<div class="db-ui-alert db-ui-alert-warning">
    <span class="db-ui-alert-icon">🛡️</span>
    <span>
        <strong><?php esc_html_e('Server non Apache rilevato.', 'db-debug-manager'); ?></strong>
        <?php esc_html_e('I file interni del plugin (log, snapshot, backup di wp-config) sono in una cartella con nome casuale e protetta da .htaccess, ma su Nginx l\'.htaccess viene ignorato. Per una protezione esplicita aggiungi alla configurazione del server:', 'db-debug-manager'); ?>
        <br><code style="display:block; margin-top:6px; user-select:all;"><?php echo esc_html(DBDM_Emergency::nginx_rule()); ?></code>
    </span>
</div>
<?php endif; ?>

<div class="db-ui-alert db-ui-alert-warning">
    <span class="db-ui-alert-icon">⚠️</span>
    <span>
        <strong><?php esc_html_e('Funzione sensibile.', 'db-debug-manager'); ?></strong>
        <?php esc_html_e('L\'accesso emergency bypassa completamente l\'autenticazione di WordPress e consente operazioni come disattivare plugin o cambiare tema anche quando il sito è down. Usa una password forte e tieni questa funzione disattivata quando non serve.', 'db-debug-manager'); ?>
    </span>
</div>

<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Stato', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body">
        <table class="db-ui-table">
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e('Accesso attivo', 'db-debug-manager'); ?></strong></td>
                    <td>
                        <?php if ($em_enabled): ?>
                            <span class="db-ui-badge db-ui-badge-success"><?php esc_html_e('Sì', 'db-debug-manager'); ?></span>
                        <?php else: ?>
                            <span class="db-ui-badge db-ui-badge-muted"><?php esc_html_e('Disattivato', 'db-debug-manager'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Password configurata', 'db-debug-manager'); ?></strong></td>
                    <td>
                        <?php if ($has_pwd): ?>
                            <span class="db-ui-badge db-ui-badge-success">✓</span>
                        <?php else: ?>
                            <span class="db-ui-badge db-ui-badge-danger"><?php esc_html_e('Nessuna', 'db-debug-manager'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('URL accesso', 'db-debug-manager'); ?></strong></td>
                    <td><code style="word-break:break-all;"><?php echo esc_html($access_url); ?></code></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Configura password e attivazione', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dbdm_save_emergency">
            <?php wp_nonce_field('dbdm_save_emergency'); ?>

            <p>
                <label>
                    <strong><?php esc_html_e('Nuova password', 'db-debug-manager'); ?></strong>
                    <?php if ($has_pwd): ?>
                        <span class="db-ui-text-muted"> — <?php esc_html_e('lascia vuoto per mantenere l\'attuale', 'db-debug-manager'); ?></span>
                    <?php endif; ?>
                </label><br>
                <input type="password" name="dbdm_password" autocomplete="new-password" style="width:320px;" minlength="12" <?php echo $has_pwd ? '' : 'required'; ?>>
            </p>
            <p class="description" style="color:var(--db-text-muted); font-size:12px;">
                <?php esc_html_e('Minimo 12 caratteri, con maiuscole, minuscole e numeri. Salvata come hash bcrypt.', 'db-debug-manager'); ?>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="dbdm_emergency_enabled" value="1" <?php checked($em_enabled); ?>>
                    <strong><?php esc_html_e('Attiva accesso emergency', 'db-debug-manager'); ?></strong>
                </label>
            </p>
            <p class="description" style="color:var(--db-text-muted); font-size:12px;">
                <?php esc_html_e('Quando disattivato, emergency.php restituisce un errore anche con la password corretta. Tienilo spento tranne quando serve davvero.', 'db-debug-manager'); ?>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="dbdm_trust_proxy" value="1" <?php checked(DBDM_Emergency::trusts_proxy()); ?>>
                    <strong><?php esc_html_e('Il sito è dietro un proxy/CDN fidato (es. Cloudflare)', 'db-debug-manager'); ?></strong>
                </label>
            </p>
            <p class="description" style="color:var(--db-text-muted); font-size:12px;">
                <?php esc_html_e('Attivalo SOLO se tutto il traffico passa da un reverse proxy o CDN: in quel caso emergency.php userà gli header del proxy (CF-Connecting-IP, X-Forwarded-For) per identificare l\'IP reale nel rate-limit e nei log. Se il sito NON è dietro proxy, lascialo disattivato: quegli header sono falsificabili e permetterebbero di aggirare il blocco tentativi.', 'db-debug-manager'); ?>
            </p>

            <div class="dbdm-form-footer">
                <button type="submit" class="db-ui-btn db-ui-btn-primary">
                    <?php esc_html_e('Salva impostazioni', 'db-debug-manager'); ?>
                </button>
                <?php if ($has_pwd): ?>
                    <button type="submit" name="dbdm_action" value="clear" class="db-ui-btn db-ui-btn-danger" onclick="return confirm('<?php echo esc_js(__('Rimuovere password e disattivare accesso emergency?', 'db-debug-manager')); ?>');">
                        <?php esc_html_e('Rimuovi password', 'db-debug-manager'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($em_enabled && $has_pwd): ?>
<div class="db-ui-card">
    <div class="db-ui-card-header">
        <h3><?php esc_html_e('Come testare', 'db-debug-manager'); ?></h3>
    </div>
    <div class="db-ui-card-body" style="font-size:13px; line-height:1.7;">
        <ol style="margin:0; padding-left:20px;">
            <li><?php esc_html_e('In una finestra privata del browser, apri:', 'db-debug-manager'); ?>
                <br><a href="<?php echo esc_url($access_url); ?>" target="_blank"><code><?php echo esc_html($access_url); ?></code></a>
            </li>
            <li><?php esc_html_e('Inserisci la password configurata.', 'db-debug-manager'); ?></li>
            <li><?php esc_html_e('Se vedi la dashboard, funziona anche quando WP è morto.', 'db-debug-manager'); ?></li>
        </ol>
        <p style="margin:10px 0 0;"><?php esc_html_e('Se la pagina dice «Accesso d\'emergenza non disponibile», il motivo (accesso disattivato, password mancante, database, cartella privata) è scritto nel log degli errori di PHP del server: per sicurezza non viene mostrato a chi apre la pagina.', 'db-debug-manager'); ?></p>
    </div>
</div>
<?php endif; ?>

<div class="db-ui-card">
    <div class="db-ui-card-header dbdm-log-header">
        <h3><?php esc_html_e('Log accessi e tentativi', 'db-debug-manager'); ?></h3>
        <div class="dbdm-log-meta">
            <span class="db-ui-badge db-ui-badge-muted"><?php echo count($log_entries); ?> <?php esc_html_e('ultime righe', 'db-debug-manager'); ?></span>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Svuotare tutti i log di accesso e rate-limit?', 'db-debug-manager')); ?>');">
                <input type="hidden" name="action" value="dbdm_clear_emergency_log">
                <?php wp_nonce_field('dbdm_clear_emergency_log'); ?>
                <button type="submit" class="db-ui-btn db-ui-btn-sm db-ui-btn-danger">🗑 <?php esc_html_e('Svuota log', 'db-debug-manager'); ?></button>
            </form>
        </div>
    </div>
    <div class="db-ui-card-body">
        <?php if (empty($log_entries)): ?>
            <div class="db-ui-empty">
                <span class="db-ui-empty-icon">📋</span>
                <span class="db-ui-empty-text"><?php esc_html_e('Nessun accesso registrato.', 'db-debug-manager'); ?></span>
            </div>
        <?php else: ?>
            <pre class="dbdm-log-viewer" style="max-height:300px;"><?php echo esc_html(implode("\n", $log_entries)); ?></pre>
        <?php endif; ?>
    </div>
</div>
