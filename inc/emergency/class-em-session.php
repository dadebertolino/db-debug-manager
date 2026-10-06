<?php
/**
 * DB Debug Manager — Emergency: sessione e token CSRF.
 *
 * Lavora su un array passato per riferimento ($_SESSION in produzione, un
 * array qualsiasi nei test). L'avvio della sessione PHP (cookie, modalità
 * stretta) è in start(); il resto non tocca né header né cookie.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Session')) return;

class DBDM_Em_Session {

    const TTL = 1800; // 30 minuti.

    /** @var array */
    private $store;

    public function __construct(array &$store) {
        $this->store = &$store;
    }

    /**
     * Avvia la sessione PHP con cookie limitato alla cartella del plugin.
     * 1.4.0: modalità stretta (PHP rifiuta ID di sessione scelti dal client),
     * solo cookie, durata lato server non inferiore a quella dichiarata.
     */
    public static function start(DBDM_Em_Request $request) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if ((int) ini_get('session.gc_maxlifetime') < self::TTL) {
            ini_set('session.gc_maxlifetime', (string) self::TTL);
        }
        session_name('dbdm_emergency');
        session_set_cookie_params(array(
            'lifetime' => self::TTL,
            'path'     => $request->script_dir(),
            'secure'   => $request->is_https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ));
        session_start();
    }

    public function csrf_token() {
        if (empty($this->store['dbdm_csrf'])) {
            $this->store['dbdm_csrf'] = bin2hex(random_bytes(16));
        }
        return $this->store['dbdm_csrf'];
    }

    /**
     * @param string $posted Token inviato con il modulo ('' se assente).
     */
    public function csrf_check($posted) {
        return !empty($this->store['dbdm_csrf']) && is_string($this->store['dbdm_csrf'])
            && is_string($posted) && hash_equals($this->store['dbdm_csrf'], $posted);
    }

    /**
     * Sessione autenticata e ancora valida. 1.4.0: decade se password,
     * abilitazione o stato del plugin sono cambiati dopo il login (impronta
     * legata all'epoca, vedi DBDM_Emergency_Guard::session_fingerprint()).
     *
     * @param string   $fingerprint Impronta attuale.
     * @param int|null $now         Orologio, per i test.
     */
    public function is_authed($fingerprint, $now = null) {
        $now = $now === null ? time() : (int) $now;
        if (empty($this->store['dbdm_authed'])) return false;
        if (empty($this->store['dbdm_auth_time'])) return false;
        if (empty($this->store['dbdm_fingerprint']) || !hash_equals((string) $fingerprint, (string) $this->store['dbdm_fingerprint'])) {
            $this->clear();
            return false;
        }
        if ($now - (int) $this->store['dbdm_auth_time'] > self::TTL) {
            $this->clear();
            return false;
        }
        return true;
    }

    /**
     * Segna la sessione come autenticata. L'ID di sessione va rigenerato
     * prima (session_regenerate_id(), in DBDM_Em_App) e il token CSRF del
     * login non vale più.
     */
    public function login($fingerprint, $now = null) {
        $this->store['dbdm_authed']      = true;
        $this->store['dbdm_auth_time']   = $now === null ? time() : (int) $now;
        $this->store['dbdm_fingerprint'] = (string) $fingerprint;
        unset($this->store['dbdm_csrf']);
    }

    public function clear() {
        $this->store = array();
    }
}
