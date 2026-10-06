# DB Debug Manager — Piano di test e correzioni

Documento di lavoro per portare il Debug Manager allo stesso livello di test
di DB Privacy Hub e DB Cookie Manager, con un obiettivo più alto sugli E2E:
il plugin scrive `wp-config.php`, espone un accesso d'emergenza che funziona
senza WordPress e ripristina lo stato dei plugin. Un difetto qui può fermare
un sito o aprire un accesso: ogni flusso va provato come lo vive un utente.

Si segue fase per fase; ogni fase è una PR in bozza. Le caselle si spuntano
man mano.

Base di partenza: v1.3.2 (audit del 2026-10-06, due analisi indipendenti:
lato WordPress e accesso d'emergenza; i punti principali sono stati
verificati sul codice).

---

## 1. Situazione attuale

- ~3.200 righe PHP: 7 classi in `inc/`, 6 template, `emergency.php` (app
  standalone di ~38 KB), `assets/js/admin.js`.
- **Nessun test nel repository.** CI: `php -l` su 7.4 e 8.3, PHPCS (WPCS);
  release da tag `v*`.
- Updater: 1.1.0 in arrivo con la PR #1 (stesso file di DB Privacy Hub 1.8.0).
- Requisito dichiarato: WordPress 5.8, PHP 7.4 (6.0 dalla Fase A, §7).

Aree e rischio:

| Area | Cosa fa | Perché è delicata |
|---|---|---|
| Costanti debug | scrive `define(...)` in `wp-config.php` con backup e lint | un errore rompe tutto il sito |
| Debug log | viewer, filtro, auto-refresh, download, svuota | contenuto arbitrario (XSS, UTF-8), file grandi, file pubblico |
| Query monitor | salva le query SQL dell'ultima pagina | dati personali dei visitatori nel database |
| Snapshots | stato di plugin e tema, diff, ripristino | scrive `active_plugins` e il tema direttamente |
| Emergency | `emergency.php` senza WordPress: login, azioni distruttive via PDO | superficie d'attacco, deve funzionare a sito rotto |
| Cartella privata | backup di `wp-config.php`, snapshot, log accessi | contiene le credenziali del database |

---

## 2. Bug noti (da correggere, ciascuno con il suo test)

Priorità: **A** = sicurezza, perdita di dati o sito fermo · **B** =
comportamento errato · **C** = robustezza e qualità.
Livello del test: **U** unit · **I** integration (WordPress + MySQL) · **E**
end-to-end (wp-env + Playwright, anche HTTP diretto su `emergency.php`).

### 2.1 Scrittura di `wp-config.php` (condivisa da pannello ed emergency)

| # | Pri. | Dove | Problema | Test |
|---|---|---|---|---|
| 1 ✅ | A | `class-standalone-config.php:101` | Un `define` dopo un `/*` aperto viene "decommentato": `/* define('WP_DEBUG', true);` + riga successiva `*/` → parse error. Unica difesa il lint, che salta in silenzio senza `exec`/CLI | U su un corpus di wp-config, `php -l` sull'output |
| 2 ✅ | A | `class-config.php:105`, `class-standalone-config.php:221` | Scrittura non atomica (niente file temporaneo + `rename`, niente lock, nessun controllo dei byte scritti): con disco pieno `wp-config.php` troncato e sito fermo | U con stream wrapper / quota |
| 3 ✅ | A | `class-config.php:87` | Il backup viene rifatto a ogni costante (5 per salvataggio): non contiene lo stato prima del salvataggio; se la cartella privata non è scrivibile la copia fallisce in silenzio e la scrittura procede senza backup; salvataggio non atomico tra costanti | U + I |
| 4 ✅ | A | `class-standalone-config.php:101` | Define con commento in coda, `defined() \|\| define()`, `if (!defined) define`, define su più righe, copia commentata prima di quella attiva, define in un file incluso: viene **inserito un duplicato**. Il toggle non ha effetto, l'admin vede "salvate", PHP 8 emette "already defined" a ogni richiesta | U (tabella di varianti, una sola define attiva col valore atteso) + I |
| 5 ✅ | B | `class-standalone-config.php:115` | Senza il marker "That's all" e con `require` (non `_once`) o indentato, la define finisce dopo `wp-settings.php`: nessun effetto | U |
| 6 ✅ | B | `tab-config.php:64` | `define('WP_DEBUG', 1)` mostrato come spento; salvando un'altra costante si scrive `false` | U/I |
| 7 ✅ | B | `class-log.php:16`, `tab-config.php:64` | `WP_DEBUG_LOG` `'true'`/`'1'` trattato come percorso di file (il core lo intende come `wp-content/debug.log`); `ini error_log` ignorato | U |
| 8 ✅ | B | `class-admin.php:98` | Ogni salvataggio scrive tutte e 5 le costanti; su un sito pulito `WP_DEBUG_DISPLAY` risulta già spuntato (default del core) e finisce scritto `true`: errori visibili ai visitatori | E |
| 9 ✅ | C | `class-standalone-config.php`, `class-config.php:142` | CRLF convertiti in LF sulla riga modificata; nome costante case-insensitive; `addslashes` in una stringa a virgolette singole | U |

### 2.2 Accesso d'emergenza (`emergency.php`)

| # | Pri. | Dove | Problema | Test |
|---|---|---|---|---|
| 10 ✅ | A | `class-standalone-config.php:62` | Le credenziali definite con `getenv()` / `getenv_docker()` (Docker, wp-env, molti hosting) non vengono lette: **l'emergency non funziona** ("Impossibile leggere le credenziali") | U + E su wp-env standard |
| 11 ✅ | A | `emergency.php:121-167` | Rate limit aggirabile: lettura senza lock, read-modify-write non atomico, `password_verify` tra controllo e registrazione → richieste parallele superano i 5 tentativi. Se la cartella non è scrivibile il rate limit e il log si disattivano in silenzio | E (20 login paralleli) + I |
| 12 ✅ | A | `emergency.php:270` | Nessun `session_regenerate_id()` al login né `use_strict_mode`: session fixation | E |
| 13 ✅ | A | `emergency.php:181` | La sessione sopravvive al cambio password, alla disattivazione dell'emergency e alla disattivazione del plugin | E |
| 14 ✅ | A | `db-debug-manager.php` | Nessun hook di disattivazione né disinstallazione: disattivato il plugin, `emergency.php` resta attivo con password e azioni distruttive | E |
| 15 ✅ | A | `emergency.php:51,239` | Senza token valido si ricade su `private/` prevedibile, dove finisce il backup di `wp-config.php` (credenziali DB): scaricabile su Nginx. Accade dopo un aggiornamento via FTP prima di aprire il pannello | E |
| 16 ✅ | A | `emergency.php:91` | Con "fidati del proxy" attivo `CF-Connecting-IP` è accettato anche senza Cloudflare: tentativi infiniti cambiando header; IPv6 per indirizzo esatto | U + E |
| 17 ✅ | A | `emergency.php:417-442` | Ripristino snapshot senza validazione: percorsi `../`, cartelle, non-stringhe; il tema padre (`template`) non viene verificato → sito bianco | I + E |
| 18 ✅ | B | `emergency.php:807` | Iniezione JS nel `confirm()` del pulsante "Disattiva" (slug con apostrofo) | E |
| 19 ✅ | B | `emergency.php:826,840` | Pannelli del log vuoti con UTF-8 non valido (taglio a metà carattere, Latin-1) | E |
| 20 ✅ | B | `emergency.php:363` | Il toggle `WP_DEBUG_LOG` dall'emergency perde il percorso personalizzato (il pannello lo conserva) | E |
| 21 ✅ | B | `emergency.php:379,475,327` | Percorsi fissi: `wp-content/debug.log`, cartella temi; ignorati `WP_DEBUG_LOG` stringa e `WP_CONTENT_DIR` | U/E |
| 22 ✅ | B | `class-standalone-config.php:62,82` | Define in commenti o condizionali possono sovrascrivere le credenziali (vince l'ultima, in PHP la prima); stesso per `$table_prefix` | U |
| 23 ✅ | B | `class-standalone-config.php:68` | Backslash nelle password alterati (`stripslashes`); valori con `);` troncati | U |
| 24 ✅ | B | `class-standalone-config.php:231` | `DB_HOST` con socket o IPv6 interpretato male | U |
| 25 ✅ | B | `class-standalone-config.php:19` | `wp-config.php` sopra la root di WordPress o plugin in symlink: non trovato | U |
| 26 ✅ | B | `emergency.php:301` | Multisite ignorato: plugin attivi in rete, temi e transient degli altri siti | I (multisite) + E |
| 27 ✅ | B | — | Con object cache persistente (Redis/Memcached) le azioni scrivono nel DB ma il sito continua a usare i valori in cache: nessun avviso | U (rilevamento del drop-in) |
| 28 ✅ | B | `emergency.php:330` | "Cambia a tema default" può scegliere il tema attivo (rotto) o un child theme | I + E |
| 29 ✅ | B | `emergency.php:138,155` | Il blocco conta dal primo errore, non dal quinto: finestra più corta del dichiarato | U (orologio iniettabile) |
| 30 ✅ | B | `emergency.php:297` e azioni | Successo riportato anche quando nulla cambia (plugin non attivo, opzione mancante); token CSRF scaduto ignorato senza avviso | E |
| 31 ✅ | B | `emergency.php:505` | Stato delle costanti letto male (`1`, `getenv`, condizionali) | U |
| 32 ✅ | C | `emergency.php:217,312,494` | `unserialize()` senza `allowed_classes => false` | U |
| 33 ✅ | C | `emergency.php:178` e altri | `csrf`/`password` inviati come array → TypeError, pagina 500 (anche senza login) | E |
| 34 ✅ | C | `emergency.php:210` | `PDOException` non gestita (prefisso tabelle sbagliato) → 500 vuoto | U/E |
| 35 ✅ | C | `emergency.php` | Mancano `X-Frame-Options`/`frame-ancestors`, `X-Robots-Tag`, `Cache-Control: no-store`; cookie `secure` non rilevato dietro proxy TLS; durata sessione PHP < 30 minuti | E |
| 36 ✅ | C | `emergency.php` | Log accessi e file del rate limit senza rotazione; righe di log falsificabili con a capo | U/E |
| 37 ✅ | C | `emergency.php:194` | Prima del login rivela se l'emergency è attivo, se c'è una password, se il DB risponde; mostra per intero l'error log del server | E |
| 38 ✅ | C | `emergency.php:250` | Logout via GET senza CSRF | E |

### 2.3 Lato WordPress

| # | Pri. | Dove | Problema | Test |
|---|---|---|---|---|
| 39 ✅ | A | `class-emergency.php:94` | **La cartella privata sta dentro la cartella del plugin: ogni aggiornamento (e la cancellazione) la elimina** — snapshot, backup di `wp-config.php`, log accessi | I (aggiornamento reale con `Plugin_Upgrader`) |
| 40 ✅ | A | `class-admin.php:39` | Multisite: ogni amministratore di sito ha `manage_options` e può riscrivere il `wp-config.php` della rete e leggere il log di tutti i siti | I (multisite) + E |
| 41 ✅ | A | `class-admin.php:113` | Attivando `WP_DEBUG_LOG` il log finisce in `/wp-content/debug.log`, **pubblico**, senza avviso | E (GET anonimo) |
| 42 ✅ | A | `class-snapshots.php:206` | Un nome di plugin con UTF-8 non valido fa fallire `json_encode` e la scrittura **cancella tutti gli snapshot** | U |
| 43 | B | `admin.js:20-42` | "Aggiorna" e auto-refresh del log mostrano sempre il contenuto di quando la pagina è stata caricata (verificato) | E |
| 44 | B | `tab-log.php:65`, `tab-queries.php:67` | Un byte UTF-8 non valido rende vuoto il viewer | E |
| 45 ✅ | B | `class-snapshots.php:48-64,154` | Gli snapshot automatici (uno per aggiornamento, dopo l'aggiornamento) espellono quelli manuali dai 5 posti; deduplica che ignora temi e core | I |
| 46 ✅ | B | `class-snapshots.php:313,328` | Ripristino con `update_option` invece di `switch_theme()`/attivazione: niente hook, tema padre non verificato, `autoload` portato a `false` su `active_plugins`, `stylesheet`, `template` | I |
| 47 ✅ | B | `class-snapshots.php:115` | Multisite: plugin attivi in rete ignorati in cattura, diff e ripristino | I (multisite) |
| 48 | B | `class-admin.php:303,278` | "Ripristino completato" ed "eliminato" mostrati anche in caso di errore | E |
| 49 ✅ | B | `class-queries.php:31-64` | Il monitor salva fino a 500 query complete a ogni richiesta pubblica (login, REST, checkout, WP-CLI): email, indirizzi, token di sessione nel database e nei backup; una scrittura pesante per richiesta | I |
| 50 ✅ | B | `db-debug-manager.php` | Manca `Update URI`: lo slug può ricevere "aggiornamenti" da un plugin omonimo su wordpress.org; `DISALLOW_FILE_MODS` non rispettato per `wp-config.php` | U |
| 51 ✅ | C | — | Nessuna disinstallazione: restano opzioni, transient, cartella privata e le costanti scritte in `wp-config.php` | I |
| 52 ✅ | C | `class-log.php:57` | Lettura della coda del log quadratica, memoria illimitata su righe lunghissime; `tail()` restituisce N+1 righe | U |
| 53 | C | `class-admin.php:165` | Download del log: buffer non svuotati, `Content-Length` che cambia, niente `nosniff` | E |
| 54 | C | `page.php:24-65` | Messaggi `err` dalla query string mostrati in admin (contenuto arbitrario via link) | E |
| 55 ✅ | C | `class-emergency.php:111` | Cartella privata `0755`, file `0644` (backup con credenziali leggibile da altri utenti su hosting condiviso); token creato due volte in caso di richieste concorrenti; cartella creata da root con WP-CLI | I |
| 56 ✅ | C | `class-snapshots.php:86` | Lettura e scrittura degli snapshot senza lock tra pannello ed emergency | I |
| 57 | C | `admin.js` | Senza `debug.log` al caricamento il viewer non esiste e l'auto-refresh non fa nulla; nessun `.fail()` (nonce scaduto) | E |
| 58 | C | template | i18n assente (`load_plugin_textdomain`, `Domain Path`); stringhe non traducibili; percorso del backup indicato in modo errato | — |
| 59 | C | template | Accessibilità: checkbox delle costanti senza etichetta, filtri con solo placeholder, pulsante 🗑 senza nome, `#999` su bianco (2,8:1) | E (axe) |
| 60 ✅ | B | `tab-emergency.php:23` | La regola Nginx suggerita protegge ancora `plugins/db-debug-manager/private`: dalla 1.4.0 la cartella privata è `wp-content/dbdm-private-*` (trovato nella Fase 1) | E |

Legenda (aggiornata alla Fase A, 2026-10-06): ✅ corretto con test · ½ in
parte (33: `csrf`/`password` come array gestiti, il resto in 2.0.0; 46:
ripristino del tema con `switch_theme()` e autoload invariato, plugin ancora
senza hook di attivazione). Emerso durante la Fase A e corretto: dopo la
scrittura di `wp-config.php` OPcache poteva servire la versione precedente
per alcuni secondi (`opcache_invalidate`).

Verificato pulito: escaping dell'output PHP e uso di `.text()` in JS
(nessuna XSS nel pannello), nonce e capability su tutti gli handler, nessun
redirect aperto, compatibilità PHP 7.4.

---

## 3. Fase 0 — Infrastruttura

Come DB Privacy Hub (`../db-privacy-hub`), con queste aggiunte:

- [x] `composer.json` (PHPUnit 9.6, polyfill, WPCS), `phpunit.xml.dist`,
      `phpunit-integration.xml.dist`, `tests/unit/bootstrap.php`,
      `bin/install-wp-tests.sh`, `tests/integration/bootstrap.php`.
- [x] `package.json` + lock, `playwright.config.js` con progetti `setup`,
      `admin`, `emergency` (contesti senza sessione WordPress).
- [x] `.wp-env.json`: plugin `.` + mu-plugin di fixture; `WP_DEBUG_DISPLAY`
      spento.
- [x] **Fixture** `tests/fixtures/dbdm-e2e-fixture.php` (REST `dbdm-e2e/v1`,
      solo in wp-env):
      - `reset`: ripristina `wp-config.php` da una copia "dorata" presa al
        primo avvio, cancella opzioni/transient `dbdm_*`, `debug.log`,
        cartella privata, rate limit, sessioni emergency; plugin e tema di
        base;
      - `state`: contenuto di `wp-config.php`, valori **effettivi** delle
        costanti (richiesta di loopback o processo `wp eval` separato),
        elenco e permessi della cartella privata, `snapshots.json`, opzioni;
      - `wp-config`: scrive una variante dal corpus (commento in coda,
        `defined() ||`, blocco commentato, `require` senza `_once`, senza
        marker, CRLF, `getenv_docker`, valori numerici, `WP_DEBUG_LOG '1'`);
      - `log`: accoda, svuota, UTF-8 non valido, riga enorme, N MB;
      - `upgrade`: simula `upgrader_process_complete` con versioni finte;
      - `emergency`: imposta password nota, abilita, proxy;
      - plugin e temi di prova installati dal setup: un plugin che va in
        fatal on demand, un child theme col padre rimovibile, un plugin con
        nome Latin-1, uno slug con apostrofo.
- [x] `bin/setup-e2e.sh`: permalink, plugin e temi di prova, controllo che
      `emergency.php` risponda e che la cartella del plugin sia scrivibile da
      `www-data`.
- [x] `.gitignore`: `private-*`, `tests/e2e/.auth/`, report.
- [x] CI: unit PHP 7.4–8.4, integration WP 6.0/latest + multisite, E2E
      riutilizzabile; nightly su trunk e PHP 8.4.
- [x] `release.yml`: escludere i file di sviluppo e verificarlo.
- [x] `TESTING.md`.

Nota: la fixture ha già le varianti `golden` e `literal` di `wp-config.php`;
le altre del corpus e i plugin/temi di prova (fatal on demand, child theme,
nome Latin-1, slug con apostrofo) si aggiungono con i bug che li usano.
L'integration gira su WordPress 6.0 e latest: la test suite di 5.8 (minimo
dichiarato oggi) non supporta PHPUnit 9, un motivo in più per il passaggio a
6.0 proposto in §7.

## 4. Fase 1 — Refactor per la testabilità + unit test

`emergency.php` esegue sessione, I/O ed `exit` al caricamento: le sue
funzioni non si possono testare. Prima dei test:

- [x] estrarre da `emergency.php` classi includibili senza effetti
      (`inc/emergency/`): richiesta/IP, rate limit con orologio e storage
      iniettabili e lock, sessione, CSRF, azioni sul DB, rendering; il file
      `emergency.php` resta un punto d'ingresso sottile;
- [x] in `DBDM_Standalone_Config` (fatto nella Fase A): parser di `wp-config.php` basato su
      `token_get_all` (commenti e condizionali esclusi, `getenv`/`getenv_docker`
      risolti), costruttore del DSN puro, writer atomico (temp + `rename`,
      lock, verifica dei byte, backup verificato prima di scrivere), lint
      con binario iniettabile e filtro per disattivarlo nei test.

Ordine dei commit nel branch `fase-1-refactor`:

1. [x] refactor senza cambi di comportamento: `Request` (input tipizzato,
       IP tramite `DBDM_Emergency_Guard`), `Session` (strict, epoca,
       logout), `Csrf`, `Repository` (opzioni e transient via PDO), `Actions`
       (una per azione, esito *cambiato / nessun effetto / errore*), `View`,
       `App` (router); i 23 E2E esistenti invariati e verdi;
2. [x] unit sulle nuove classi; `Repository` e `Actions` su PDO SQLite in
       memoria (disponibile in locale, MySQL no);
3. [x] bug dell'emergency, uno per commit con test rosso prima: 18–21, 27,
       28, 30, 31, 33 (resto), 34–38;
4. [x] bug unit di costanti e log: 6, 7 (in parte), 9, 50, 52.

Fatto (2026-10-06): 8 classi in `inc/emergency/` (`DBDM_Em_Request`,
`_Session`, `_Repository`, `_Logger`, `_Status`, `_Actions`, `_View`,
`_App`; CSRF dentro la sessione), unit da 68 a 180, bug 6, 9, 18–21, 27,
28, 30, 31, 33–38, 50, 52 e il nuovo 60 corretti; 7 in parte.

Restano alla Fase 2: 26 (multisite dell'emergency), 45–49, 51, 55, 56. Alla
Fase 3: 43, 44, 53, 54, 57–59.

Unit test (stima 150+): corpus di `wp-config.php` reali e patologici (bug
1, 4, 5, 9, 10, 22–25, 31), writer e backup (2, 3), rate limit e finestra
(11, 16, 29), IP e proxy, CSRF, sessione, `tail` e UTF-8 (52), JSON degli
snapshot (42), diff e uguaglianza degli stati, `unserialize` sicuro (32),
rilevamento object cache (27), `Update URI`/`DISALLOW_FILE_MODS` (50).

## 5. Fase 2 — Integration test (stima 50–70)

WordPress + MySQL reali, anche multisite:

- [x] salvataggio costanti end-to-end e valore effettivo in un processo
      separato; backup = stato precedente (coperto dagli E2E della Fase A);
- [x] aggiornamento reale del plugin con `Plugin_Upgrader` da ZIP locale:
      snapshot e backup sopravvivono (bug 39);
- [x] snapshot: limiti per tipo, cattura prima dell'aggiornamento, diff,
      ripristino via API del core, autoload, multisite (45–47, 17);
- [x] capability in multisite (40, Fase A);
- [x] monitor query: niente dati dei visitatori (49);
- [x] disattivazione e disinstallazione (14, 51);
- [x] permessi e concorrenza della cartella privata (55, 56).

Fatto (2026-10-06): bug 7 (resto), 26, 45, 46, 47, 49, 51, 55, 56; unit
199, integration in `Phase2IntegrationTest`. L'integration ha trovato che
`add_option()` non è atomico (sovrascrive con ON DUPLICATE KEY UPDATE): il
token usa INSERT IGNORE.

## 6. Fase 3 — E2E "al massimo" (stima 120+)

Ogni flusso utente, nel browser e via HTTP diretto, su una matrice ampia.

**Pannello admin** (`tools.php?page=db-debug-manager`):
- [ ] Costanti: ogni costante on/off, effetto reale alla richiesta
      successiva (notice visibile o no, `SAVEQUERIES` che cattura),
      `wp-config` non scrivibile, varianti del corpus, percorso personalizzato
      del log, su un sito pulito solo ciò che l'admin ha toccato (bug 8).
- [ ] Log: viewer, righe, filtro, **aggiorna e auto-refresh con righe nuove**
      (43), UTF-8 non valido (44), file assente poi creato (57), download
      (nome, contenuto, header), svuota con conferma, log non pubblico (41).
- [ ] Query: cattura dopo una visita, lente evidenziate, filtro.
- [ ] Snapshots: crea con nota, diff dopo attivazioni e aggiornamenti
      simulati, ripristino selettivo (plugin, tema, entrambi), plugin rimossi,
      tema padre mancante, elimina, elimina tutti, messaggi corretti (48).
- [ ] Emergency (tab): password (lunghezza, conferma), abilita, rimuovi,
      proxy, svuota log, avviso Nginx.

**`emergency.php` su HTTP, senza WordPress caricato**:
- [ ] funziona su wp-env standard (`getenv_docker`, bug 10);
- [ ] stati di errore: disattivato, senza password, DB irraggiungibile,
      `wp-config` illeggibile — senza rivelare dettagli (37);
- [ ] login: password errata, CSRF scaduto, input come array (33),
      **blocco dopo 5 tentativi anche con 20 richieste parallele** (11),
      proxy e header falsificati (16), sblocco allo scadere;
- [ ] sessione: rigenerata al login (12), invalidata da cambio password,
      disattivazione emergency, disattivazione plugin (13, 14), scadenza,
      logout via POST (38), cookie e header (35);
- [ ] **scenario "sito rotto"**: un plugin di prova manda in fatal tutto il
      sito; dall'emergency lo si individua nel log, lo si disattiva (singolo
      e tutti), il sito torna a rispondere; stesso con un tema rotto e
      "Cambia a tema default" (28); ripristino di uno snapshot (17);
- [ ] toggle costanti dall'emergency con percorso del log conservato (20),
      log con UTF-8 non valido (19), slug con apostrofo (18), azioni senza
      effetto riportate come tali (30);
- [ ] cartella privata mai raggiungibile via HTTP (15) e mai cancellata
      dagli aggiornamenti (39).

**Trasversali**:
- [ ] accessibilità axe-core WCAG 2.1 AA su tutte le tab e su
      `emergency.php` (59);
- [ ] multisite: amministratore di sito senza accesso (40), azioni
      dell'emergency sulla rete (26);
- [ ] matrice: PHP 8.1 / 8.3 / 8.4, WordPress 6.0 (o minimo deciso) /
      latest / trunk, single e multisite; nightly sulla matrice completa.

## 7. Decisioni (prese il 2026-10-06)

- [x] **Cartella privata** (bug 39): `wp-content/dbdm-private-{token}/`, fuori
      dal plugin (sopravvive agli aggiornamenti, fuori dai backup di
      `uploads/`), con migrazione automatica dalla posizione attuale e da
      `private/`.
- [x] **Disattivazione**: l'emergency si spegne. **Disinstallazione**: si
      rimuovono opzioni, transient e cartella privata; le costanti in
      `wp-config.php` restano, con un avviso nel pannello (e nella pagina dei
      plugin) che le elenca prima di disinstallare.
- [x] **Multisite**: solo super admin, dalla bacheca di rete; emergency e
      snapshot tengono conto dei plugin attivi in rete e degli altri siti.
- [x] **Release in due tempi**: **1.4.0** con i bug di priorità A (e i test
      che li provano), poi **2.0.0** a fine piano con refactor ed E2E
      completi. Due tag in tutto.

- [x] **Bug 37** (2026-10-06): prima del login un messaggio unico
      ("Accesso d'emergenza non disponibile"), il motivo nel log degli errori
      di PHP; dopo il login l'error log di PHP mostra solo le voci con i
      percorsi del sito.

Proposte adottate in assenza di indicazioni diverse (da confermare):

- [x] **Posizione del debug log** (bug 41): attivando `WP_DEBUG_LOG` dal
      pannello si scrive un percorso dentro la cartella privata; un percorso
      personalizzato già impostato viene rispettato.
- [x] **Monitor query** (bug 49): cattura solo le richieste
      dell'amministratore loggato che ha attivato il monitor, esclusi login,
      REST, AJAX e WP-CLI (confermato il 2026-10-06).
- [x] **Requisito minimo WordPress**: 6.0 come gli altri plugin DB
      (confermato il 2026-10-06; primo commit della Fase A).
- [x] **Refactor di `emergency.php`**: classi includibili in `inc/emergency/`,
      l'URL resta lo stesso. I bug B/C dell'emergency si correggono nella
      stessa Fase 1, dopo il refactor (confermato il 2026-10-06).

### Fase A — fatta (→ 1.4.0)

Tutti i bug di priorità A (1–4, 10–17, 39–42) più alcuni B/C collegati (5,
8, 22–25, 29, 32, 33 in parte, 46 in parte), con:

- unit (68): corpus di `wp-config.php` (`standard`, `docker`, `edge-cases`)
  su ogni costante e valore, scrittura e backup, `build_dsn`,
  `find_wp_config`; `DBDM_Emergency_Guard` (IP, /64, tentativi con
  orologio, impronta della sessione, unserialize, plugin e temi
  ripristinabili); cartella privata e migrazione, epoca, costanti da
  scrivere, log privato, snapshot con UTF-8 non valido;
- integration (10, anche multisite): capability e bacheca di rete,
  disattivazione, cartella privata, ripristino del tema senza padre,
  autoload;
- E2E (23): login su wp-env standard (`getenv_docker`), cartella privata,
  nessun ripiego su `private/`, session fixation, sessioni chiuse da cambio
  password e disattivazione, **20 login in parallelo** (al massimo 5
  verificati), `CF-Connecting-IP` falsificato, input array, snapshot
  manomesso, `WP_DEBUG_LOG` privato da emergency e pannello (log non
  raggiungibile via HTTP), `wp-config.php` "da hosting", backup.

Nuovo file: `inc/class-emergency-guard.php` (regole di sicurezza
dell'emergency, senza WordPress). Il refactor completo di `emergency.php`
resta nella 2.0.0.

### Ordine di lavoro

1. **Fase 0** — infrastruttura.
2. **Fase A → 1.4.0** — bug A (1–4, 10–17, 39–42) con i loro test: unit sul
   writer e sul parser, integration su aggiornamento, multisite, snapshot,
   E2E su `emergency.php` (sessione, rate limit, disattivazione, cartella).
   Il refactor minimo necessario per 11 e 12 si fa qui.
3. **Fasi 1–3 → 2.0.0** — refactor completo, unit, integration, E2E "al
   massimo", bug B e C.

## 8. Convenzioni

- Un branch e una PR in bozza per fase; merge e tag solo su conferma.
- Ogni bug corretto ha un test che fallisce prima della correzione
  (verificato contro i sorgenti di `main` dove possibile).
- Changelog nel README alla voce "Non rilasciata"; tag solo per release
  cumulative, dopo CI verde su `main`.
- Riferimento: `../db-privacy-hub/TESTING.md` e `../db-privacy-hub/tests/`.
