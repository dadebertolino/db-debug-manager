<?php
/**
 * DB Debug Manager — Emergency: log degli accessi.
 *
 * Una riga per evento (login, blocchi, azioni) in emergency-access.log
 * nella cartella privata, con rotazione a 1 MB.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Logger')) return;

class DBDM_Em_Logger {

    /** Oltre questa dimensione il file passa a .1 (una sola copia). */
    const MAX_BYTES = 1048576;

    /** @var string */
    private $file;

    /** @var string */
    private $ip;

    /** @var string */
    private $user_agent;

    /** @var int */
    private $max_bytes;

    public function __construct($file, $ip, $user_agent, $max_bytes = self::MAX_BYTES) {
        $this->file       = $file;
        $this->ip         = $ip;
        $this->user_agent = $user_agent;
        $this->max_bytes  = (int) $max_bytes;
    }

    public function log($event, $detail = '', $now = null) {
        $line = sprintf(
            "[%s] %s | IP=%s | UA=%s | %s\n",
            gmdate('Y-m-d H:i:s', $now === null ? time() : (int) $now),
            self::clean($event),
            self::clean($this->ip),
            self::clean(substr($this->user_agent, 0, 120)),
            self::clean($detail)
        );
        clearstatcache(true, $this->file);
        if (@filesize($this->file) >= $this->max_bytes) {
            @rename($this->file, $this->file . '.1');
        }
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Caratteri di controllo resi visibili (\n, \r, \t, \xNN): un valore
     * inviato dal client non può aggiungere righe al log (2.0.0, bug 36).
     */
    private static function clean($value) {
        return preg_replace_callback('/[\x00-\x1F\x7F]/', function ($m) {
            $map = array("\n" => '\n', "\r" => '\r', "\t" => '\t');
            return isset($map[$m[0]]) ? $map[$m[0]] : sprintf('\x%02x', ord($m[0]));
        }, (string) $value);
    }
}
