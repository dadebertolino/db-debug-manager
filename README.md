# DB Debug Manager

Plugin WordPress per gestire il debug direttamente dal pannello di amministrazione, senza più aprire l'FTP per modificare `wp-config.php` o scaricare `debug.log`. Include un **sistema di accesso emergency standalone** che funziona anche quando WordPress è crashato.

**Autore:** Davide Bertolino · [davidebertolino.it](https://www.davidebertolino.it)
**Versione:** 1.4.0
**Licenza:** GPL v2 or later

---

## Cosa fa

### Gestione debug standard
- **Toggle delle costanti** (`WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, `SCRIPT_DEBUG`, `SAVEQUERIES`) con salvataggio diretto in `wp-config.php`.
- **Viewer del `debug.log`** in tempo reale, con filtro, auto-refresh ogni 5 secondi, download e svuotamento.
- **Query Monitor**: snapshot delle query SQL eseguite sull'ultima pagina frontend. Evidenzia le query lente (>50ms) e mostra il caller stack.
- **Backup automatico** di `wp-config.php` prima di ogni modifica (nella cartella privata randomizzata, deny-all): se il backup non riesce, `wp-config.php` non viene toccato.
- **Scrittura sicura** di `wp-config.php`: lettura con il tokenizer di PHP (commenti, `define` condizionali e valori da `getenv()` riconosciuti), scrittura atomica, controllo di sintassi prima di salvare.
- **Debug log privato**: attivando `WP_DEBUG_LOG` dal pannello il log va nella cartella privata, non in `/wp-content/debug.log` raggiungibile da chiunque.

### Emergency Access (v1.1.0)
- File **standalone** (`emergency.php`) che **non carica WordPress**: funziona anche quando il sito è crashato.
- Connessione PDO diretta al DB usando credenziali estratte da `wp-config.php`.
- Autenticazione con password (hash bcrypt), rate-limit, log accessi, CSRF.
- Azioni disponibili: vedere `debug.log`, vedere il PHP error log del server, disattivare tutti i plugin, disattivare un singolo plugin, cambiare al tema default, svuotare i transient, toggle delle costanti debug.

### Preflight Snapshots (novità v1.2.0)
- Cattura lo **stato del sito** (plugin attivi + tema + versioni di tutti i plugin/temi installati).
- **Tre trigger**: manuale dal pannello, automatico quando attivi l'Emergency Access, automatico dopo ogni aggiornamento completato da WordPress.
- Storico degli **ultimi 5 snapshot** (FIFO, deduplicazione se lo stato non è cambiato).
- **Diff visuale** rispetto allo stato attuale: plugin attivati/disattivati, plugin aggiornati con versioni from/to, tema cambiato.
- **Rollback selettivo**: scegli se ripristinare solo i plugin attivi, solo il tema o entrambi.
- **Disponibile anche dall'Emergency standalone** — puoi ripristinare uno snapshot anche quando WordPress è down.

## Installazione

1. Carica lo ZIP da *Plugin → Aggiungi nuovo → Carica*.
2. Attiva il plugin.
3. Vai su **Strumenti → Debug Manager**.

## Requisiti

- WordPress 6.0+
- PHP 7.4+
- `wp-config.php` scrivibile (permessi 0644 consigliati)
- Per l'emergency access: estensione `pdo_mysql` attiva

## Utilizzo

### Tab Costanti
Spunta/deseleziona le costanti e premi **Salva**. Le modifiche hanno effetto al caricamento successivo di qualsiasi pagina. Vengono scritte solo le costanti che cambiano; una `define` già presente viene modificata dov'è (commenti e condizioni restano com'erano). Se `wp-config.php` non è scrivibile, i toggle sono disabilitati e compare un alert con il percorso rilevato.

In multisite il pannello è nella **bacheca di rete** (Impostazioni → Debug Manager) ed è riservato ai super admin: `wp-config.php` e il log sono dell'intera rete.

### Tab Debug Log
La prima volta che viene generato un errore con `WP_DEBUG_LOG` attiva, WordPress crea il file di log. Attivato dal pannello, il log sta nella cartella privata (`wp-content/dbdm-private-{token}/debug.log`); un percorso personalizzato già impostato in `wp-config.php` viene rispettato.
- Seleziona numero di righe (100–5000).
- Usa il campo **Filtra** per trovare righe specifiche (`Fatal`, `Notice`, nome file).
- **Auto-refresh 5s** per monitoraggio live.
- **Scarica** / **Svuota** in un click.

### Tab Query SQL
Attiva `SAVEQUERIES` dalla tab Costanti, poi visita una pagina frontend. Torna in **Debug Manager → Query SQL** per vedere SQL eseguite, tempi e caller. Snapshot conservato 1 ora come transient.

### Tab Snapshots

**Flusso tipico di uso:**
1. Stai per aggiornare un plugin critico (es: WooCommerce) → vai in **Debug Manager → Snapshots** → premi "Crea snapshot adesso" con nota `"pre-update WooCommerce 9.3"`.
2. Fai l'aggiornamento.
3. **Se tutto OK**: niente da fare, lo snapshot rimane nello storico in caso di problemi scoperti in seguito.
4. **Se qualcosa si rompe**: torna in Snapshots → clicca "Ripristina" sullo snapshot di prima → il DB viene riportato allo stato precedente.

In aggiunta agli snapshot manuali, il plugin crea snapshot **automaticamente**:
- Quando attivi l'Emergency Access (utile perché tipicamente lo attivi proprio prima di un intervento rischioso).
- Quando WordPress completa un aggiornamento di plugin, tema o core.

**Cosa ripristina e cosa no:**
- ✅ Lista dei plugin attivi nel DB (`active_plugins`)
- ✅ Tema attivo (`stylesheet`, `template`)
- ❌ Non ripristina i **file** dei plugin/temi: se il bug è nella nuova versione del file, devi reinstallare la versione vecchia via FTP o via rollback manuale del plugin dalla sua interfaccia.
- ❌ Non ripristina le option custom di configurazione

Gli snapshot mostrano il **diff** rispetto allo stato attuale (quale plugin è stato aggiornato, da che versione a che versione), così capisci a colpo d'occhio cosa è cambiato.

**Uso dall'Emergency standalone:** se il sito è down e non puoi accedere al pannello WP, apri `emergency.php`, fai login, e in fondo alla dashboard trovi la sezione "Snapshot disponibili" con gli stessi controlli di ripristino.

### Tab Emergency
1. Vai in **Debug Manager → Emergency**.
2. Imposta una password forte (minimo 12 caratteri, con maiuscole/minuscole/numeri). Salvata come hash bcrypt, non recuperabile in chiaro.
3. Spunta "Attiva accesso emergency" e salva.
4. Tieni in un password manager l'URL:
   `https://tuosito.com/wp-content/plugins/db-debug-manager/emergency.php`

**Quando il sito è giù:**
1. Apri l'URL dell'emergency.
2. Inserisci la password.
3. Dalla dashboard:
   - Ultimi 64KB di `debug.log` e PHP error log del server
   - Disattivare tutti i plugin (per isolare un fatal)
   - Disattivare un singolo plugin dall'elenco
   - Cambiare al tema default (cerca `twentytwentyfive` → `twentytwenty`)
   - Svuotare tutti i transient
   - Toggle costanti debug (es: attivare `WP_DEBUG_LOG` per vedere l'errore)

**Sicurezza dell'emergency:**
- Default **disattivato**. Finché non lo attivi esplicitamente, `emergency.php` risponde con errore anche con password giusta.
- 5 tentativi per IP (per rete /64 in IPv6), poi blocco di 15 minuti dall'ultimo tentativo. Ogni tentativo è contato prima della verifica della password, anche con richieste in parallelo; se il limite non può essere garantito (cartella privata non scrivibile) l'accesso è negato. L'IP è `REMOTE_ADDR` (non falsificabile); se il sito è dietro proxy/CDN, attiva l'opzione dedicata per usare l'ultimo hop di `X-Forwarded-For`.
- Ogni tentativo (login, successo, blocco, azione) viene loggato con IP e User-Agent.
- Sessione 30 minuti, cookie HttpOnly + SameSite=Strict, nuovo ID di sessione al login. Cambiare password, disattivare l'emergency o **disattivare il plugin** chiude tutte le sessioni aperte; disattivando il plugin l'emergency si spegne.
- CSRF token su ogni azione distruttiva.
- Ripristino degli snapshot validato: solo plugin ancora installati, solo temi il cui tema padre è presente.
- File interni (log, rate-limit, snapshot, backup) in `wp-content/dbdm-private-{token}/`, fuori dalla cartella del plugin (sopravvive agli aggiornamenti), nome casuale e `.htaccess` deny-all: protetti anche su Nginx. Se la cartella manca, l'emergency nega l'accesso invece di ripiegare su un nome prevedibile.
- Funziona anche con le credenziali del database in variabili d'ambiente (`getenv()`, `getenv_docker()` dell'immagine Docker ufficiale).
- `<meta name="robots" content="noindex, nofollow">`.

**Quando il sito funziona bene, disattiva l'emergency.** È una feature da tenere spenta di default e accendere solo nei momenti di crisi.

## Note di sicurezza

- Tutte le azioni admin protette da nonce + `manage_options` (`manage_network_options` in multisite).
- `WP_DEBUG_DISPLAY` va tenuto **disattivato in produzione**.
- `SAVEQUERIES` impatta le performance: solo in debug attivo.
- Il backup di `wp-config.php` (`wp-content/dbdm-private-{token}/wp-config.dbdm-bak`) contiene lo stato precedente all'ultimo salvataggio; ne esiste sempre solo l'ultimo.
- L'emergency access è **ad alto rischio**: chiunque ottenga la password ha accesso a operazioni distruttive. Trattala come chiave master.

## Struttura file

```
db-debug-manager/
├── db-debug-manager.php         # Bootstrap singleton
├── emergency.php                # Accesso standalone (no WP)
├── .htaccess                    # Protezione file sensibili
├── README.md
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── db-admin-ui.css      # Design system condiviso
│   └── js/
│       └── admin.js
├── inc/
│   ├── class-admin.php
│   ├── class-config.php         # Parser wp-config (WP side)
│   ├── class-emergency.php      # Password/log emergency, cartella privata (WP side)
│   ├── class-emergency-guard.php    # Regole di sicurezza dell'emergency (no WP deps)
│   ├── class-log.php
│   ├── class-queries.php
│   ├── class-snapshots.php      # Preflight capture & rollback
│   ├── class-standalone-config.php  # Lettura/scrittura wp-config (no WP deps)
│   └── class-updater.php        # GitHub auto-updater
├── index.php                    # Anti directory-listing
└── templates/
    ├── page.php
    ├── tab-config.php
    ├── tab-log.php
    ├── tab-queries.php
    ├── tab-snapshots.php
    └── tab-emergency.php
```

## Changelog

### Non rilasciata

- **Fix (emergency): stato delle costanti di debug sbagliato** con `define( 'WP_DEBUG', 1 )`, valori da `getenv()`, `define` condizionali o copie commentate. Ora è letto come lo vede PHP; un valore non determinabile è indicato come "da verificare".
- **Fix (emergency): azioni riportate come riuscite anche senza effetto.** Un plugin già disattivato, nessun transient, un log già vuoto ora danno un avviso giallo; un elenco dei plugin illeggibile, un'azione sconosciuta o un modulo scaduto (prima ignorato in silenzio) danno un errore. "Disattiva" funziona anche con nomi di plugin non UTF-8.
- **Fix (emergency): "Cambia a tema default" poteva scegliere il tema rotto stesso o un child theme.** Ora esclude il tema attivo e il suo padre e sceglie solo temi completi.
- **Fix (emergency): con `WP_CONTENT_DIR` o la cartella dei plugin spostati**, l'emergency cercava temi, plugin e `debug.log` nelle posizioni standard (cambio di tema e ripristino degli snapshot fallivano). Ora usa i percorsi che WordPress salva insieme alla cartella privata.
- **Fix (emergency): spegnendo `WP_DEBUG_LOG` il percorso personalizzato del log andava perso.** Ora viene ricordato come fa il pannello, e alla riaccensione il log torna lì; un percorso ricordato che porterebbe al log pubblico è ignorato.
- **Fix (emergency): debug.log e error log di PHP apparivano vuoti** se contenevano un byte non UTF-8 (testo Latin-1, coda tagliata a metà carattere). Ora i caratteri non validi sono mostrati come �.
- **Fix (emergency): uno slug di plugin con un apostrofo rompeva la conferma di "Disattiva"** e poteva eseguire codice JavaScript nella dashboard. Il testo della conferma è ora codificato per JavaScript prima che per HTML.
- **Interno: `emergency.php` diviso in classi** (`inc/emergency/`: richiesta, sessione e CSRF, accesso al database, log degli accessi, azioni, stato del sito, pagine, flusso). L'URL e il comportamento non cambiano; le parti si possono ora provare con test automatici.

### 1.4.0 — Sicurezza dell'emergency e di wp-config.php — 2026-10-06

Prima release del piano di test (`TESTING-PLAN.md`): corregge tutti i difetti di priorità A trovati dall'audit, ciascuno con un test automatico.

- **Fix (importante): ogni aggiornamento del plugin cancellava snapshot, backup di `wp-config.php` e log dell'emergency.** La cartella privata stava dentro la cartella del plugin; ora è `wp-content/dbdm-private-{token}/`, con migrazione automatica.
- **Fix: emergency inutilizzabile con le credenziali in variabili d'ambiente** (Docker, molti hosting gestiti): `getenv()` e `getenv_docker()` vengono ora risolti. Supportati anche `DB_HOST` con socket o IPv6 e `wp-config.php` un livello sopra WordPress.
- **Fix: scrittura di `wp-config.php`.** Una `define` dopo un `/*` aperto veniva "decommentata" (parse error); una `define` con commento in coda, condizionale o su più righe veniva duplicata invece che modificata (il toggle non aveva effetto e PHP avvisava "already defined" a ogni richiesta). Ora la lettura usa il tokenizer di PHP, la scrittura è atomica, il backup contiene lo stato prima del salvataggio e se non riesce non si scrive nulla, il controllo di sintassi non dipende più da `exec`. Dopo la scrittura la cache di OPcache viene invalidata (prima le richieste dei secondi successivi potevano usare il file precedente).
- **Sicurezza emergency:**
  - limite dei tentativi aggirabile con richieste parallele, e disattivato in silenzio se la cartella non era scrivibile: ora ogni tentativo è prenotato sotto lock prima della verifica e, senza limite garantito, l'accesso è negato;
  - nessuna rigenerazione dell'ID di sessione al login (session fixation): ora nuovo ID e modalità stretta;
  - le sessioni sopravvivevano a cambio password, disattivazione dell'emergency e del plugin: ora vengono chiuse;
  - disattivare il plugin lasciava l'emergency attivo: ora lo spegne;
  - con "proxy fidato" `CF-Connecting-IP` era accettato anche senza Cloudflare (tentativi illimitati cambiando header): ora si usa solo l'ultimo hop di `X-Forwarded-For`; IPv6 limitato per rete /64;
  - senza cartella privata si ripiegava su `private/`, dal nome prevedibile e scaricabile su Nginx: ora l'accesso è negato;
  - ripristino degli snapshot senza validazione: percorsi non validi e temi figlio senza padre (sito bianco) vengono ora scartati; valori serializzati letti senza istanziare oggetti.
- **Fix: multisite.** Ogni amministratore di sito poteva riscrivere il `wp-config.php` della rete e leggere il log di tutti i siti: ora il pannello è nella bacheca di rete, solo per i super admin.
- **Fix: debug log pubblico.** Attivare `WP_DEBUG_LOG` dal pannello lo scriveva in `/wp-content/debug.log`, raggiungibile da chiunque: ora va nella cartella privata, e un log pubblico già attivo viene spostato al primo salvataggio. Anche l'emergency mostra e svuota il log nella posizione effettiva.
- **Fix: un nome di plugin con caratteri non UTF-8 cancellava tutti gli snapshot.** Ripristino del tema con `switch_theme()` (tema padre verificato, autoload delle opzioni invariato).
- Il pannello scrive solo le costanti che cambiano (prima riscriveva anche `WP_DEBUG_DISPLAY`, attiva di default).
- **Requisito minimo WordPress 6.0** (era 5.8), allineato agli altri plugin DB. WordPress stesso impedisce l'attivazione sulle versioni precedenti.
- **Suite di test:** unit (PHP 7.4–8.4), integration (WordPress 6.0 e ultima versione, anche multisite) ed E2E (wp-env + Playwright, compreso l'accesso a `emergency.php`), più una run notturna su WordPress in sviluppo e PHP 8.4. Vedi `TESTING.md`.
- **Aggiornamenti dal pannello:** `DB_GitHub_Updater` 1.1.0, lo stesso di DB Privacy Hub 1.8.0. Dopo un aggiornamento il plugin viene riattivato solo se era attivo (prima veniva attivato anche se l'admin l'aveva disattivato), anche per l'attivazione di rete; release senza ZIP ignorate invece di generare un errore; nessun errore se il filesystem di WordPress non è disponibile.

### 1.3.2 — 2026-07-17
- **CI/QA:** aggiunti GitHub Actions: lint PHP su 7.4 e 8.3, PHPCS con ruleset WPCS (`phpcs.xml.dist`: sicurezza ed escaping bloccanti, stile del progetto preservato) e workflow di release che builda lo ZIP con la cartella `db-debug-manager/` e lo allega alla Release (richiesto dall'auto-updater, che preferisce l'asset .zip allo zipball).
- **Conformità WPCS:** aggiunti `wp_unslash`/sanitizzazione sugli input, `esc_html__` nei `wp_die`, rinominate variabili che sovrascrivevano global WordPress (`$status`, `$s`, `$m`, `$descriptions`), eliminati short ternary. Nessun cambiamento funzionale (suite di regressione completa verde).
- Il workflow di release verifica che la versione del tag coincida con header e `DBDM_VERSION`.

### 1.3.1 — 2026-07-16
- **Fix:** falsi "Errore di sintassi rilevato" su hosting PHP-FPM: `PHP_BINARY` puntava a php-fpm, che non supporta il lint e falliva su qualsiasi contenuto, bloccando ogni salvataggio delle costanti. Il binario di lint viene ora calibrato (deve accettare codice valido e rifiutare codice rotto) con fallback al `php` CLI di sistema; se nessun binario è utilizzabile il lint viene saltato (best effort, come con `exec` disabilitata).

### 1.3.0 — 2026-07-16
- **Pulizia:** rimosso il codice morto della gestione sessioni su file (`session_path()`, `emergency-sessions.json`): l'emergency usa le sessioni PHP native da sempre.
- **Hardening:** token CSRF escapato con `htmlspecialchars` in tutti i campi hidden di emergency.php (coerenza con il resto dell'output).
- **Fix:** un `WP_DEBUG_LOG` impostato a un path custom (stringa) non viene più sovrascritto o azzerato dal salvataggio delle costanti: viene mostrato come attivo con il path visibile, il toggle off lo memorizza in wp_options e il toggle on lo ripristina. Anche la dashboard emergency ora riconosce i path stringa.
- **Sicurezza:** la validazione sintattica PHP pre-salvataggio di `wp-config.php` ora è attiva anche nel toggle costanti dall'Emergency standalone (prima solo dal pannello WP). Logica di sostituzione/inserimento e lint consolidate in `DBDM_Standalone_Config`: un'unica implementazione condivisa dai due contesti.
- **Sicurezza:** la cartella dei file interni (log accessi, snapshot, backup wp-config) ora ha un nome casuale (`private-{token}`) non indovinabile: i file restano protetti anche su Nginx, dove l'`.htaccess` viene ignorato. Migrazione automatica dalla vecchia `private/`; il token è in wp_options ed è letto anche dall'emergency standalone. Aggiunto `index.php` nella root del plugin contro il directory listing.
- **Sicurezza:** su server non-Apache il pannello mostra un avviso con lo snippet Nginx per il deny esplicito.
- **Sicurezza (importante):** il backup di `wp-config.php` viene ora salvato in `private/wp-config.dbdm-bak` (cartella con deny-all) invece che accanto a `wp-config.php`, dove poteva essere scaricato come testo semplice esponendo le credenziali del database.
- **Sicurezza (importante):** il rate-limit dell'emergency access ora usa `REMOTE_ADDR` invece degli header `X-Forwarded-For` / `CF-Connecting-IP`, che sono falsificabili dal client e permettevano di aggirare il blocco tentativi. Se il sito è dietro un proxy/CDN fidato, attiva la nuova opzione nella tab Emergency per usare gli header del proxy (viene letto l'ultimo hop di X-Forwarded-For, non il primo).
- **Migrazione:** il vecchio backup `wp-config.php.dbdm-bak` nella webroot viene eliminato automaticamente alla prima apertura del pannello o alla prima modifica delle costanti. Se hai usato versioni ≤ 1.2.0, verifica comunque che il file non sia più presente.

### 1.2.0 — 2026-04-16
- **Nuovo:** tab **Snapshots** — preflight capture dello stato del sito (plugin attivi + tema + versioni) con rollback selettivo.
- **Nuovo:** snapshot automatici su attivazione Emergency e dopo ogni aggiornamento completato da WordPress (`upgrader_process_complete`).
- **Nuovo:** diff visuale snapshot vs stato corrente (plugin attivati/disattivati, versioni from→to, tema cambiato).
- **Nuovo:** ripristino snapshot anche dall'Emergency standalone — il file JSON è condiviso tra i due contesti.
- **Nuovo:** deduplicazione snapshot identici entro 60 secondi.

### 1.1.0 — 2026-04-16
- **Nuovo:** tab **Emergency Access** con password protetta.
- **Nuovo:** file standalone `emergency.php` funzionante senza WordPress caricato.
- **Nuovo:** azioni di ripristino dalla dashboard emergency.
- **Nuovo:** sicurezza: rate-limit 5/15min, log tentativi, CSRF token, sessione 30min cookie HttpOnly+SameSite=Strict.
- **Nuovo:** cartella `private/` auto-creata con `.htaccess` deny-all.

### 1.0.0 — 2026-04-16
- Prima release.
- Toggle costanti `WP_DEBUG` et al. con salvataggio in `wp-config.php`.
- Viewer log con filtro, auto-refresh, download, svuotamento.
- Query monitor con cattura snapshot frontend.
- Backup automatico + lint PHP pre-salvataggio.
- Auto-updater da GitHub Releases.

## Licenza

GPL v2 or later. Niente registrazioni, niente nag, niente trucchi.
