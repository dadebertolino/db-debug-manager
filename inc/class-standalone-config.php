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
