<?php
/**
 * DB Debug Manager — Standalone config parser.
 *
 * IMPORTANTE: questa classe deve essere utilizzabile SIA con WordPress caricato
 * (dal plugin normale) SIA senza WordPress (da emergency.php).
 * Non deve usare nessuna funzione WP.
 */

if (class_exists('DBDM_Standalone_Config')) return;

class DBDM_Standalone_Config {

    /**
     * Cerca wp-config.php risalendo fino a 3 livelli dalla posizione corrente.
     */
    public static function find_wp_config($start_dir) {
        $dir = $start_dir;
        for ($i = 0; $i < 4; $i++) {
            if (file_exists($dir . '/wp-config.php')) {
                return $dir . '/wp-config.php';
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return false;
    }

    /**
     * Estrae credenziali DB + table_prefix + secret keys da wp-config.php
     * usando regex (niente eval, niente include).
     */
    public static function parse_credentials($config_path) {
        if (!$config_path || !is_readable($config_path)) {
            return false;
        }
        $content = file_get_contents($config_path);
        if ($content === false) return false;

        $defs = self::extract_defines($content);
        $prefix = self::extract_table_prefix($content);

        $required = array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST');
        foreach ($required as $key) {
            if (!isset($defs[$key])) return false;
        }

        return array(
            'name'    => $defs['DB_NAME'],
            'user'    => $defs['DB_USER'],
            'pass'    => $defs['DB_PASSWORD'],
            'host'    => $defs['DB_HOST'],
            'charset' => isset($defs['DB_CHARSET']) ? $defs['DB_CHARSET'] : 'utf8mb4',
            'prefix'  => $prefix ?: 'wp_',
        );
    }

    private static function extract_defines($content) {
        $out = array();
        // Match: define('KEY', 'value'); (stringhe singole/doppie, bool, numeri)
        if (preg_match_all('/define\s*\(\s*[\'"]([A-Z_][A-Z0-9_]*)[\'"]\s*,\s*(.+?)\s*\)\s*;/i', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $key = $match[1];
                $raw = trim($match[2]);
                // Stringa
                if (preg_match('/^[\'"](.*)[\'"]$/s', $raw, $s)) {
                    $out[$key] = stripslashes($s[1]);
                } elseif (strtolower($raw) === 'true') {
                    $out[$key] = true;
                } elseif (strtolower($raw) === 'false') {
                    $out[$key] = false;
                } elseif (is_numeric($raw)) {
                    $out[$key] = $raw + 0;
                }
            }
        }
        return $out;
    }

    private static function extract_table_prefix($content) {
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $content, $m)) {
            return $m[1];
        }
        return false;
    }

    /**
     * Sostituisce o inserisce una define() nel sorgente di wp-config.
     * $php_value è già in forma sorgente ('true', 'false', "'stringa'").
     * Regole:
     * - Se la costante esiste (anche commentata con // o #), viene sostituita la riga.
     * - Altrimenti viene inserita prima di "/* That's all, stop editing!".
     * Logica CONDIVISA tra DBDM_Config (lato WP) ed emergency.php: modificala
     * solo qui per evitare divergenze.
     */
    public static function replace_or_insert_constant($contents, $name, $php_value) {
        $new_line = "define('{$name}', {$php_value});";

        // Regex: cattura righe define della costante, anche commentate.
        $pattern = '/^[ \t]*(?:\/\/|#|\/\*)?[ \t]*define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,.*?\)\s*;[ \t]*(?:\*\/)?[ \t]*(\r?\n|$)/mi';

        if (preg_match($pattern, $contents)) {
            return preg_replace($pattern, $new_line . "\n", $contents, 1);
        }

        // Inserimento prima del marker di fine editing.
        $marker = "/* That's all, stop editing!";
        $pos = strpos($contents, $marker);
        if ($pos !== false) {
            return substr($contents, 0, $pos) . $new_line . "\n\n" . substr($contents, $pos);
        }

        // Fallback: inserisci prima del require di wp-settings.php.
        $fallback = '/^(require_once\s*[\(\s].*?wp-settings\.php.*?;)/mi';
        if (preg_match($fallback, $contents)) {
            return preg_replace($fallback, $new_line . "\n\n$1", $contents, 1);
        }

        // Ultimo fallback: in coda.
        return rtrim($contents) . "\n\n" . $new_line . "\n";
    }

    /**
     * Verifica sintassi PHP di una stringa via `-l` su file temporaneo.
     * Ritorna true se ok (o se il lint non è eseguibile: best effort),
     * stringa di errore altrimenti. Nessuna dipendenza WP.
     *
     * Nota: nel contesto web PHP_BINARY punta spesso a php-fpm, che NON
     * supporta il lint e fallisce sempre (falsi "errore di sintassi").
     * Per questo il binario viene prima calibrato: deve dare exit 0 su
     * codice valido ed exit != 0 su codice rotto, altrimenti si passa
     * al candidato successivo o si salta il lint.
     */
    public static function php_lint_string($content) {
        if (!function_exists('exec')) {
            return true; // skip se exec disabilitata
        }
        $php = self::lint_binary();
        if ($php === '') {
            return true; // nessun binario in grado di lintare: non bloccare
        }
        $code = self::run_lint($php, $content);
        if ($code !== 0) {
            return 'Errore di sintassi rilevato. Modifica annullata.';
        }
        return true;
    }

    /**
     * Esegue `binario -l` sul contenuto (via file temporaneo).
     * Ritorna l'exit code, o -1 se impossibile eseguire.
     */
    private static function run_lint($php, $content) {
        $tmp = tempnam(sys_get_temp_dir(), 'dbdm-lint');
        if (!$tmp || file_put_contents($tmp, $content) === false) {
            return -1;
        }
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        @exec($cmd, $output, $code);
        @unlink($tmp);
        return is_int($code) ? $code : -1;
    }

    /**
     * Trova un binario PHP capace di lintare. Ritorna '' se nessuno.
     * Calibrazione: exit 0 su "<?php" valido E exit != 0 su codice rotto.
     */
    private static function lint_binary() {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $candidates = array();
        if (defined('PHP_BINARY') && PHP_BINARY) {
            // php-fpm non supporta -l: escluso a priori.
            if (stripos(basename(PHP_BINARY), 'fpm') === false) {
                $candidates[] = PHP_BINARY;
            }
        }
        $candidates[] = 'php'; // CLI di sistema nel PATH

        foreach (array_unique($candidates) as $php) {
            $ok_valid  = self::run_lint($php, "<?php\n\$dbdm = 1;\n");
            $ok_broken = self::run_lint($php, "<?php\ndefine('X', true;\n");
            if ($ok_valid === 0 && $ok_broken > 0) {
                return $resolved = $php;
            }
        }
        return $resolved = '';
    }

    /**
     * Imposta una costante booleana in wp-config.php in modo sicuro:
     * backup, sostituzione/inserimento, lint PRE-scrittura, poi scrittura.
     * Ritorna true oppure una stringa di errore. Nessuna dipendenza WP.
     *
     * @param string $config_path Path di wp-config.php.
     * @param string $name        Nome costante.
     * @param bool   $value       Valore.
     * @param string $backup_path Dove salvare il backup pre-modifica ('' per saltare).
     */
    public static function set_bool_constant($config_path, $name, $value, $backup_path = '') {
        if (!is_writable($config_path)) return 'wp-config.php non scrivibile.';
        $content = file_get_contents($config_path);
        if ($content === false) return 'lettura fallita';

        if ($backup_path) {
            @copy($config_path, $backup_path);
        }

        $new = self::replace_or_insert_constant($content, $name, $value ? 'true' : 'false');
        if ($new === $content) return true; // nessuna modifica necessaria

        $lint = self::php_lint_string($new);
        if ($lint !== true) return $lint;

        if (file_put_contents($config_path, $new) === false) return 'scrittura fallita';
        return true;
    }

    /**
     * Connessione PDO.
     */
    public static function connect($creds) {
        $host = $creds['host'];
        $port = 3306;
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            (int) $port,
            $creds['name'],
            $creds['charset']
        );

        try {
            $pdo = new PDO($dsn, $creds['user'], $creds['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            return $pdo;
        } catch (PDOException $e) {
            return false;
        }
    }
}
