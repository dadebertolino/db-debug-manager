<?php
/**
 * DB Debug Manager — Emergency: accesso alla tabella delle opzioni via PDO.
 *
 * Unico punto in cui l'emergency legge e scrive il database. Le query sono
 * SQL comune: girano su MySQL in produzione e su SQLite negli unit test.
 *
 * @since 2.0.0
 */

if (class_exists('DBDM_Em_Repository')) return;

class DBDM_Em_Repository {

    /** @var PDO */
    private $pdo;

    /** @var string */
    private $table;

    /**
     * @param PDO    $pdo
     * @param string $prefix Prefisso delle tabelle ($table_prefix).
     */
    public function __construct(PDO $pdo, $prefix) {
        $this->pdo   = $pdo;
        $this->table = '`' . $prefix . 'options`';
    }

    /**
     * Valore di un'opzione come lo restituirebbe get_option(): i valori
     * serializzati vengono decodificati senza istanziare oggetti.
     */
    public function get_option($name, $default = null) {
        $stmt = $this->pdo->prepare("SELECT option_value FROM {$this->table} WHERE option_name = :n LIMIT 1");
        $stmt->execute(array(':n' => $name));
        $v = $stmt->fetchColumn();
        if ($v === false) return $default;
        return DBDM_Emergency_Guard::maybe_unserialize($v);
    }

    /**
     * Aggiorna un'opzione esistente (gli array vengono serializzati).
     */
    public function update_option($name, $value) {
        $this->pdo->prepare("UPDATE {$this->table} SET option_value = :v WHERE option_name = :n")
            ->execute(array(':v' => is_array($value) ? serialize($value) : $value, ':n' => $name));
    }

    /**
     * Plugin attivi del sito, null se l'opzione manca o non è un elenco.
     *
     * @return string[]|null
     */
    public function active_plugins() {
        $v = $this->get_option('active_plugins');
        return is_array($v) ? $v : null;
    }

    /**
     * Cancella transient e transient di sito (anche i timeout).
     *
     * @return int Righe eliminate.
     */
    public function delete_transients() {
        return (int) $this->pdo->exec(
            "DELETE FROM {$this->table} WHERE option_name LIKE '!_transient!_%' ESCAPE '!' OR option_name LIKE '!_site!_transient!_%' ESCAPE '!'"
        );
    }
}
