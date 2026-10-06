<?php
/**
 * DB Debug Manager — Standalone config parser/writer.
 *
 * IMPORTANTE: questa classe deve essere utilizzabile SIA con WordPress caricato
 * (dal plugin normale) SIA senza WordPress (da emergency.php).
 * Non deve usare nessuna funzione WP.
 *
 * 1.4.0: wp-config.php viene letto con il tokenizer di PHP, non con regex:
 * commenti, define condizionali, valori presi da getenv()/getenv_docker()
 * e define su più righe sono riconosciuti come li vede PHP. La scrittura è
 * atomica (file temporaneo + rename), preceduta da un backup verificato e
 * da un controllo di sintassi eseguito nel processo (nessun exec).
 */

if (class_exists('DBDM_Standalone_Config')) return;

class DBDM_Standalone_Config {

    /**
     * Cerca wp-config.php risalendo dalla posizione corrente fino alla
     * cartella che sta sopra ABSPATH (WordPress lo accetta anche lì, purché
     * quella cartella non contenga a sua volta un'installazione).
     */
    public static function find_wp_config($start_dir) {
        $dir = rtrim($start_dir, '/\\');
        for ($i = 0; $i < 5; $i++) {
            if (file_exists($dir . '/wp-config.php')) {
                return $dir . '/wp-config.php';
            }
            // Un livello sopra ABSPATH: solo se ABSPATH è il livello corrente.
            if (file_exists($dir . '/wp-settings.php')) {
                $parent = dirname($dir);
                if (file_exists($parent . '/wp-config.php') && !file_exists($parent . '/wp-settings.php')) {
                    return $parent . '/wp-config.php';
                }
                return false;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return false;
    }

    /**
     * Estrae credenziali DB e table_prefix da wp-config.php senza eseguirlo.
     *
     * Per ogni costante vale la prima define incondizionata (in PHP vince la
     * prima eseguita); in mancanza, la prima define il cui valore è noto.
     * Le define nei commenti sono ignorate. I valori presi da getenv() o
     * getenv_docker() (immagine Docker ufficiale, wp-env, molti hosting) sono
     * risolti come farebbe wp-config.php.
     */
    public static function parse_credentials($config_path) {
        if (!$config_path || !is_readable($config_path)) {
            return false;
        }
        $content = file_get_contents($config_path);
        if ($content === false) return false;

        $values = self::effective_defines($content);
        foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $key) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) return false;
        }

        $prefix  = self::table_prefix($content);
        $charset = isset($values['DB_CHARSET']) && is_string($values['DB_CHARSET']) && $values['DB_CHARSET'] !== ''
            ? $values['DB_CHARSET'] : 'utf8mb4';

        return array(
            'name'    => (string) $values['DB_NAME'],
            'user'    => (string) $values['DB_USER'],
            'pass'    => (string) $values['DB_PASSWORD'],
            'host'    => (string) $values['DB_HOST'],
            'charset' => $charset,
            'prefix'  => (is_string($prefix) && preg_match('/^[A-Za-z0-9_]+$/', $prefix)) ? $prefix : 'wp_',
        );
    }

    /**
     * Valore effettivo di ogni costante definita in wp-config.php, per quanto
     * determinabile senza eseguirlo (vedi parse_credentials()).
     *
     * @return array<string,mixed> nome => valore (solo valori noti).
     */
    public static function effective_defines($content) {
        $simple = array();
        $any    = array();
        foreach (self::find_defines($content) as $d) {
            if (!$d['known']) continue;
            // La prima incondizionata vince su tutto; altrimenti la prima nota.
            if ($d['simple'] && !array_key_exists($d['name'], $simple)) {
                $simple[$d['name']] = $d['value'];
            }
            if (!array_key_exists($d['name'], $any)) {
                $any[$d['name']] = $d['value'];
            }
        }
        return array_merge($any, $simple);
    }

    /**
     * Tutte le chiamate define() di wp-config.php, nell'ordine del file.
     *
     * Ogni voce: name, simple (istruzione a sé, fuori da blocchi e
     * condizioni), known/value (valore valutabile senza eseguire il file),
     * offset in byte dell'istruzione e del secondo argomento.
     *
     * @return array<int,array>
     */
    public static function find_defines($content) {
        $toks = self::tokenize($content);
        $n    = count($toks);
        $out  = array();

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!self::is_define_name($t)) continue;

            $prev = self::prev_significant($toks, $i);
            if ($prev !== null && in_array($toks[$prev]['id'], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
                continue;
            }
            if ($prev !== null && defined('T_NULLSAFE_OBJECT_OPERATOR') && $toks[$prev]['id'] === T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }
            $open = self::next_significant($toks, $i);
            if ($open === null || $toks[$open]['text'] !== '(') continue;

            // Argomenti al livello più esterno.
            $args  = array(array());
            $depth = 0;
            $close = null;
            for ($j = $open + 1; $j < $n; $j++) {
                $text = $toks[$j]['text'];
                if ($text === '(' || $text === '[' || $text === '{') $depth++;
                if ($text === ')' || $text === ']' || $text === '}') {
                    if ($depth === 0) {
                        $close = $j;
                        break;
                    }
                    $depth--;
                }
                if ($text === ',' && $depth === 0) {
                    $args[] = array();
                    continue;
                }
                $args[count($args) - 1][] = $j;
            }
            if ($close === null || count($args) < 2) continue;

            $name_toks = self::significant_only($toks, $args[0]);
            if (count($name_toks) !== 1 || $toks[$name_toks[0]]['id'] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $name = self::decode_string($toks[$name_toks[0]]['text']);
            if (!is_string($name)) continue;

            $value_idx = self::significant_only($toks, $args[1]);
            if (!$value_idx) continue;
            $eval = self::evaluate($toks, $value_idx);

            $after  = self::next_significant($toks, $close);
            $simple = $after !== null && $toks[$after]['text'] === ';'
                && $t['depth'] === 0
                && ($prev === null || in_array($toks[$prev]['text'], array(';', '}'), true) || $toks[$prev]['id'] === T_OPEN_TAG);

            $first_value = $value_idx[0];
            $last_value  = $value_idx[count($value_idx) - 1];
            $out[] = array(
                'name'        => $name,
                'simple'      => $simple,
                'known'       => $eval[0],
                'value'       => $eval[1],
                'start'       => $t['offset'],
                'end'         => $simple ? $toks[$after]['offset'] + 1 : $toks[$close]['offset'] + 1,
                'value_start' => $toks[$first_value]['offset'],
                'value_end'   => $toks[$last_value]['offset'] + strlen($toks[$last_value]['text']),
            );
        }
        return $out;
    }

    /**
     * Valore di $table_prefix: ultima assegnazione incondizionata (per una
     * variabile vince l'ultima), altrimenti l'ultima nota.
     *
     * @return string|null
     */
    public static function table_prefix($content) {
        $toks   = self::tokenize($content);
        $n      = count($toks);
        $simple = null;
        $any    = null;
        for ($i = 0; $i < $n; $i++) {
            if ($toks[$i]['id'] !== T_VARIABLE || $toks[$i]['text'] !== '$table_prefix') continue;
            $eq = self::next_significant($toks, $i);
            if ($eq === null || $toks[$eq]['text'] !== '=') continue;
            $expr = array();
            for ($j = $eq + 1; $j < $n && $toks[$j]['text'] !== ';'; $j++) {
                $expr[] = $j;
            }
            $eval = self::evaluate($toks, self::significant_only($toks, $expr));
            if (!$eval[0] || !is_string($eval[1])) continue;
            $any = $eval[1];
            $prev = self::prev_significant($toks, $i);
            if ($toks[$i]['depth'] === 0 && ($prev === null || in_array($toks[$prev]['text'], array(';', '}'), true) || $toks[$prev]['id'] === T_OPEN_TAG)) {
                $simple = $eval[1];
            }
        }
        return $simple !== null ? $simple : $any;
    }

    /**
     * Sostituisce o inserisce una define() nel sorgente di wp-config.
     * $php_value è già in forma sorgente ('true', 'false', "'stringa'").
     *
     * Regole (1.4.0):
     * - define incondizionata esistente: cambia solo il valore, il resto
     *   della riga (spazi, commento in coda) resta com'è; eventuali duplicati
     *   incondizionati successivi (lasciati dalle versioni precedenti, PHP li
     *   ignora con un warning) vengono rimossi;
     * - solo define condizionali (`defined() || define()`, `if (...)
     *   define()`): ne cambia il valore, mantenendo la condizione;
     * - nessuna define: inserita prima del commento "stop editing", o prima
     *   del require di wp-settings.php, o in coda;
     * - le define nei commenti non vengono mai toccate.
     * Logica CONDIVISA tra DBDM_Config (lato WP) ed emergency.php.
     */
    public static function replace_or_insert_constant($contents, $name, $php_value) {
        $all = array_values(array_filter(self::find_defines($contents), function ($d) use ($name) {
            return $d['name'] === $name;
        }));
        $simple = array_values(array_filter($all, function ($d) {
            return $d['simple'];
        }));

        if ($simple) {
            $edits = array(array($simple[0]['value_start'], $simple[0]['value_end'], $php_value));
            foreach (array_slice($simple, 1) as $duplicate) {
                $edits[] = self::removal_range($contents, $duplicate['start'], $duplicate['end']);
            }
            return self::apply_edits($contents, $edits);
        }

        if ($all) {
            $edits = array();
            foreach ($all as $d) {
                $edits[] = array($d['value_start'], $d['value_end'], $php_value);
            }
            return self::apply_edits($contents, $edits);
        }

        $eol  = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
        $line = "define( '{$name}', {$php_value} );";
        $pos  = self::insertion_offset($contents);
        if ($pos === null) {
            return rtrim($contents) . $eol . $eol . $line . $eol;
        }
        return substr($contents, 0, $pos) . $line . $eol . $eol . substr($contents, $pos);
    }

    /**
     * Controllo di sintassi PHP nel processo, con il parser di PHP
     * (token_get_all con TOKEN_PARSE): sempre disponibile, nessun exec né
     * binario esterno. Ritorna true se ok, stringa di errore altrimenti.
     */
    public static function php_lint_string($content) {
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $e) {
            return 'Errore di sintassi rilevato (riga ' . $e->getLine() . '). Modifica annullata.';
        }
        return true;
    }

    /**
     * Imposta una costante booleana in wp-config.php.
     * Ritorna true oppure una stringa di errore. Nessuna dipendenza WP.
     */
    public static function set_bool_constant($config_path, $name, $value, $backup_path = '') {
        return self::set_constants($config_path, array($name => $value ? 'true' : 'false'), $backup_path);
    }

    /**
     * Imposta più costanti in un'unica scrittura di wp-config.php.
     *
     * Ordine: lettura → modifiche in memoria → controllo di sintassi →
     * backup del file ORIGINALE (verificato; se non riesce, nulla viene
     * scritto) → scrittura atomica → verifica rileggendo il file.
     *
     * @param string               $config_path
     * @param array<string,string> $values      nome => valore in forma sorgente.
     * @param string               $backup_path '' per non fare il backup.
     * @return true|string
     */
    public static function set_constants($config_path, array $values, $backup_path = '') {
        if (!$config_path || !is_file($config_path)) return 'wp-config.php non trovato.';
        if (!is_writable($config_path)) return 'wp-config.php non scrivibile.';
        $content = file_get_contents($config_path);
        if ($content === false) return 'Lettura di wp-config.php non riuscita.';

        $new = $content;
        foreach ($values as $name => $php_value) {
            $new = self::replace_or_insert_constant($new, $name, $php_value);
        }
        if ($new === $content) return true; // Nessuna modifica necessaria.

        $lint = self::php_lint_string($new);
        if ($lint !== true) return $lint;

        if ($backup_path !== '') {
            $saved = self::write_file($backup_path, $content, 0640);
            if ($saved !== true) {
                return 'Backup di wp-config.php non riuscito (' . $saved . '): nessuna modifica effettuata.';
            }
        }

        $written = self::write_file($config_path, $new);
        if ($written !== true) {
            return 'Scrittura di wp-config.php non riuscita (' . $written . ').';
        }
        clearstatcache(true, $config_path);
        if (file_get_contents($config_path) !== $new) {
            self::write_file($config_path, $content);
            self::invalidate_opcache($config_path);
            return 'Verifica di wp-config.php non riuscita: ripristinato il contenuto precedente.';
        }
        self::invalidate_opcache($config_path);
        return true;
    }

    /**
     * Toglie un file dalla cache di OPcache (1.4.0): senza, le richieste dei
     * secondi successivi alla scrittura continuano a usare la versione
     * compilata precedente di wp-config.php (opcache.revalidate_freq).
     */
    public static function invalidate_opcache($path) {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Scrive un file in modo atomico: file temporaneo nella stessa cartella,
     * verifica dei byte scritti, rename. Se la cartella non è scrivibile (il
     * file sì), scrittura in place con lock e verifica.
     *
     * @param string   $path
     * @param string   $data
     * @param int|null $mode Permessi del file nuovo (se null: quelli del file
     *                       esistente, altrimenti 0644).
     * @return true|string
     */
    public static function write_file($path, $data, $mode = null) {
        $len = strlen($data);
        if ($mode === null) {
            $mode = file_exists($path) ? (fileperms($path) & 0777) : 0644;
        }

        $tmp = dirname($path) . '/.' . basename($path) . '.dbdm-' . bin2hex(random_bytes(6)) . '.tmp';
        $put = @file_put_contents($tmp, $data, LOCK_EX);
        if ($put === $len) {
            @chmod($tmp, $mode);
            if (@rename($tmp, $path)) {
                return true;
            }
        }
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        if ($put !== false && $put !== $len) {
            return 'spazio insufficiente o scrittura parziale';
        }

        // Cartella non scrivibile: in place, con lock e controllo.
        if (file_exists($path) && !is_writable($path)) {
            return 'file non scrivibile';
        }
        $fp = @fopen($path, 'c+');
        if (!$fp) return 'apertura non riuscita';
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return 'lock non ottenuto';
        }
        ftruncate($fp, 0);
        rewind($fp);
        $w = fwrite($fp, $data);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($w !== $len) {
            return 'scrittura parziale';
        }
        @chmod($path, $mode);
        return true;
    }

    /**
     * DSN PDO dai dati di wp-config.php. DB_HOST può essere `host`,
     * `host:porta`, `host:/percorso/socket`, `/percorso/socket`,
     * `[::1]:porta`, con l'eventuale prefisso `p:` (connessione persistente
     * di mysqli, non usato da PDO).
     */
    public static function build_dsn($creds) {
        $host = trim((string) $creds['host']);
        if (strpos($host, 'p:') === 0) {
            $host = substr($host, 2);
        }
        $port   = null;
        $socket = null;

        if ($host !== '' && $host[0] === '/') {
            $socket = $host;
            $host   = 'localhost';
        } elseif (preg_match('/^\[([0-9a-fA-F:.]+)\](?::(\d+))?$/', $host, $m)) {
            $host = $m[1];
            $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
        } elseif (substr_count($host, ':') === 1) {
            list($h, $rest) = explode(':', $host, 2);
            $host = $h !== '' ? $h : 'localhost';
            if (ctype_digit($rest)) {
                $port = (int) $rest;
            } elseif ($rest !== '') {
                $socket = $rest;
            }
        }

        $charset = isset($creds['charset']) && $creds['charset'] !== '' ? $creds['charset'] : 'utf8mb4';
        $dsn = $socket !== null
            ? 'mysql:unix_socket=' . $socket
            : 'mysql:host=' . $host . ($port ? ';port=' . $port : '');
        return $dsn . ';dbname=' . $creds['name'] . ';charset=' . $charset;
    }

    /**
     * Connessione PDO.
     */
    public static function connect($creds) {
        try {
            return new PDO(self::build_dsn($creds), $creds['user'], $creds['pass'], array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } catch (PDOException $e) {
            return false;
        }
    }

    /* =====================================================================
     * Tokenizer
     * ================================================================== */

    /**
     * Token normalizzati: id, text, offset in byte, profondità delle graffe.
     */
    private static function tokenize($content) {
        $raw    = token_get_all($content);
        $out    = array();
        $offset = 0;
        $depth  = 0;
        foreach ($raw as $t) {
            if (is_array($t)) {
                $id   = $t[0];
                $text = $t[1];
            } else {
                $id   = null;
                $text = $t;
            }
            if ($text === '}') $depth = max(0, $depth - 1);
            $out[] = array('id' => $id, 'text' => $text, 'offset' => $offset, 'depth' => $depth);
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) $depth++;
            $offset += strlen($text);
        }
        return $out;
    }

    private static function is_define_name($t) {
        if ($t['id'] === T_STRING) {
            return strtolower($t['text']) === 'define';
        }
        if (defined('T_NAME_FULLY_QUALIFIED') && $t['id'] === T_NAME_FULLY_QUALIFIED) {
            return strtolower($t['text']) === '\\define';
        }
        return false;
    }

    private static function is_insignificant($t) {
        return in_array($t['id'], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
    }

    private static function prev_significant($toks, $i) {
        for ($k = $i - 1; $k >= 0; $k--) {
            if ($toks[$k]['id'] === T_NS_SEPARATOR) continue; // \define( su PHP 7
            if (!self::is_insignificant($toks[$k])) return $k;
        }
        return null;
    }

    private static function next_significant($toks, $i) {
        $n = count($toks);
        for ($k = $i + 1; $k < $n; $k++) {
            if (!self::is_insignificant($toks[$k])) return $k;
        }
        return null;
    }

    private static function significant_only($toks, $idx) {
        return array_values(array_filter($idx, function ($k) use ($toks) {
            return !self::is_insignificant($toks[$k]);
        }));
    }

    /**
     * Valuta un'espressione semplice di wp-config.php senza eseguirla:
     * letterali (stringhe, numeri, true/false/null), negazioni (`!`, `!!`),
     * getenv('X') e getenv_docker('X', default).
     *
     * @param array $toks
     * @param int[] $idx  Indici dei token significativi dell'espressione.
     * @return array{0:bool,1:mixed} [noto, valore]
     */
    private static function evaluate($toks, $idx) {
        $count = count($idx);
        if ($count === 0) return array(false, null);

        $first = $toks[$idx[0]];
        if ($first['text'] === '!') {
            $inner = self::evaluate($toks, array_slice($idx, 1));
            return $inner[0] ? array(true, !$inner[1]) : array(false, null);
        }
        if ($first['text'] === '(' && $toks[$idx[$count - 1]]['text'] === ')') {
            return self::evaluate($toks, array_slice($idx, 1, -1));
        }
        if ($count === 1) {
            if ($first['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $s = self::decode_string($first['text']);
                return is_string($s) ? array(true, $s) : array(false, null);
            }
            if ($first['id'] === T_LNUMBER || $first['id'] === T_DNUMBER) {
                return array(true, $first['text'] + 0);
            }
            if ($first['id'] === T_STRING) {
                $lc = strtolower($first['text']);
                if ($lc === 'true') return array(true, true);
                if ($lc === 'false') return array(true, false);
                if ($lc === 'null') return array(true, null);
            }
            return array(false, null);
        }
        if ($count === 2 && ($first['text'] === '-' || $first['text'] === '+')) {
            $num = $toks[$idx[1]];
            if ($num['id'] === T_LNUMBER || $num['id'] === T_DNUMBER) {
                return array(true, ($first['text'] === '-' ? -1 : 1) * ($num['text'] + 0));
            }
        }

        // Chiamate getenv() / getenv_docker().
        if ($first['id'] === T_STRING && $toks[$idx[1]]['text'] === '(' && $toks[$idx[$count - 1]]['text'] === ')') {
            $fn   = strtolower($first['text']);
            $args = self::split_args($toks, array_slice($idx, 2, -1));
            if ($fn === 'getenv' && count($args) >= 1) {
                $var = self::evaluate($toks, $args[0]);
                if (!$var[0] || !is_string($var[1])) return array(false, null);
                return array(true, getenv($var[1]));
            }
            if ($fn === 'getenv_docker' && count($args) === 2) {
                $var = self::evaluate($toks, $args[0]);
                $def = self::evaluate($toks, $args[1]);
                if (!$var[0] || !is_string($var[1]) || !$def[0]) return array(false, null);
                $file = getenv($var[1] . '_FILE');
                if ($file && is_readable($file)) {
                    return array(true, rtrim((string) file_get_contents($file), "\r\n"));
                }
                $val = getenv($var[1]);
                return array(true, $val !== false ? $val : $def[1]);
            }
        }
        return array(false, null);
    }

    /**
     * Divide gli indici di una lista di argomenti alle virgole esterne.
     */
    private static function split_args($toks, $idx) {
        $args  = array(array());
        $depth = 0;
        foreach ($idx as $k) {
            $text = $toks[$k]['text'];
            if ($text === '(' || $text === '[') $depth++;
            if ($text === ')' || $text === ']') $depth--;
            if ($text === ',' && $depth === 0) {
                $args[] = array();
                continue;
            }
            $args[count($args) - 1][] = $k;
        }
        return $args;
    }

    /**
     * Valore di un letterale stringa PHP. Le stringhe con variabili
     * interpolate non sono valutabili: null.
     */
    private static function decode_string($literal) {
        $q    = $literal[0];
        $body = substr($literal, 1, -1);
        if ($q === "'") {
            return preg_replace_callback('/\\\\([\\\\\'])/', function ($m) {
                return $m[1];
            }, $body);
        }
        if ($q === '"') {
            if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*\$/', $body)) return null;
            return stripcslashes($body);
        }
        return null;
    }

    /**
     * Intervallo da rimuovere per un'istruzione: l'intera riga se
     * l'istruzione è l'unico codice presente (eventuale commento in coda
     * compreso), altrimenti solo l'istruzione.
     *
     * @return array{0:int,1:int,2:string}
     */
    private static function removal_range($contents, $start, $end) {
        $line_start = strrpos(substr($contents, 0, $start), "\n");
        $line_start = $line_start === false ? 0 : $line_start + 1;
        $line_end   = strpos($contents, "\n", $end);
        $line_end   = $line_end === false ? strlen($contents) : $line_end + 1;

        $before = substr($contents, $line_start, $start - $line_start);
        $after  = rtrim(substr($contents, $end, $line_end - $end), "\r\n");
        if (trim($before) === '' && (trim($after) === '' || preg_match('/^\s*(\/\/|#)/', $after))) {
            return array($line_start, $line_end, '');
        }
        return array($start, $end, '');
    }

    /**
     * Applica sostituzioni [inizio, fine, testo] partendo dalla fine.
     */
    private static function apply_edits($contents, $edits) {
        usort($edits, function ($a, $b) {
            return $b[0] - $a[0];
        });
        foreach ($edits as $e) {
            $contents = substr($contents, 0, $e[0]) . $e[2] . substr($contents, $e[1]);
        }
        return $contents;
    }

    /**
     * Dove inserire una nuova define: inizio della riga del commento
     * "stop editing", altrimenti del require/include di wp-settings.php.
     *
     * @return int|null
     */
    private static function insertion_offset($contents) {
        $toks = self::tokenize($contents);
        $n    = count($toks);
        $at   = null;
        // "That's all, stop editing!" prima di tutto: il commento "Add any
        // custom values … stop editing line" che lo precede lo cita.
        foreach (array("that's all", 'stop editing') as $needle) {
            for ($i = 0; $i < $n && $at === null; $i++) {
                $t = $toks[$i];
                if (($t['id'] === T_COMMENT || $t['id'] === T_DOC_COMMENT) && $t['depth'] === 0 && stripos($t['text'], $needle) !== false) {
                    $at = $t['offset'];
                }
            }
        }
        for ($i = 0; $i < $n && $at === null; $i++) {
            $t = $toks[$i];
            if (in_array($t['id'], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE), true) && $t['depth'] === 0) {
                for ($j = $i + 1; $j < $n && $toks[$j]['text'] !== ';'; $j++) {
                    if (stripos($toks[$j]['text'], 'wp-settings.php') !== false) {
                        $at = $t['offset'];
                        break;
                    }
                }
            }
        }
        if ($at === null) return null;
        $line_start = strrpos(substr($contents, 0, $at), "\n");
        return $line_start === false ? 0 : $line_start + 1;
    }
}
