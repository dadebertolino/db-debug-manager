<?php
/**
 * DB Debug Manager — Emergency: pagine HTML.
 *
 * Errore, login e dashboard. Tutto l'output passa da htmlspecialchars().
 * Il token CSRF dei moduli si legge dalla sessione al momento del rendering
 * (una sessione scaduta viene svuotata e il token rigenerato).
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_View')) return;

class DBDM_Em_View {

    /** @var DBDM_Em_Session */
    private $session;

    public function __construct(DBDM_Em_Session $session) {
        $this->session = $session;
    }

    public function error($msg) {
        $this->header('Debug Manager — Errore');
        ?>
<div class="login-wrap">
    <div class="logo">⚠️</div>
    <h1>Accesso non disponibile</h1>
    <p style="color:var(--muted); text-align:center;"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></p>
</div>
<?php
        $this->footer();
    }

    public function login($error_msg = '') {
        $csrf = $this->session->csrf_token();
        $this->header('Debug Manager — Emergency Login');
        ?>
<div class="login-wrap">
    <div class="logo">🔒</div>
    <h1>Emergency Access</h1>
    <?php if ($error_msg): ?>
        <div class="notice notice-err"><?php echo htmlspecialchars($error_msg, ENT_QUOTES); ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
        <div style="margin-bottom:14px;">
            <input type="password" name="password" placeholder="Password emergency" autofocus required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Accedi</button>
    </form>
    <p style="color:var(--muted); font-size:11px; text-align:center; margin-top:16px;">
        5 tentativi per IP prima del blocco di 15 minuti. Ogni tentativo viene loggato.
    </p>
</div>
<?php
        $this->footer();
    }

    /**
     * @param array[] $notices Avvisi array(tipo, messaggio).
     * @param array   $data    Da DBDM_Em_Status::collect().
     */
    public function dashboard(array $notices, array $data) {
        $csrf            = $this->session->csrf_token();
        $log_content     = $data['log_content'];
        $log_size        = $data['log_size'];
        $active_plugins  = $data['active_plugins'];
        $cur_theme       = $data['cur_theme'];
        $consts_status   = $data['consts_status'];
        $php_error_log   = $data['php_error_log'];
        $php_log_content = $data['php_log_content'];
        $snapshots       = $data['snapshots'];
        $this->header('Debug Manager — Emergency Dashboard');
        ?>
<div class="wrap">
    <div class="header">
        <div>
            <h1>🚨 Emergency Dashboard</h1>
            <small>Accesso diretto al sito senza WordPress · Sessione valida 30 min</small>
        </div>
        <div>
            <a class="btn btn-sm" href="?a=logout">Logout</a>
        </div>
    </div>

    <?php foreach ($notices as $n): ?>
        <div class="notice notice-<?php echo $n[0] === 'ok' ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars($n[1], ENT_QUOTES); ?></div>
    <?php endforeach; ?>

    <div class="panel panel-danger">
        <h2>⚡ Azioni rapide di ripristino</h2>
        <p style="color:var(--muted); font-size:12px; margin:0 0 12px;">Usa queste azioni se il sito è down. Ogni operazione è irreversibile senza un backup.</p>
        <div class="row">
            <form method="post" style="display:inline;" onsubmit="return confirm('Disattivare TUTTI i plugin?');">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="disable_all_plugins">
                <button type="submit" class="btn btn-danger btn-sm">🔌 Disattiva tutti i plugin</button>
            </form>

            <form method="post" style="display:inline;" onsubmit="return confirm('Cambiare al tema default? Lo stylesheet e template attuali verranno sostituiti.');">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="switch_to_default_theme">
                <button type="submit" class="btn btn-warn btn-sm">🎨 Cambia a tema default</button>
            </form>

            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="clear_transients">
                <button type="submit" class="btn btn-sm">🧹 Svuota transient</button>
            </form>

            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="a" value="clear_log">
                <button type="submit" class="btn btn-sm">🗑 Svuota debug.log</button>
            </form>
        </div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>🔧 Costanti Debug</h2>
            <table>
                <?php foreach ($consts_status as $name => $val): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($name, ENT_QUOTES); ?></code></td>
                        <td>
                            <?php if ($val === true): ?><span class="tag tag-ok">ON</span>
                            <?php elseif ($val === false): ?><span class="tag tag-off">OFF</span>
                            <?php else: ?><span class="tag tag-off">non definita</span><?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="a" value="toggle_const">
                                <input type="hidden" name="const" value="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>">
                                <input type="hidden" name="enable" value="<?php echo $val === true ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-sm"><?php echo $val === true ? 'Off' : 'On'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="panel">
            <h2>🎨 Tema attuale</h2>
            <p style="margin:0;">Stylesheet: <code><?php echo htmlspecialchars($cur_theme, ENT_QUOTES); ?></code></p>
        </div>
    </div>

    <?php if (!empty($snapshots)): ?>
    <div class="panel" style="border-color:var(--primary);">
        <h2>📸 Snapshot disponibili (<?php echo count($snapshots); ?>)</h2>
        <p style="color:var(--muted); font-size:12px; margin:0 0 12px;">
            Ripristina lo stato dei plugin attivi e/o del tema a uno snapshot precedente.
            I file dei plugin/tema non vengono toccati: cambia solo ciò che è attivo nel DB.
        </p>

        <?php foreach ($snapshots as $snap): ?>
            <?php
            $trigger_map = array(
                'manual'            => array('Manuale', 'var(--primary)'),
                'emergency_enabled' => array('Pre-emergency', 'var(--warn)'),
                'wp_upgrade'        => array('Post-aggiornamento', 'var(--ok)'),
            );
            $trig = $trigger_map[$snap['trigger'] ?? 'manual'] ?? array($snap['trigger'] ?? '—', 'var(--muted)');
            $date_fmt = !empty($snap['timestamp']) ? gmdate('j/m/Y H:i', $snap['timestamp']) . ' UTC' : '—';
            $plugin_count = isset($snap['active_plugins']) ? count($snap['active_plugins']) : 0;
            $snap_theme = $snap['stylesheet'] ?? '—';
            $note = $snap['note'] ?? '';
            ?>
            <div style="border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:10px; background:var(--bg);">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                    <div>
                        <strong><?php echo htmlspecialchars($date_fmt, ENT_QUOTES); ?></strong>
                        <span class="tag" style="background:rgba(74,158,255,0.15); color:<?php echo $trig[1]; ?>; margin-left:6px;"><?php echo htmlspecialchars($trig[0], ENT_QUOTES); ?></span>
                    </div>
                    <code style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars(substr($snap['id'] ?? '', 0, 14), ENT_QUOTES); ?></code>
                </div>

                <?php if ($note): ?>
                    <div style="font-size:12px; color:var(--muted); margin-bottom:8px; padding:6px 10px; background:rgba(255,255,255,0.04); border-radius:4px;">
                        📝 <?php echo htmlspecialchars($note, ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div style="font-size:12px; color:var(--muted); margin-bottom:10px;">
                    📦 <?php echo $plugin_count; ?> plugin attivi · 🎨 <code><?php echo htmlspecialchars($snap_theme, ENT_QUOTES); ?></code> · WP <?php echo htmlspecialchars($snap['wp_version'] ?? '—', ENT_QUOTES); ?>
                </div>

                <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;" onsubmit="return confirm('Ripristinare questo snapshot? Verranno modificati i plugin attivi/tema nel database.');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <input type="hidden" name="a" value="restore_snapshot">
                    <input type="hidden" name="snap_id" value="<?php echo htmlspecialchars($snap['id'] ?? '', ENT_QUOTES); ?>">
                    <label style="font-size:12px; color:var(--text);">
                        <input type="checkbox" name="restore_plugins" value="1" checked> Plugin attivi
                    </label>
                    <label style="font-size:12px; color:var(--text);">
                        <input type="checkbox" name="restore_theme" value="1" checked> Tema
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm">⏪ Ripristina</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h2>🔌 Plugin attivi (<?php echo count($active_plugins); ?>)</h2>
        <?php if (empty($active_plugins)): ?>
            <p style="color:var(--muted);">Nessun plugin attivo.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Slug</th><th style="width:100px; text-align:right;">Azione</th></tr></thead>
                <tbody>
                    <?php foreach ($active_plugins as $p): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($p, ENT_QUOTES); ?></code></td>
                            <td style="text-align:right;">
                                <form method="post" style="display:inline;" onsubmit="return confirm('Disattivare <?php echo htmlspecialchars($p, ENT_QUOTES); ?>?');">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                    <input type="hidden" name="a" value="disable_plugin">
                                    <input type="hidden" name="plugin" value="<?php echo htmlspecialchars($p, ENT_QUOTES); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Disattiva</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>📋 debug.log <small style="color:var(--muted); font-weight:normal;"><?php echo number_format($log_size / 1024, 1); ?> KB</small></h2>
        <?php if (!$log_content): ?>
            <p style="color:var(--muted);">Nessun debug.log trovato o file vuoto.</p>
        <?php else: ?>
            <pre class="log"><?php echo htmlspecialchars($log_content, ENT_QUOTES); ?></pre>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>🖥 PHP error log del server</h2>
        <?php if (!$php_error_log): ?>
            <p style="color:var(--muted);">error_log di PHP non configurato.</p>
        <?php elseif (!file_exists($php_error_log)): ?>
            <p style="color:var(--muted);">Path: <code><?php echo htmlspecialchars($php_error_log, ENT_QUOTES); ?></code> (non esistente).</p>
        <?php elseif (!$php_log_content): ?>
            <p style="color:var(--muted);">Path: <code><?php echo htmlspecialchars($php_error_log, ENT_QUOTES); ?></code> (vuoto o non leggibile).</p>
        <?php else: ?>
            <p style="color:var(--muted); font-size:11px; margin:0 0 8px;">Path: <code><?php echo htmlspecialchars($php_error_log, ENT_QUOTES); ?></code></p>
            <pre class="log"><?php echo htmlspecialchars($php_log_content, ENT_QUOTES); ?></pre>
        <?php endif; ?>
    </div>
</div>
<?php
        $this->footer();
    }

    private function header($title) {
        $this->session->csrf_token();
        ?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($title, ENT_QUOTES); ?></title>
<style>
:root {
    --bg:#0f1419; --panel:#1a1f2e; --panel-b:#2a3142;
    --text:#e8e8e8; --muted:#8b95a7; --border:#2a3142;
    --primary:#4a9eff; --danger:#ff5c5c; --warn:#ffb74a; --ok:#4ade80;
    --radius:8px;
}
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
.wrap { max-width:1100px; margin:0 auto; padding:24px 20px; }
h1 { margin:0 0 4px; font-size:20px; font-weight:600; }
h2 { margin:0 0 12px; font-size:15px; font-weight:600; color:var(--text); }
.header { display:flex; justify-content:space-between; align-items:center; padding-bottom:20px; border-bottom:1px solid var(--border); margin-bottom:20px; }
.header small { color:var(--muted); font-size:12px; }
.panel { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px; margin-bottom:16px; }
.panel-danger { border-color:var(--danger); }
.grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:720px){.grid{grid-template-columns:1fr;}}
.btn { display:inline-block; background:var(--panel-b); color:var(--text); border:1px solid var(--border); padding:7px 14px; border-radius:6px; font-size:13px; cursor:pointer; text-decoration:none; font-family:inherit; }
.btn:hover { background:#343c52; }
.btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; }
.btn-primary:hover { background:#3a8fe0; }
.btn-danger { background:var(--danger); border-color:var(--danger); color:#fff; }
.btn-danger:hover { background:#e04040; }
.btn-warn { background:var(--warn); border-color:var(--warn); color:#000; }
.btn-sm { padding:3px 10px; font-size:12px; }
.row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; }
.log { background:#0a0d14; color:#b8c1d1; border:1px solid var(--border); border-radius:6px; padding:12px; font:12px/1.5 'Menlo',monospace; max-height:400px; overflow:auto; white-space:pre-wrap; word-break:break-word; margin:0; }
.tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; }
.tag-ok { background:rgba(74,222,128,0.15); color:var(--ok); }
.tag-warn { background:rgba(255,183,74,0.15); color:var(--warn); }
.tag-off { background:rgba(139,149,167,0.15); color:var(--muted); }
.notice { padding:10px 14px; border-radius:6px; margin-bottom:12px; }
.notice-ok { background:rgba(74,222,128,0.1); border:1px solid var(--ok); color:var(--ok); }
.notice-err { background:rgba(255,92,92,0.1); border:1px solid var(--danger); color:var(--danger); }
.notice-warn { background:rgba(255,183,74,0.1); border:1px solid var(--warn); color:var(--warn); }
table { width:100%; border-collapse:collapse; font-size:13px; }
td,th { padding:7px 10px; border-bottom:1px solid var(--border); text-align:left; }
th { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.3px; font-weight:600; }
tr:last-child td { border-bottom:0; }
code { background:rgba(255,255,255,0.08); padding:1px 6px; border-radius:4px; font:12px 'Menlo',monospace; }
input[type="password"],input[type="text"] { width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border); border-radius:6px; color:var(--text); font:14px inherit; }
input:focus { outline:2px solid var(--primary); outline-offset:-1px; border-color:var(--primary); }
.login-wrap { max-width:380px; margin:80px auto; padding:30px; background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); }
.login-wrap h1 { text-align:center; margin-bottom:20px; font-size:18px; }
.logo { text-align:center; color:var(--primary); font-size:32px; margin-bottom:6px; }
.footer { text-align:center; color:var(--muted); font-size:11px; padding:20px 0; }
</style>
</head>
<body>
<?php
    }

    private function footer() {
        ?>
<div class="footer">DB Debug Manager — Emergency Standalone · <a href="?a=logout" style="color:var(--muted);">Esci</a></div>
</body></html>
<?php
    }
}
