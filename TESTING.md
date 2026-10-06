# Test & CI — DB Debug Manager

Piramide dei test su GitHub Actions: `.github/workflows/ci.yml` a ogni push e
PR, `.github/workflows/nightly.yml` ogni notte. Gli E2E stanno in un workflow
riutilizzabile (`.github/workflows/e2e.yml`) chiamato da entrambi. La release
resta in `.github/workflows/release.yml`, sul tag `v*`.

Il piano di lavoro (bug noti, decisioni, fasi) è in `TESTING-PLAN.md`.

## I job della CI

| Job | Cosa verifica | Quando |
|-----|---------------|--------|
| **lint** | `php -l` su PHP 7.4 e 8.3 | ogni push/PR |
| **phpcs** | WPCS (sicurezza ed escaping bloccanti) + compatibilità PHP 7.4+ | ogni push/PR |
| **unit** | logica pura (PHPUnit), matrice PHP 7.4–8.4 | ogni push/PR |
| **integration** | WordPress + MySQL reali: 6.0, ultima versione, ultima versione multisite | dopo lint |
| **e2e** | wp-env + Playwright: pannello admin ed `emergency.php` via HTTP | dopo lint, phpcs e unit |

`composer.lock` non è versionato (solo dipendenze di sviluppo); le dipendenze
npm sono fissate da `package-lock.json`.

## Run notturna

Ogni notte alle 04:17 UTC (e a mano da *Actions → Nightly*): E2E su
WordPress trunk e su PHP 8.4, integration su WordPress trunk.

## Perché questa struttura

Il Debug Manager scrive `wp-config.php`, legge e svuota `debug.log`,
ripristina plugin e tema attivi e offre un accesso d'emergenza
(`emergency.php`) che funziona **senza WordPress**, collegandosi al database
con PDO. Un difetto può fermare un sito o aprire un accesso:

- **unit** — parser e writer di `wp-config.php` su un corpus di file reali e
  patologici (`tests/fixtures/wp-config/`), coda del log, snapshot;
- **integration** — scrittura reale, aggiornamenti dei plugin, ripristino
  degli snapshot, multisite;
- **e2e** — ogni flusso come lo vive un utente, compreso lo scenario "sito
  rotto" recuperato dall'emergency.

## Unit

`tests/unit/bootstrap.php` definisce gli stub WordPress e carica tutte le
classi di `inc/` e `inc/emergency/` (solo definizioni). `emergency.php` e
`db-debug-manager.php` non si caricano: eseguono codice al caricamento.

L'accesso d'emergenza è diviso in classi senza effetti al caricamento
(`inc/emergency/`): richiesta, sessione e CSRF su un array qualsiasi,
repository delle opzioni via PDO, log, stato, azioni, pagine, flusso.
`EmergencyActionsTest` prova repository e azioni su **PDO SQLite in
memoria** (estensione `pdo_sqlite`, dichiarata nel job unit), con plugin,
temi, `wp-config.php` e cartella privata in una cartella temporanea.
`DBDM_Em_App::dispatch()` accetta una connessione iniettata: i casi d'errore
del flusso (database, accesso non disponibile) si provano senza sessione PHP.

| Helper | A cosa serve |
|--------|--------------|
| `dbdm_test_reset()` | azzera option, transient, filtri, `_doing_it_wrong`; da chiamare in `set_up()` |
| `dbdm_test_call_private( $class, $method, $args )` | invoca un metodo statico privato |
| `dbdm_test_set_static( $class, $property, $value )` | imposta una proprietà statica privata |

Il corpus `tests/fixtures/wp-config/` raccoglie i `wp-config.php` su cui
parser e writer devono funzionare: `standard.php` (installazione guidata),
`docker.php` (immagine ufficiale e wp-env, `getenv_docker`). Si allarga con
le varianti dei bug del piano. Un test verifica che ogni file del corpus sia
PHP valido.

## Integration

`tests/integration/bootstrap.php` carica il plugin su `muplugins_loaded`.
I test estendono `WP_UnitTestCase`; i file finiscono in `IntegrationTest.php`.
I test `@group ms-required` girano solo con `WP_MULTISITE=1`.

## E2E: ambiente e fixture

`.wp-env.json` monta il plugin e il mu-plugin
`tests/fixtures/dbdm-e2e-fixture.php` (solo in wp-env, non nel pacchetto) e
parte con le costanti di debug **spente**, come un sito in produzione.

`bin/setup-e2e.sh`, oltre a permalink e attivazione:

- installa **pdo_mysql** nel container `wordpress` se manca (l'immagine
  ufficiale ha solo mysqli; `emergency.php` usa PDO);
- rende `wp-config.php` e la cartella del plugin **scrivibili dal server
  web** (in CI i file appartengono all'utente del runner);
- prende la **copia dorata** di `wp-config.php` con il primo reset.

| Risorsa | A cosa serve |
|---------|--------------|
| `POST /?rest_route=/dbdm-e2e/v1/reset` | Ripristina `wp-config.php` dalla copia dorata (o ne scrive una variante: `wp_config`), cancella opzioni e transient `dbdm_*`, `debug.log` (o lo crea con `log`), le cartelle private; con `emergency` imposta password nota, abilitazione, token fisso della cartella privata, proxy. |
| `GET /?rest_route=/dbdm-e2e/v1/state` | Contenuto di `wp-config.php`, **valori effettivi delle costanti** (letti da una richiesta interna separata), permessi, `pdo_mysql`, versione PHP, `debug.log`, contenuto e permessi delle cartelle private, opzioni `dbdm_*`. |

Varianti di `wp-config.php`: `golden` (come lo scrive wp-env, credenziali con
`getenv_docker`) e `literal` (credenziali come stringhe). Le altre varianti
del corpus si aggiungono con i bug che le riguardano.

`emergency.php` si raggiunge su `/wp-content/plugins/db-debug-manager/emergency.php`
(helper `EMERGENCY_URL`, `emergencyLogin()`), in contesti del browser senza
sessione WordPress.

## Eseguire in locale

```bash
# Prerequisiti: Docker attivo, Node 22, PHP 8.x, Composer.
composer install
composer run lint
composer run phpcs
composer run test:unit

bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer run test:integration

npm ci
npx playwright install --with-deps chromium
npm run env:start
npm run env:setup
npm run test:e2e
npm run env:stop
```

## Release

Il tag annotato `vX.Y.Z` si crea solo dopo CI verde su `main`. `release.yml`
verifica che tag, header e `DBDM_VERSION` coincidano, costruisce lo ZIP con
`git archive` (i file di sviluppo sono `export-ignore` in `.gitattributes`)
e controlla che non contenga file di sviluppo.
