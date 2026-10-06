<?php
/**
 * DB Debug Manager — Regole di sicurezza dell'accesso emergency.
 *
 * Logica pura usata da emergency.php (senza WordPress): IP del client,
 * limite dei tentativi, legame della sessione all'epoca, lettura sicura dei
 * valori serializzati, validazione degli snapshot da ripristinare.
 * Nessuna funzione WP, nessun effetto al caricamento: testabile da sola.
 *
 * @since 1.4.0
 */

if (class_exists('DBDM_Emergency_Guard')) return;

class DBDM_Emergency_Guard {

    const MAX_ATTEMPTS = 5;
    const LOCKOUT_SEC  = 900; // 15 minuti dall'ultimo tentativo fallito.

    /* =====================================================================
     * IP del client
     * ================================================================== */

    /**
     * IP del client per limite dei tentativi e log.
     *
     * Di default solo REMOTE_ADDR, non falsificabile. Con "sito dietro proxy
     * o CDN fidato" si usa l'ULTIMO valore di X-Forwarded-For, cioè quello
     * aggiunto dal proxy più vicino (Cloudflare compreso, che lo imposta).
     * 1.4.0: CF-Connecting-IP non è più letto: senza verificare che la
     * richiesta arrivi davvero da Cloudflare, il client lo sceglie a piacere.
     *
     * @param array $server      $_SERVER.
     * @param bool  $trust_proxy
     * @return string
     */
    public static function client_ip(array $server, $trust_proxy) {
        if ($trust_proxy && !empty($server['HTTP_X_FORWARDED_FOR']) && is_string($server['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $server['HTTP_X_FORWARDED_FOR']);
            $ip    = trim(end($parts));
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        if (!empty($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR'])) {
            $ip = trim($server['REMOTE_ADDR']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return '0.0.0.0';
    }

    /**
     * Chiave del limite dei tentativi: l'indirizzo IPv4, o la rete /64 per
     * IPv6 (un singolo client dispone di solito di un'intera /64).
     */
    public static function rate_key($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $ip;
    }

    /* =====================================================================
     * Limite dei tentativi
     * ================================================================== */

    /**
     * Prenota un tentativo di login per $key, in modo atomico (lock sul
     * file per tutta la lettura-modifica-scrittura).
     *
     * Il tentativo viene contato PRIMA della verifica della password: con
     * richieste in parallelo nessuna può superare il limite. Al login
     * riuscito il contatore si azzera (reset()).
     *
     * Se il file non è utilizzabile il login viene RIFIUTATO: senza limite
     * dei tentativi la password sarebbe esposta a forza bruta.
     *
     * @param string   $file
     * @param string   $key
     * @param int|null $now  Orologio, per i test.
     * @return array{allowed:bool,retry_after:int,error:string}
     */
    public static function reserve_attempt($file, $key, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $result = self::with_locked_json($file, function ($data) use ($key, $now) {
            $data  = self::prune($data, $now);
            $entry = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : array('count' => 0, 'last' => 0);
            if ((int) $entry['count'] >= self::MAX_ATTEMPTS) {
                $retry = self::LOCKOUT_SEC - ($now - (int) $entry['last']);
                return array($data, array('allowed' => false, 'retry_after' => max(1, $retry), 'error' => ''));
            }
            $entry['count'] = (int) $entry['count'] + 1;
            $entry['last']  = $now;
            $data[$key]     = $entry;
            return array($data, array('allowed' => true, 'retry_after' => 0, 'error' => ''));
        });
        if ($result === null) {
            return array('allowed' => false, 'retry_after' => 0, 'error' => 'storage');
        }
        return $result;
    }

    /**
     * Secondi di blocco rimanenti per $key (0 se libero), senza contare un
     * tentativo: per mostrare il blocco sulla pagina di login.
     */
    public static function locked_for($file, $key, $now = null) {
        $now  = $now === null ? time() : (int) $now;
        $data = self::prune(self::read_json($file), $now);
        if (isset($data[$key]['count']) && (int) $data[$key]['count'] >= self::MAX_ATTEMPTS) {
            return max(1, self::LOCKOUT_SEC - ($now - (int) $data[$key]['last']));
        }
        return 0;
    }

    /**
     * Azzera il contatore di $key (login riuscito).
     */
    public static function reset($file, $key) {
        self::with_locked_json($file, function ($data) use ($key) {
            unset($data[$key]);
            return array($data, true);
        });
    }

    /**
     * Scarta le voci scadute: il blocco dura LOCKOUT_SEC dall'ultimo
     * tentativo fallito (1.4.0; prima contava dal primo).
     */
    private static function prune($data, $now) {
        foreach ($data as $k => $entry) {
            if (!is_array($entry) || $now - (int) ($entry['last'] ?? 0) > self::LOCKOUT_SEC) {
                unset($data[$k]);
            }
        }
        return $data;
    }

    private static function read_json($file) {
        $raw  = is_readable($file) ? @file_get_contents($file) : '';
        $data = $raw ? json_decode($raw, true) : array();
        return is_array($data) ? $data : array();
    }

    /**
     * Legge, trasforma e riscrive un file JSON sotto lock esclusivo.
     *
     * @param string   $file
     * @param callable $fn   function(array $data): array{0:array,1:mixed}
     * @return mixed Il secondo valore restituito da $fn, null se il file
     *               non è utilizzabile.
     */
    private static function with_locked_json($file, $fn) {
        $fp = @fopen($file, 'c+');
        if (!$fp) return null;
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }
        $raw  = stream_get_contents($fp);
        $data = $raw ? json_decode($raw, true) : array();
        if (!is_array($data)) $data = array();

        list($data, $result) = $fn($data);

        $json = json_encode($data);
        ftruncate($fp, 0);
        rewind($fp);
        $ok = fwrite($fp, $json) === strlen($json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $ok ? $result : null;
    }

    /* =====================================================================
     * Sessione
     * ================================================================== */

    /**
     * Impronta che lega una sessione alla password e all'epoca correnti:
     * cambiando password, abilitazione o disattivando il plugin l'epoca
     * cambia e tutte le sessioni aperte decadono.
     */
    public static function session_fingerprint($stored_hash, $epoch) {
        return hash_hmac('sha256', 'dbdm-emergency-session', (string) $stored_hash . '|' . (string) $epoch);
    }

    /* =====================================================================
     * Valori dal database
     * ================================================================== */

    /**
     * Valore di un'opzione come lo restituirebbe get_option(): i valori
     * serializzati vengono decodificati SENZA istanziare oggetti.
     */
    public static function maybe_unserialize($value) {
        if (!is_string($value) || !preg_match('/^(a|s|i|b|d|N)[:;]/', $value)) {
            return $value;
        }
        $u = @unserialize($value, array('allowed_classes' => false));
        if ($u === false && $value !== 'b:0;') {
            return $value;
        }
        return $u;
    }

    /* =====================================================================
     * Snapshot
     * ================================================================== */

    /**
     * Plugin di uno snapshot che si possono riattivare: percorsi relativi
     * alla cartella dei plugin (`cartella/file.php` o `file.php`), senza
     * risalite, che puntano a un file .php esistente.
     *
     * @param mixed  $list
     * @param string $plugins_dir
     * @return array{0:string[],1:string[]} [validi, scartati]
     */
    public static function restorable_plugins($list, $plugins_dir) {
        $valid   = array();
        $skipped = array();
        foreach (is_array($list) ? $list : array() as $p) {
            if (!is_string($p)
                || !preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)?\.php$#', $p)
                || strpos($p, '..') !== false
                || !is_file(rtrim($plugins_dir, '/') . '/' . $p)
            ) {
                $skipped[] = is_scalar($p) ? (string) $p : gettype($p);
                continue;
            }
            $valid[] = $p;
        }
        return array(array_values(array_unique($valid)), $skipped);
    }

    /**
     * Tema di uno snapshot, se si può attivare: slug semplice, cartella con
     * style.css, e tema padre (dichiarato in style.css) installato. Il padre
     * è letto dal tema stesso, non dallo snapshot.
     *
     * @param mixed  $stylesheet
     * @param string $themes_dir
     * @return array{ok:bool,stylesheet:string,template:string,error:string}
     */
    public static function restorable_theme($stylesheet, $themes_dir) {
        $fail = function ($error) use ($stylesheet) {
            return array('ok' => false, 'stylesheet' => is_string($stylesheet) ? $stylesheet : '', 'template' => '', 'error' => $error);
        };
        if (!self::is_theme_slug($stylesheet)) return $fail('slug non valido');

        $dir = rtrim($themes_dir, '/') . '/' . $stylesheet;
        if (!is_file($dir . '/style.css')) return $fail('tema non installato');

        $template = $stylesheet;
        $header   = (string) @file_get_contents($dir . '/style.css', false, null, 0, 8192);
        if (preg_match('/^[ \t\/*#@]*Template:(.*)$/mi', $header, $m) && trim($m[1]) !== '') {
            $template = trim($m[1]);
            if (!self::is_theme_slug($template) || !is_file(rtrim($themes_dir, '/') . '/' . $template . '/style.css')) {
                return $fail('tema padre non installato: ' . $template);
            }
        }
        return array('ok' => true, 'stylesheet' => $stylesheet, 'template' => $template, 'error' => '');
    }

    private static function is_theme_slug($slug) {
        return is_string($slug) && $slug !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $slug) && strpos($slug, '..') === false;
    }
}
