<?php
/**
 * DB Debug Manager — Emergency: richiesta HTTP.
 *
 * Copia di $_GET, $_POST e $_SERVER presa all'avvio: le altre classi
 * dell'emergency leggono l'input solo da qui, già tipizzato (stringhe o
 * flag), e nei test si costruisce con array qualsiasi.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Request')) return;

class DBDM_Em_Request {

    /** @var array */
    private $get;

    /** @var array */
    private $post;

    /** @var array */
    private $server;

    public function __construct(array $get, array $post, array $server) {
        $this->get    = $get;
        $this->post   = $post;
        $this->server = $server;
    }

    public static function from_globals() {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function is_post() {
        return isset($this->server['REQUEST_METHOD']) && $this->server['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Azione richiesta (`a`), dal POST o dalla query string come
     * $_REQUEST: solo lettere minuscole e underscore.
     */
    public function action() {
        $a = isset($this->post['a']) ? $this->post['a'] : (isset($this->get['a']) ? $this->get['a'] : '');
        return is_string($a) ? preg_replace('/[^a-z_]/', '', $a) : '';
    }

    public function has_post($key) {
        return isset($this->post[$key]);
    }

    /**
     * Campo POST come stringa: '' se assente o di un altro tipo (array).
     */
    public function post_string($key) {
        return isset($this->post[$key]) && is_string($this->post[$key]) ? $this->post[$key] : '';
    }

    public function post_flag($key) {
        return !empty($this->post[$key]);
    }

    /**
     * IP del client (vedi DBDM_Emergency_Guard::client_ip()).
     */
    public function ip($trust_proxy) {
        return DBDM_Emergency_Guard::client_ip($this->server, $trust_proxy);
    }

    public function user_agent() {
        return isset($this->server['HTTP_USER_AGENT']) && is_string($this->server['HTTP_USER_AGENT'])
            ? $this->server['HTTP_USER_AGENT'] : '-';
    }

    /**
     * URL della pagina senza query string: destinazione dei redirect.
     */
    public function path() {
        $uri = isset($this->server['REQUEST_URI']) && is_string($this->server['REQUEST_URI']) ? $this->server['REQUEST_URI'] : '';
        $parts = explode('?', $uri, 2);
        return $parts[0];
    }

    /**
     * Cartella dello script, con slash finale: percorso del cookie di sessione.
     */
    public function script_dir() {
        $script = isset($this->server['SCRIPT_NAME']) && is_string($this->server['SCRIPT_NAME']) ? $this->server['SCRIPT_NAME'] : '/';
        return rtrim(dirname($script), '/') . '/';
    }

    public function is_https() {
        return !empty($this->server['HTTPS']);
    }
}
