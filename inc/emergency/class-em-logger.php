<?php
/**
 * DB Debug Manager — Emergency: log degli accessi.
 *
 * Una riga per evento (login, blocchi, azioni) in emergency-access.log
 * nella cartella privata.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Logger')) return;

class DBDM_Em_Logger {

    /** @var string */
    private $file;

    /** @var string */
    private $ip;

    /** @var string */
    private $user_agent;

    public function __construct($file, $ip, $user_agent) {
        $this->file       = $file;
        $this->ip         = $ip;
        $this->user_agent = $user_agent;
    }

    public function log($event, $detail = '', $now = null) {
        $line = sprintf(
            "[%s] %s | IP=%s | UA=%s | %s\n",
            gmdate('Y-m-d H:i:s', $now === null ? time() : (int) $now),
            $event,
            $this->ip,
            substr($this->user_agent, 0, 120),
            $detail
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
