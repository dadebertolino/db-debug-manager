# DB Debug Manager

Plugin WordPress per gestire il debug direttamente dal pannello di amministrazione, senza più aprire l'FTP per modificare `wp-config.php` o scaricare `debug.log`. Include un **sistema di accesso emergency standalone** che funziona anche quando WordPress è crashato.

**Autore:** Davide Bertolino · [davidebertolino.it](https://www.davidebertolino.it)
**Versione:** 1.2.1
**Licenza:** GPL v2 or later

---

## Cosa fa

### Gestione debug standard
- **Toggle delle costanti** (`WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, `SCRIPT_DEBUG`, `SAVEQUERIES`) con salvataggio diretto in `wp-config.php`.
- **Viewer del `debug.log`** in tempo reale, con filtro, auto-refresh ogni 5 secondi, download e svuotamento.
- **Query Monitor**: snapshot delle query SQL eseguite sull'ultima pagina frontend. Evidenzia le query lente (>50ms) e mostra il caller stack.
- **Backup automatico** di `wp-config.php` prima di ogni modifica (in `private/`, cartella protetta deny-all).
- **Validazione sintattica PHP** pre-salvataggio (aborta se la modifica genererebbe parse error).

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

- WordPress 5.8+
- PHP 7.4+
- `wp-config.php` scrivibile (permessi 0644 consigliati)
- Per l'emergency access: estensione `pdo_mysql` attiva

## Utilizzo

### Tab Costanti
Spunta/deseleziona le costanti e premi **Salva**. Le modifiche hanno effetto al caricamento successivo di qualsiasi pagina. Se `wp-config.php` non è scrivibile, i toggle sono disabilitati e compare un alert con il percorso rilevato.

### Tab Debug Log
La prima volta che viene generato un errore con `WP_DEBUG_LOG` attiva, il file `/wp-content/debug.log` viene creato automaticamente da WordPress.
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
- Dopo 5 tentativi falliti, IP bloccato per 15 minuti.
- Ogni tentativo (login, successo, blocco, azione) viene loggato con IP e User-Agent.
- Sessione 30 minuti, cookie HttpOnly + SameSite=Strict.
- CSRF token su ogni azione distruttiva.
- File interni (log, rate-limit) in `private/` con `.htaccess` deny-all.
- `<meta name="robots" content="noindex, nofollow">`.

**Quando il sito funziona bene, disattiva l'emergency.** È una feature da tenere spenta di default e accendere solo nei momenti di crisi.

## Note di sicurezza

- Tutte le azioni admin protette da nonce + `manage_options`.
- `WP_DEBUG_DISPLAY` va tenuto **disattivato in produzione**.
- `SAVEQUERIES` impatta le performance: solo in debug attivo.
- Il backup di `wp-config.php` (`private/wp-config.dbdm-bak`) viene sovrascritto a ogni modifica; ne esiste sempre solo l'ultimo.
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
│   ├── class-emergency.php      # Password/log emergency (WP side)
│   ├── class-log.php
│   ├── class-queries.php
│   ├── class-snapshots.php      # Preflight capture & rollback
│   ├── class-standalone-config.php  # Parser wp-config (no WP deps)
│   └── class-updater.php        # GitHub auto-updater
├── private/                     # Auto-creata, log + snapshots
│   ├── .htaccess                # Deny all
│   └── index.php
└── templates/
    ├── page.php
    ├── tab-config.php
    ├── tab-log.php
    ├── tab-queries.php
    ├── tab-snapshots.php
    └── tab-emergency.php
```

## Changelog

### 1.2.1 — 2026-07-16
- **Sicurezza (importante):** il backup di `wp-config.php` viene ora salvato in `private/wp-config.dbdm-bak` (cartella con deny-all) invece che accanto a `wp-config.php`, dove poteva essere scaricato come testo semplice esponendo le credenziali del database.
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
