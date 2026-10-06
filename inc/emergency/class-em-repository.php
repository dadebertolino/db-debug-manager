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

    /** @var string */
    private $sitemeta;

    /** @var int|null Rete (multisite), null su un sito singolo. */
    private $site_id;

    /**
     * @param PDO      $pdo
     * @param string   $prefix  Prefisso delle tabelle ($table_prefix).
     * @param int|null $site_id Rete in multisite (SITE_ID_CURRENT_SITE),
     *                          null su un sito singolo.
     */
    public function __construct(PDO $pdo, $prefix, $site_id = null) {
        $this->pdo      = $pdo;
        $this->table    = '`' . $prefix . 'options`';
        $this->sitemeta = '`' . $prefix . 'sitemeta`';
        $this->site_id  = $site_id === null ? null : (int) $site_id;
    }

    public function is_network() {
        return $this->site_id !== null;
    }

    /**
     * Plugin attivi in rete (2.0.0, bug 26), null su un sito singolo o se
     * il valore manca o non è leggibile.
     *
     * @return string[]|null
     */
    public function network_plugins() {
        $all = $this->network_plugins_raw();
        return $all === null ? null : array_keys($all);
    }

    /**
     * Toglie plugin dall'elenco di rete, conservando la data di attivazione
     * degli altri.
     *
     * @param string[] $remove
     */
    public function remove_network_plugins(array $remove) {
        $all = $this->network_plugins_raw();
        if ($all === null) return;
        $this->pdo->prepare("UPDATE {$this->sitemeta} SET meta_value = :v WHERE site_id = :s AND meta_key = 'active_sitewide_plugins'")
            ->execute(array(':v' => serialize(array_diff_key($all, array_flip($remove))), ':s' => $this->site_id));
    }

    private function network_plugins_raw() {
        if ($this->site_id === null) return null;
        $stmt = $this->pdo->prepare("SELECT meta_value FROM {$this->sitemeta} WHERE site_id = :s AND meta_key = 'active_sitewide_plugins' LIMIT 1");
        $stmt->execute(array(':s' => $this->site_id));
        $v = DBDM_Emergency_Guard::maybe_unserialize($stmt->fetchColumn());
        return is_array($v) ? $v : null;
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
     * Salva un'opzione, creandola se manca (autoload spento), come
     * update_option().
     */
    public function save_option($name, $value) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE option_name = :n LIMIT 1");
        $stmt->execute(array(':n' => $name));
        if ($stmt->fetchColumn() !== false) {
            $this->update_option($name, $value);
            return;
        }
        $this->pdo->prepare("INSERT INTO {$this->table} (option_name, option_value, autoload) VALUES (:n, :v, 'no')")
            ->execute(array(':n' => $name, ':v' => is_array($value) ? serialize($value) : $value));
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
     * Cancella transient e transient di sito (anche i timeout), in
     * multisite anche quelli della rete.
     *
     * @return int Righe eliminate.
     */
    public function delete_transients() {
        $deleted = (int) $this->pdo->exec(
            "DELETE FROM {$this->table} WHERE option_name LIKE '!_transient!_%' ESCAPE '!' OR option_name LIKE '!_site!_transient!_%' ESCAPE '!'"
        );
        if ($this->site_id !== null) {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->sitemeta} WHERE site_id = :s AND meta_key LIKE '!_site!_transient!_%' ESCAPE '!'");
            $stmt->execute(array(':s' => $this->site_id));
            $deleted += $stmt->rowCount();
        }
        return $deleted;
    }
}
