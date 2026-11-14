<?php

/**
 * Italian translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'file SQLite non trovato: {path}',
    'complete.in_literal' => 'niente da completare dentro una stringa o un commento',
    'complete.nothing' => 'niente da completare — digita l\'inizio di una parola chiave',
    'complete.none_keyword' => 'nessuna parola chiave completa "{word}"',
    'complete.none_table' => 'nessuna tabella completa "{word}"',
    'complete.none_column' => 'nessuna colonna completa "{word}"',
    'complete.none_qualified' => 'nessuna colonna di {qualifier} completa "{word}"',
    'complete.list' => '{count} completano "{word}": {list}{more}',
    'complete.done' => 'completato {word} → {insert}',
    'complete.inserted' => 'inserito {insert}',
    'edit.refuse_readonly' => 'sessione di sola lettura — la modifica è disattivata',
    'edit.refuse_no_table' => 'apri prima l\'anteprima di una tabella — i risultati delle query non sono modificabili sul posto',
    'edit.refuse_no_rows' => 'nessuna riga sotto il cursore',
    'edit.refuse_no_pk' => 'questa tabella non ha una chiave primaria — le righe non si possono indirizzare in sicurezza; scrivi l\'istruzione nel pannello query',
    'edit.refuse_pk_hidden' => 'la chiave primaria di questa riga è NULL o un segnaposto binario — non può essere indirizzata',
    'edit.refuse_binary' => 'le celle binarie o grandi non sono modificabili qui — usa un\'istruzione nel pannello query',
    'edit.refuse_schema' => 'impossibile leggere lo schema di {table} — modifica rifiutata',
    'edit.prompt_cell' => 'modifica {table}.{column} = {value}  (invio anteprima · ctrl+n NULL · esc annulla)',
    'edit.prompt_insert' => 'nuova riga in {table} — {column} ({type}) [{n}/{total}] = {value}  (invio avanti · vuoto = DEFAULT · ctrl+n NULL · esc annulla)',
    'edit.prompt_confirm' => '{sql}  (invio conferma · esc annulla)',
    'edit.cancelled' => 'modifica annullata',
    'edit.unchanged' => 'valore invariato — nulla scritto',
    'edit.done_update' => 'aggiornata 1 riga in {table}',
    'edit.done_insert' => 'inserita 1 riga in {table}',
    'edit.done_delete' => 'eliminata 1 riga da {table}',
    'edit.none_changed' => 'nessuna riga modificata — il valore c\'era già o la riga non esiste più; anteprima aggiornata',
    'edit.failed' => 'scrittura non riuscita — annullata, nulla è cambiato: {reason}',
    'edit.too_many' => 'rifiutato: l\'istruzione toccherebbe {count} righe — annullata, nulla è cambiato',
    'ui.title.tables' => ' tabelle ',
    'ui.title.rows' => ' righe ',
    'ui.title.query' => ' query ',
    'ui.title.admin' => ' admin ',
    'ui.title.explain' => ' piano ',
    'ui.help.query' => 'tab  cambia pannello  ·  ctrl+r / ctrl+e / ctrl+invio  esegui  ·  invio  a capo  ·  ↑/↓  cronologia  ·  esc / ctrl+c  annulla query  ·  ctrl+c  esci da inattivo',
    'ui.help.rows' => 'tab  cambia pannello  ·  j/k  riga  ·  h/l  cella  ·  e  modifica  ·  o  nuova riga  ·  D  elimina  ·  x  esporta  ·  q / esc  esci',
    'ui.help.browse' => 'tab  cambia pannello  ·  invio  carica tabella  ·  x  esporta  ·  i  importa  ·  q / esc  esci',
    'ui.help.empty' => 'tab  cambia pannello  ·  q / esc  esci',
    'ui.help.result' => 'tab  cambia pannello  ·  j/k  riga  ·  h/l  cella  ·  x  esporta  ·  q / esc  esci',
    'ui.help.admin' => '1-{count}  scegli pagina  ·  j/k  naviga  ·  p  pausa  ·  r  reimposta  ·  tab  cambia pannello  ·  q  indietro  ·  esc  annulla finestra o query',
    'ui.no_tables' => '(nessuna tabella)',
    'ui.no_match' => '(nessuna corrispondenza)',
    'ui.loading' => 'caricamento…',
    'ui.empty' => '(vuoto)',
    'ui.admin_unavailable' => 'pagina di amministrazione non disponibile: {reason}',
    'ui.error' => 'errore: {message}',
    'io.prompt_export' => 'in CSV → {path}  (invio scrive · esc annulla)',
    'io.prompt_replace' => 'SOSTITUIRE {path}?  (invio conferma · esc annulla)',
    'io.prompt_import_path' => 'importa CSV ← {path}  (invio · esc annulla)',
    'io.prompt_import_table' => 'importazione di {source} → tabella {table}  (invio · esc annulla)',
    'bar.tables' => 'Tabelle: {count}',
    'bar.cycle' => 'Tab:cambia',
    'bar.navigate' => '↑↓:naviga',
    'bar.load' => 'Invio:carica',
    'bar.run' => 'Ctrl+R:esegui',
    'bar.select' => '1-{count}:scegli',
    'bar.nav' => 'j/k:nav',
    'bar.quit' => 'q:esci',
    'bar.paused' => '[IN PAUSA]',
    'bar.tx' => '[TX]',
    'picker.title' => 'connessioni salvate',
    'picker.help' => '[↑↓/j/k] scegli   [Invio] connetti   [q] annulla',
    'result.empty' => '(set di risultati vuoto)',
    'explain.no_plan' => '(nessun piano di query — la query non ha restituito righe)',
    'explain.title' => ' PIANO DELLA QUERY ',
    'status.live_refresh_off' => 'aggiornamento live dell\'admin disattivato — ssl-mode verificato TLS (solo trasporto sincrono)',
    'status.async_failed' => 'Comando asincrono non riuscito: {message}',
    'status.file_cancelled' => 'riga file annullata',
    'status.path_required' => 'serve un percorso — esc annulla',
    'status.no_such_file' => 'file non trovato — continua a digitare o esc annulla',
    'status.table_required' => 'indica la tabella di destinazione — esc annulla',
    'status.export_failed' => 'esportazione non riuscita: {message}',
    'status.exported' => '{count} righe esportate in {path}',
    'status.import_failed' => 'importazione non riuscita: {message}',
    'status.imported' => '{path} importato in {table}',
    'status.history' => 'cronologia {pos}/{count}',
    'status.conn_lost' => 'connessione persa; riprova',
    'status.destructive' => '⚠ istruzione distruttiva — ctrl+r di nuovo (senza modifiche) per confermare',
    'status.running' => 'esecuzione della query…',
    'status.explain_failed' => 'EXPLAIN non riuscito: {message}',
    'status.explain_plan' => 'piano di esecuzione — qualsiasi query o anteprima di tabella lo sostituisce',
    'status.end_of_table' => 'fine della tabella — nessun\'altra pagina',
    'status.first_page' => 'già alla prima pagina',
    'status.nothing_to_save' => 'niente da salvare',
    'status.saved' => 'salvato ({count})',
    'status.removed' => 'rimosso ({count})',
    'status.row_one' => '1 riga',
    'status.rows' => '{count} righe',
    'status.page' => 'pagina {page} (righe {first}–{last})',
    'editor.placeholder' => '-- scrivi SQL, ctrl+r per eseguire --',
    'err.async_query_unsupported' => 'Questo driver non supporta le query asincrone',
    'err.async_browse_unsupported' => 'Navigazione asincrona non supportata da questo driver',
    'dash.title' => 'Dashboard prestazioni',
    'dash.fetching' => 'Recupero delle metriche del server...',
    'dash.back_hint' => 'Premi [q] per tornare alla vista delle tabelle',
    'dash.recent_queries' => 'Query recenti',
    'dash.no_queries' => '(ancora nessuna query)',
    'dash.header_pg' => 'Dashboard prestazioni{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Dashboard prestazioni{status} | MySQL {version} | Uptime: {uptime}',
    'dash.uptime_segment' => ' | Uptime: {uptime}',
    'pane.process_list' => 'Elenco processi',
    'pane.variables' => 'Variabili',
    'pane.status' => 'Stato',
    'pane.query_stats' => 'Statistiche query',
    'pane.dashboard' => 'Dashboard',
    'pane.table_stats' => 'Statistiche tabelle',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Debug',
    'page.no_data' => 'Nessun dato disponibile al momento.',
    'page.error' => 'Errore: {message}',
    'page.loading' => 'Caricamento…',
    'page.fetching' => 'Recupero dei dati dal server.',
    'debug.title' => 'Log di debug',
    'debug.entries' => ' ({count} voci)',
    'debug.errors_only' => '[solo errori]',
    'debug.empty' => '(nessuna voce di log)',
    'debug.more' => '↑ altre {count} voci',
    'debug.help' => '[j/k] nav  [f] solo errori  [c] svuota  [q] indietro',
    'reports.cannot_access' => 'Impossibile accedere ai report sulle prestazioni: {message}',
    'reports.title' => 'Report sulle prestazioni',
    'reports.categories' => 'Categorie',
    'reports.no_data' => '(nessun dato)',
    'reports.loading' => 'Caricamento dei report…',
    'reports.loading_one' => 'Caricamento di {caption}…',
    'reports.no_data_for' => 'Nessun dato disponibile per {caption}',
    'reports.help' => '[j/k] righe  [h/l] categoria  [[]/[]] report  [,/.] colonna  [c] unità  [r] aggiorna  [x] esporta  [q] indietro',
    'reports.unavailable' => 'Report sulle prestazioni non disponibili',
    'reports.ensure' => 'Assicurati di usare MySQL 5.6.6+ con performance_schema=enabled e lo schema sys installato.',
    'conn.kill_cancelled' => 'Kill annullato',
    'conn.none_selected' => 'Nessuna connessione selezionata',
    'conn.refuse_background' => 'Rifiuto di terminare un thread in background',
    'conn.verb_kill_query' => 'Kill della query',
    'conn.verb_kill' => 'Kill',
    'conn.sent' => '{verb} inviato alla connessione {id}',
    'conn.failed' => '{verb} non riuscito per la connessione {id}',
    'conn.confirm_verb_query' => 'Annulla la query in esecuzione su',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} connessione {id} ({user}@{host})?  [y] conferma   [altro tasto] annulla',
    'conn.help_empty' => '[j/k] nav  [f] filtro  [r] aggiorna',
    'conn.help_full' => '[j/k] nav  [K] kill  [X] kill query  [f] filtro  [r] aggiorna',
    'conn.vars_fail' => 'Impossibile recuperare le variabili del server',
    'vars.load_fail' => 'Impossibile caricare le variabili: {message}',
    'vars.unknown_error' => 'Errore sconosciuto',
    'vars.not_dynamic' => 'Errore 1238: \'{name}\' non è dinamica — premi [p] per usare PERSIST_ONLY',
    'vars.new_value' => 'nuovo valore > ',
    'vars.edit_head' => 'Modifica ({mode}) {name} (attuale: {current})',
    'vars.scope_explain' => 'GLOBAL = solo runtime (perso al riavvio) · PERSIST = runtime + file di configurazione · PERSIST_ONLY = solo file di configurazione (si applica al prossimo riavvio)',
    'vars.edit_help' => 'Inserisci il nuovo valore, [p] cambia modalità, [Invio] conferma, [Esc] annulla',
    'reports.back' => '[q] indietro',
    'err.dsn_empty' => 'Il DSN non può essere vuoto',
    'err.dsn_no_separator' => 'DSN non valido: manca il separatore "://"',
    'err.driver_unsupported' => 'Driver non supportato: {driver}',
    'err.dsn_sqlite_invalid' => 'DSN SQLite non valido',
    'err.dsn_parse_failed' => 'DSN non valido: parse_url() non riuscito',
    'err.dsn_no_host' => 'DSN non valido: host mancante',
    'err.driver_choose' => 'Driver non supportato: {driver} — scegline uno tra {list}',
    'err.args_missing' => 'Argomenti obbligatori mancanti: passa sia --driver sia --db (oppure usa --dsn)',
    'err.opt_needs_value' => 'L\'opzione --{name} richiede un valore',
    'err.opt_unknown' => 'Opzione sconosciuta: --{name}',
    'err.csv_not_found' => 'File CSV non trovato: {path}',
    'err.csv_unreadable' => 'Impossibile leggere il file CSV \'{path}\': verifica che esista e sia leggibile',
    'err.csv_no_headers' => 'Impossibile leggere le intestazioni CSV da: {path}',
    'err.csv_no_columns' => 'Il file CSV non ha colonne: {path}',
    'err.dest_unwritable' => 'Impossibile aprire la destinazione \'{path}\' in scrittura: verifica che la directory esista e sia scrivibile',
    'err.export_buffer' => 'Impossibile creare il buffer di esportazione',
    'err.csv_partial' => 'L\'esportazione CSV di {table} ha scritto {written} righe su {expected} — la tabella è cambiata durante l\'esportazione o il driver ha ignorato la finestra di paginazione; rifiuto di presentare un file parziale come completo',
    'err.sql_partial' => 'Il dump SQL di {table} ha scritto {written} righe su {expected} — la tabella è cambiata durante il dump o il driver ha ignorato la finestra di paginazione; rifiuto di presentare un file parziale come completo',
    'err.table_unreadable' => 'Tabella non trovata o illeggibile: {table} ({message})',
    'err.dml_no_prepare' => 'questa connessione non può eseguire istruzioni preparate',
    'err.dml_refused' => 'il server ha rifiutato l\'istruzione',
    'alert.connection_critical' => 'Utilizzo delle connessioni criticamente alto',
    'alert.connection_elevated' => 'Utilizzo delle connessioni elevato',
    'alert.aborted_elevated' => 'Tasso di connessioni interrotte elevato',
    'alert.thread_elevated' => 'Numero di thread in esecuzione elevato',
    'alert.slow_query_time' => 'Tempo query lenta impostato a {seconds}s',
    'alert.connections_at' => 'Connessioni al {pct}% del massimo ({connected}/{max})',
    'alert.connection_error_one' => '{count} errore di connessione rilevato',
    'alert.connection_error_other' => '{count} errori di connessione rilevati',
    'alert.threshold_ratio_connection_warning' => 'La soglia di avviso connessioni deve essere compresa tra 0.0 e 1.0',
    'alert.threshold_ratio_connection_critical' => 'La soglia critica connessioni deve essere compresa tra 0.0 e 1.0',
    'alert.threshold_ratio_thread_running' => 'La soglia dei thread in esecuzione deve essere compresa tra 0.0 e 1.0',
    'alert.threshold_aborted_rate' => 'La soglia del tasso di interruzioni deve essere >= 0.0',
    'alert.threshold_slow_query' => 'La soglia delle query lente deve essere >= 0.0',
    'alert.threshold_connection_errors' => 'La soglia degli errori di connessione deve essere >= 0',
    'history.err_mkdir' => 'Impossibile creare la directory della cronologia: {dir}',
    'history.err_chmod' => 'Impossibile proteggere il file della cronologia: {path} (modalità {mode})',
    'history.err_unusable' => 'L’archivio della cronologia non è utilizzabile: {error} — elimina il file o correggine i permessi per recuperarlo.',
    'query.cancelled' => 'Query asincrona annullata',
    'tx.unknown' => 'Comando sconosciuto — :tx avvia, conferma o annulla una transazione',
    'tx.async_refusal' => ':tx copre la sessione sincrona; il trasporto asincrono non ha ancora una diramazione transazione coordinata — esegui BEGIN/COMMIT espliciti o usa il percorso sincrono',
    'tx.already_open' => 'Una transazione è già aperta — :tx commit o :tx rollback',
    'tx.no_tx' => 'Nessuna transazione aperta — :tx ne avvia una',
    'tx.opened' => 'Transazione avviata — le istruzioni vi si accumulano fino a :tx commit o :tx rollback',
    'tx.committed' => 'Transazione confermata',
    'tx.rolled_back' => 'Transazione annullata',
    'tx.arm_rollback' => 'ROLLBACK elimina tutte le modifiche da BEGIN — premi di nuovo il tasto di esecuzione per confermare',
    'tx.failed' => 'Comando di transazione fallito: {message}',
    'history.none' => 'Niente da leggere — la cronologia di stato registra solo sessioni MySQL (a richiesta con --history)',
    'history.empty' => 'Nessun campione negli ultimi {minutes} minuti — il trend ne richiede almeno due',
    'history.unreadable' => 'Archivio cronologia illeggibile: {message}',
    'history.status' => 'Trend cronologia — {samples} righe negli ultimi {minutes} minuti',
    'query.timed_out' => 'Query asincrona scaduta dopo {seconds}s',
    'reports.err_meta_missing' => 'File dei metadati dei report non trovato: {path}',
    'reports.err_meta_read' => 'Lettura del file dei metadati dei report non riuscita: {path}',
    'reports.err_not_in_catalog' => 'Report non trovato nel catalogo: {name}',
    'reports.err_view_unavailable' => 'Vista non disponibile su questo server: {name}',
    'reports.err_loading' => 'Errore nel caricamento del report: {error}',
    'variables.err_meta_missing' => 'File dei metadati delle variabili non trovato: {path}',
    'variables.err_meta_read' => 'Lettura del file dei metadati delle variabili non riuscita: {path}',
    'resilience.statement_timeout_default' => 'Timeout nell’esecuzione dell’istruzione',
    'resilience.statement_timeout_after' => 'Timeout nell’esecuzione dell’istruzione dopo {seconds} s',
    'variables.err_prepare' => 'Impossibile preparare l’istruzione su questa connessione',
    'variables.err_not_editable' => 'Variabile non modificabile',
    'variables.err_persist_mariadb' => 'La persistenza richiede MariaDB 11.1+ — questo server non implementa SET PERSIST.',
    'variables.err_persist_mysql' => 'La persistenza richiede MySQL 8.0.16+ — questo server non implementa SET PERSIST.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: nome utente vuoto — rifiuto di connettersi a MySQL con l’identità presunta "root"; specifica un utente esplicito',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: nome utente vuoto — rifiuto di connettersi a PostgreSQL con l’identità presunta "postgres"; specifica un utente esplicito',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: sslmode "{mode}" non riconosciuto; previsto uno tra {allowed} — scartare in silenzio un errore di battitura degraderebbe a "prefer", che ammette il testo in chiaro',
    'store.err_read' => 'Impossibile leggere il file delle connessioni: {path} (eliminalo o correggine i permessi)',
    'store.err_json' => 'Il file delle connessioni non è JSON valido ({error}): {path}',
    'store.err_shape' => 'Il file delle connessioni deve essere un oggetto JSON con una mappa "connections": {path}',
    'store.err_connections_type' => 'Il valore "connections" deve essere un oggetto JSON nome -> impostazioni: {path}',
    'store.err_unknown' => 'Connessione sconosciuta \'{name}\' — connessioni salvate: {saved}',
    'store.none' => '(nessuna)',
    'store.err_pass_env_unset' => 'La connessione \'{name}\' legge la password da ${var} — esportala prima di connetterti',
    'store.err_mkdir' => 'Impossibile creare la directory delle connessioni: {dir}',
    'store.err_write' => 'Impossibile scrivere il file delle connessioni: {path}',
    'store.err_replace' => 'Impossibile sostituire il file delle connessioni: {path}',
    'store.err_entry_type' => 'La connessione \'{name}\' deve corrispondere a un oggetto JSON di impostazioni',
    'store.err_plaintext_pass' => 'La connessione \'{name}\' memorizza un \'pass\' in chiaro — connections.json non contiene mai segreti: sostituiscilo con "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'La connessione \'{name}\' ha un’impostazione sconosciuta \'{field}\' — consentite: {allowed}',
    'store.err_setting_type' => 'L’impostazione \'{field}\' della connessione \'{name}\' deve essere una stringa o un numero',
    'store.err_setting_empty' => 'L’impostazione \'{field}\' della connessione \'{name}\' non deve essere vuota',
    'store.err_needs_driver' => 'La connessione \'{name}\' richiede un \'driver\'',
    'store.err_driver' => 'La connessione \'{name}\' usa un driver non supportato \'{driver}\' — scegli tra: {allowed}',
    'store.err_port' => 'L’impostazione \'port\' della connessione \'{name}\' deve essere un numero',
    'store.err_pass_env' => 'L’impostazione \'pass-env\' della connessione \'{name}\' deve essere il nome di una variabile d’ambiente',
    'store.err_name' => 'Nome connessione non valido \'{name}\' — usa lettere, cifre, punto, trattino o underscore (1-64 caratteri)',
    'store.err_secure' => 'Impossibile proteggere {path} (modalità {mode})',
    'db.err_dsn_semicolon' => 'Host e nome del database non devono contenere \';\' — è il separatore di opzioni del DSN PDO',
    'db.err_unknown_pg_ssl' => 'ssl-mode PostgreSQL sconosciuto: {mode}',
    'db.err_unknown_mysql_ssl' => 'ssl-mode MySQL sconosciuto: {mode}',
    'db.err_pg_driver' => 'Impossibile connettersi a un driver non PostgreSQL con il connettore PostgreSQL',
    'db.err_mysql_driver' => 'Il connettore MySQL accetta solo il driver mysql (ricevuto \'{driver}\')',
    'db.err_reconnect' => 'Riconnessione non riuscita dopo un errore di connessione: {error}',
    'db.err_quote_no_connection' => 'Impossibile applicare il quoting senza connessione',
    'dml.err_key_required' => 'è richiesta una chiave di riga: rifiutata un’istruzione che potrebbe toccare più di una riga',
    'dml.err_key_null' => 'la colonna chiave {column} è NULL: la riga non può essere indirizzata',
    'dml.err_empty_table' => 'il nome della tabella non deve essere vuoto',
    'dml.err_empty_column' => 'il nome della colonna non deve essere vuoto',
    'dml.err_empty_key_column' => 'il nome della colonna chiave non deve essere vuoto',
    'status.already_saved' => 'già salvato ({count})',
    'status.not_in_favorites' => 'non nei preferiti ({count})',
    'status.gtid_mariadb' => 'GTID_MODE non è disponibile: MariaDB non ha la variabile gtid_mode.',
    'status.gtid_version' => 'GTID_MODE richiede MySQL 5.7.6 o successivo (il server è {version}).',
    'caption.blocks_hit' => 'Blocchi in cache',
    'caption.blocks_read' => 'Blocchi letti',
    'caption.buffer_pool_read_reqs' => 'Richieste di lettura del buffer pool',
    'caption.buffer_pool_usage' => 'Utilizzo del buffer pool',
    'caption.buffer_pool_write_reqs' => 'Richieste di scrittura del buffer pool',
    'caption.bytes_in' => 'Byte in ingresso',
    'caption.bytes_out' => 'Byte in uscita',
    'caption.cache_hit_rate' => 'Percentuale di hit della cache',
    'caption.commits' => 'Commit',
    'caption.connections' => 'Connessioni',
    'caption.deadlocks' => 'Deadlock',
    'caption.disk_reads_not_from_pool' => 'Letture da disco (non dal pool)',
    'caption.disk_write_requests' => 'Richieste di scrittura su disco',
    'caption.doublewrite_writes' => 'Scritture doublewrite',
    'caption.innodb_disk_reads' => 'Letture disco InnoDB',
    'caption.innodb_disk_writes' => 'Scritture disco InnoDB',
    'caption.insert_buffer' => 'Buffer di inserimento',
    'caption.pages_created' => 'Pagine create',
    'caption.pages_flushed' => 'Pagine scaricate',
    'caption.pages_read' => 'Pagine lette',
    'caption.read_ahead' => 'Read-ahead',
    'caption.redo_log_bytes_written' => 'Byte scritti nel redo log',
    'caption.redo_log_writes' => 'Scritture del redo log',
    'caption.rollbacks' => 'Rollback',
    'caption.row_lock_time' => 'Tempo di blocco righe',
    'caption.row_lock_waits' => 'Attese di blocco righe',
    'caption.shared_buffers' => 'Buffer condivisi',
    'caption.sql_statements' => 'Istruzioni SQL',
    'caption.table_open_cache' => 'Cache delle tabelle aperte',
    'caption.temp_bytes' => 'Byte temporanei',
    'caption.temp_files' => 'File temporanei',
    'caption.transactions' => 'Transazioni',
    'caption.tuples_fetched' => 'Tuple recuperate',
    'caption.tuples_returned' => 'Tuple restituite',
    'caption.traffic' => 'Traffico',
    'caption.key_efficiency' => 'Efficienza delle chiavi',
    'caption.selects_per_second' => 'Select al secondo',
    'caption.innodb_buffer_usage' => 'Utilizzo del buffer InnoDB',
    'caption.innodb_reads_per_second' => 'Letture InnoDB al secondo',
    'caption.innodb_writes_per_second' => 'Scritture InnoDB al secondo',
    'widget.tip.bytes_in' => 'Traffico in ingresso: {Bytes_received} byte totali',
    'widget.tip.bytes_out' => 'Traffico in uscita: {Bytes_sent} byte totali',
    'widget.tip.client_connections' => 'Connessioni client: {Threads_connected}',
    'widget.tip.connections_level' => 'Livello connessioni rispetto a max_connections',
    'widget.tip.table_open_cache' => 'Rapporto di hit della cache delle tabelle aperte',
    'widget.tip.sql_statements' => 'Frequenze delle istruzioni SQL: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'Percentuale di utilizzo del buffer pool InnoDB (basata sui byte)',
    'widget.tip.buffer_pool_read_reqs' => 'Richieste di lettura del buffer pool InnoDB al secondo',
    'widget.tip.buffer_pool_write_reqs' => 'Richieste di scrittura del buffer pool InnoDB al secondo',
    'widget.tip.disk_reads_not_from_pool' => 'Letture del buffer pool InnoDB da disco al secondo',
    'widget.tip.innodb_disk_writes' => 'Byte di dati InnoDB scritti su disco al secondo',
    'widget.tip.disk_write_requests' => 'Richieste di scrittura sui file dati InnoDB al secondo',
    'widget.tip.innodb_disk_reads' => 'Byte di dati InnoDB letti da disco al secondo',
    'widget.tip.row_lock_waits' => 'Attese di blocco righe InnoDB al secondo',
    'widget.tip.row_lock_time' => 'Tempo di blocco righe InnoDB al secondo (millisecondi)',
    'widget.tip.pages_flushed' => 'Pagine InnoDB scaricate al secondo',
    'widget.tip.pages_created' => 'Pagine InnoDB create al secondo',
    'widget.tip.pages_read' => 'Pagine InnoDB lette al secondo',
    'widget.tip.insert_buffer' => 'Dimensione del buffer di inserimento InnoDB (pagine)',
    'widget.tip.read_ahead' => 'Read-ahead del buffer pool InnoDB al secondo',
    'widget.tip.redo_log_bytes_written' => 'Byte scritti nel redo log InnoDB al secondo',
    'widget.tip.redo_log_writes' => 'Scritture del redo log InnoDB al secondo',
    'widget.tip.doublewrite_writes' => 'Scritture doublewrite InnoDB al secondo',
    'widget.tip.alter_rate' => 'Frequenza comandi ALTER (tutti i Com_alter_*)',
    'widget.tip.create_rate' => 'Frequenza comandi CREATE (tutti i Com_create_*)',
    'widget.tip.drop_rate' => 'Frequenza comandi DROP (tutti i Com_drop_*)',
    'widget.tip.select_rate' => 'Frequenza SELECT',
    'widget.tip.insert_rate' => 'Frequenza INSERT',
    'widget.tip.update_rate' => 'Frequenza UPDATE',
    'widget.tip.delete_rate' => 'Frequenza DELETE',
    'widget.tip.tuples_fetched_total' => 'Righe recuperate dal DB: {tup_fetched} totali',
    'widget.tip.tuples_fetched_rate' => 'Frequenza di recupero righe',
    'widget.tip.tuples_returned_total' => 'Righe restituite al client: {tup_returned} totali',
    'widget.tip.tuples_returned_rate' => 'Frequenza di restituzione righe',
    'widget.tip.blocks_read_total' => 'Blocchi disco letti: {blks_read} totali',
    'widget.tip.blocks_hit_total' => 'Hit della cache buffer: {blks_hit} totali',
    'widget.tip.active_backends' => 'Backend attivi: {numbackends}',
    'widget.tip.transaction_rates' => 'Frequenze transazioni: commit / rollback',
    'widget.tip.commit_rate' => 'Frequenza dei commit delle transazioni',
    'widget.tip.rollback_rate' => 'Frequenza dei rollback delle transazioni',
    'widget.tip.tuple_insert_rate' => 'Frequenza di inserimento tuple',
    'widget.tip.tuple_update_rate' => 'Frequenza di aggiornamento tuple',
    'widget.tip.tuple_delete_rate' => 'Frequenza di eliminazione tuple',
    'widget.tip.deadlock_rate' => 'Frequenza dei deadlock',
    'widget.tip.shared_buffers' => 'Configurazione shared_buffers di PostgreSQL (dimensione del buffer pool)',
    'widget.tip.cache_hit_rate' => 'Rapporto di hit della cache buffer: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Frequenza di creazione file temporanei',
    'widget.tip.temp_bytes' => 'Byte scritti nei file temporanei al secondo',
    'dash.data_written' => 'dati scritti {kb} kB/s',
    'dash.writes_ops' => 'scritture {n} ops/s',
    'dash.log_written' => 'log scritto {kb} kB/s',
    'dash.read_reqs' => 'richieste di lettura {n} pagine/s',
    'dash.write_reqs' => 'richieste di scrittura {n} pagine/s',
    'dash.disk_reads' => 'letture da disco {n} /s',
    'metrics.busy_proxy' => 'proxy di attività {pct}%',
    'metrics.cpu_load' => 'carico CPU {load}',
    'metrics.no_samples' => 'ancora nessun campione',
    'metrics.connections_count' => '{count} connessioni',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Metriche del server',
    'explain.query_block' => 'BLOCCO QUERY #{id} (costo: {cost})',
    'variables.footer_tree' => '[j/k] albero',
    'variables.footer_rows' => '[j/k] righe',
    'variables.footer_categories' => '[c] categorie',
    'variables.footer_edit' => '[Enter/e] modifica (dinamiche)',
    'variables.footer_rw_filter' => '[w] filtro rw',
    'variables.footer_search' => '[s] cerca',
    'variables.footer_back' => '[q] indietro',
    'srv.title' => 'Stato del server',
    'srv.features' => 'Funzionalità',
    'srv.stored_programs' => 'Programmi memorizzati',
    'srv.directories' => 'Directory',
    'srv.data_directory' => 'Directory dei dati',
    'srv.temp_directory' => 'Directory temporanea',
    'srv.log_directory' => 'Directory dei log',
    'srv.pid_file' => 'File PID',
    'srv.tls_active' => 'TLS attivo',
    'srv.tls_cipher' => 'Cifratura TLS',
    'srv.tls_version' => 'Versione TLS',
    'srv.ssl_support' => 'Supporto SSL',
    'srv.ssl_ca' => 'CA SSL',
    'srv.ssl_cert' => 'Certificato SSL',
    'srv.ssl_key' => 'Chiave SSL',
    'srv.tls_secure' => 'TLS / connessione sicura',
    'srv.replication' => 'Replica',
    'srv.repl_denied' => 'Privilegi insufficienti (REPLICATION CLIENT)',
    'srv.repl_error' => 'Errore nell’accesso allo stato della replica',
    'srv.not_configured' => 'Non configurato',
    'srv.channel' => 'Canale',
    'srv.source_host' => 'Host di origine',
    'srv.source_port' => 'Porta di origine',
    'srv.replica_io' => 'IO della replica in esecuzione',
    'srv.replica_sql' => 'SQL della replica in esecuzione',
    'srv.seconds_behind' => 'Secondi di ritardo dalla sorgente',
    'srv.relay_log_file' => 'File del relay log',
    'srv.relay_pos' => 'Pos. relay',
    'srv.repl_channel_title' => 'Replica › {channel}',
    'srv.default' => 'Predefinito',
    'srv.firewall' => 'Firewall',
    'srv.mysql_firewall' => 'Firewall MySQL',
    'srv.caught_up' => '0 s (allineato)',
    'srv.host' => 'Host',
    'srv.socket' => 'Socket',
    'srv.port' => 'Porta',
    'srv.version' => 'Versione',
    'srv.uptime' => 'Uptime',
    'srv.running_since' => 'In esecuzione dal',
    'srv.unknown' => 'Sconosciuto',
    'srv.server_information' => 'Informazioni sul server',
    'perf.tab_easy_setup' => 'Configurazione semplice',
    'perf.tab_instruments' => 'Strumenti',
    'perf.tab_consumers' => 'Consumatori',
    'perf.tab_actors' => 'Attori',
    'perf.tab_objects' => 'Oggetti',
    'perf.tab_threads' => 'Thread',
    'perf.tab_options' => 'Opzioni',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[SOLA LETTURA]',
    'perf.nothing_to_commit' => 'Niente da applicare',
    'perf.commit_planning_failed' => 'Pianificazione dell’applicazione non riuscita: {error}',
    'perf.err_prepare' => 'Preparazione dell’istruzione non riuscita: {sql}',
    'perf.committed_one' => 'Applicata {count} istruzione',
    'perf.committed_other' => 'Applicate {count} istruzioni',
    'perf.applied_one' => '{label} applicato — {count} istruzione',
    'perf.applied_other' => '{label} applicato — {count} istruzioni',
    'perf.commit_failed' => '{label} non riuscito all’istruzione {position} di {total}: {error} — le istruzioni precedenti sono state applicate, lo stato del server è parziale.',
    'perf.err_access_denied' => 'Accesso negato: privilegi necessari mancanti',
    'perf.err_not_enabled' => 'Performance Schema non è abilitato',
    'perf.err_cannot_connect' => 'Impossibile connettersi al database',
    'perf.state_fully' => 'Completamente abilitato',
    'perf.state_default' => 'Configurazione predefinita',
    'perf.state_custom' => 'Configurazione personalizzata',
    'perf.state_disabled' => 'Disabilitato',
    'perf.state_unknown' => 'Sconosciuto',
    'perf.current_state' => 'Stato corrente: {state}',
    'perf.readonly_note' => '(Modalità sola lettura - nessun privilegio di modifica)',
    'perf.keys_hint' => 'Tasto "[" Abilita Performance Schema completo   Tasto "]" Disabilita   Tasto "." Ripristina predefiniti',
    'perf.default_instruments' => 'Strumenti predefiniti: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Consumatori predefiniti: events_statements_history, events_waits_history, ecc.',
    'perf.none_instruments' => '(nessuno strumento disponibile)',
    'perf.none_consumers' => '(nessun consumatore disponibile)',
    'perf.none_actors' => '(nessun attore configurato)',
    'perf.none_objects' => '(nessuna regola oggetto configurata)',
    'perf.none_threads' => '(nessun thread disponibile)',
    'perf.total_instruments_one' => 'Totale: {count} strumento',
    'perf.total_instruments_other' => 'Totale: {count} strumenti',
    'perf.total_consumers_one' => 'Totale: {count} consumatore',
    'perf.total_consumers_other' => 'Totale: {count} consumatori',
    'perf.total_actors_one' => 'Totale: {count} attore',
    'perf.total_actors_other' => 'Totale: {count} attori',
    'perf.total_objects_one' => 'Totale: {count} regola oggetto',
    'perf.total_objects_other' => 'Totale: {count} regole oggetto',
    'perf.total_threads_one' => 'Totale: {count} thread',
    'perf.total_threads_other' => 'Totale: {count} thread',
    'perf.col_thread_id' => 'ID thread',
    'perf.col_name' => 'Nome',
    'perf.col_type' => 'Tipo',
    'perf.col_user' => 'Utente',
    'perf.timer_options' => 'Opzioni del timer',
    'perf.none_timers' => '(nessun timer disponibile - sola lettura)',
    'perf.col_timer_name' => 'Nome del timer',
    'perf.col_implementation' => 'Implementazione',
    'perf.col_scale_factor' => 'Fattore di scala',
    'perf.timer_readonly_note' => 'La configurazione del timer è di sola lettura (determinata dalla build del server)',
    'perf.footer_nav' => '[j/k] naviga  [Space] attiva/disattiva',
    'perf.footer_actions' => '  [c] prepara applicazione ([y] esegui)  [r] ripristina',
    'perf.footer_back' => '  [q] indietro',
    'perf.pending_one' => 'In sospeso: {count} modifica',
    'perf.pending_other' => 'In sospeso: {count} modifiche',
    'cli.err_not_tty' => 'questo launcher pilota un terminale interattivo e rifiuta di inviare ANSI a una pipe (uscita 2) — eseguilo in un pty (es. script -qec \'candy-sql app.sqlite\' /dev/null) oppure esegui il rendering headless dal codice della libreria (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'registrazione della cronologia DISABILITATA ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' richiede TLS verificato — il trasporto di amministrazione ReactPHP non può soddisfarlo, quindi l’aggiornamento live dell’amministrazione è DISABILITATO e query/caricamenti di tabelle vengono eseguiti in modo sincrono sulla connessione TLS.',
    'cli.no_connection_selected' => 'nessuna connessione selezionata — passa un percorso, un DSN o --connect=<name>',
    'cli.usage_line' => 'USO: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # per le informazioni d’uso complete',
    'cli.tip_saved_connections' => '       Suggerimento: salva connessioni con nome in {path} e avvia con --connect=<name>.',
    'help.summary' => 'candy-sql - Client SQL da terminale per SQLite, MySQL e PostgreSQL',
    'help.usage' => 'UTILIZZO
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGOMENTI
  path-or-dsn
    Percorso del file di database SQLite, ad es.: app.sqlite
    Oppure una stringa DSN, ad es.:  mysql://user:pass@localhost:3306/dbname

  Se omesso del tutto: in un terminale interattivo con connessioni
  salvate, un selettore le propone; altrimenti viene stampata una breve
  riga d\'uso e il processo termina con stato 2 (invocazione errata) —
  eseguire --help per questa schermata completa.',
    'help.options' => 'OPZIONI
  --help, -h
    Mostra questa schermata di aiuto.

  --version, -V
    Mostra il numero di versione.

  --connect=<name>
    Si collega alla voce indicata del file delle connessioni salvate
    ($XDG_CONFIG_HOME/candy-sql/connections.json, in alternativa
    $HOME/.config/candy-sql/connections.json). Non può essere combinata
    con argomenti di connessione espliciti. Le password non vengono mai
    salvate su disco: una voce indica il proprio segreto tramite "pass-env"
    e la variabile viene letta al momento della connessione.
    Vedere CONNESSIONI SALVATE più sotto.',
    'help.option_dsn' => '  --dsn=<dsn>
    Stringa di connessione DSN completa.
    Formato: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Esempi:
      mysql://root:secret@localhost:3306/mydb?ssl-mode=require
      pgsql://postgres:pass@db.example.com:5432/production
      sqlite:///var/data/app.sqlite
      sqlite://:memory:',
    'help.option_params' => '  --driver=<driver>
  --db=<dbname>
  --host=<host>
  --port=<port>
  --user=<user>
  --pass=<pass>
  --ssl-mode=<mode>
    Parametri di connessione singoli (tutti tranne --driver e --db sono facoltativi).
    --driver deve essere uno tra: sqlite, mysql, pgsql
    --ssl-mode vale "prefer" per impostazione predefinita su MySQL. "require" e
    "verify_*" impongono TLS con verifica del certificato del server; la fiducia
    nella CA va configurata lato server o tramite my.cnf (vedere il README). In
    questi modi verificati il trasporto ReactPHP, capace di testo in chiaro, viene
    rifiutato — le query girano in modo sincrono sulla connessione TLS e
    l\'aggiornamento live dell\'amministrazione è disattivato (un avviso all\'avvio
    e la riga di stato dell\'app lo segnalano).
    Preferire CANDY_SQL_PASS a --pass= per tenere la password fuori dalla
    riga di comando del processo (visibile con ps).',
    'help.examples' => 'ESEMPI
  # SQLite (predefinito — percorso semplice o DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL tramite DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL tramite opzioni singole
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'CONNESSIONI SALVATE
  Elenca una volta i server con nome, collegati sempre:

    $HOME/.config/candy-sql/connections.json  (cartella 0700, file 0600)',
    'help.saved_fields' => '  Campi: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Ogni altro campo viene rifiutato, e un campo "pass" in chiaro
  viene sempre rifiutato — far puntare pass-env a una variabile. Un avvio
  senza argomenti in un terminale interattivo presenta queste voci come
  selettore; --connect=<name> ne apre una direttamente.',
    'help.editing' => 'MODIFICA
  Il pannello Rows di un\'anteprima TABLE è una griglia modificabile: h/l
  (o ←/→) spostano il cursore di cella, e tre combinazioni modificano i
  dati — ciascuna mostra l\'istruzione esatta nella riga di stato e non
  scrive nulla finché Invio non conferma:

    e   modifica la cella sotto il cursore (Ctrl+N imposta NULL)
    o   inserisce una riga (Invio scorre le colonne; un valore vuoto
        lascia la colonna al suo DEFAULT, Ctrl+N memorizza NULL)
    D   elimina la riga sotto il cursore

  Le righe sono indirizzate solo tramite la loro PRIMARY KEY completa: una
  tabella senza, una chiave NULL/binaria, una cella binaria e qualsiasi
  risultato di query vengono rifiutati con una riga di stato esplicita. I
  valori sono parametri associati; ogni scrittura gira in una transazione
  annullata a meno che sia stata toccata esattamente una riga (un UPDATE che
  scrive il valore già presente viene segnalato come nessuna modifica).
  Esc annulla una riga di modifica aperta.',
    'help.editing_readonly' => '  --read-only
    Disattiva le combinazioni di modifica per la sessione. Protegge da una
    svista; non filtra le istruzioni del pannello Query — per una garanzia
    rigida, collegarsi come utente di database in sola lettura.',
    'help.history' => 'CRONOLOGIA
  Per impostazione predefinita ogni istantanea di polling amministrativo
  viene registrata in $HOME/.candy-sql/history.db (cartella 0700 / file 0600)
  come traccia di audit offline. Le pagine Dashboard e Reports dell\'app non
  leggono questo file — interrogano il server in tempo reale.',
    'help.history_flags' => '  --no-history
    Disattiva del tutto la registrazione (nulla viene scritto su disco).

  --history=<path>
    Salva le istantanee in <path> invece che nella posizione predefinita.',
    'help.keyboard' => 'SCORCIATOIE DA TASTIERA (TUI)
  Tab          Fa ruotare il focus tra i pannelli (Tables → Rows → Query → Admin)
  ↑ / ↓        Naviga tra le righe dell\'elenco (o j/k) — nel pannello Tables lo
               spostamento è solo locale; non carica mai un\'anteprima
  /            (Pannello Tables) apre una riga di filtro live sui nomi delle tabelle;
               le lettere la estendono, ↑/↓ muovono il cursore, Invio conferma e
               carica, Backspace modifica, Esc abbandona e cancella
  Enter        Carica le righe della tabella selezionata
  Space        Carica le righe della tabella selezionata (alias di Enter)
  PgDn / PgUp  (Pannello Tables/Rows, su un\'anteprima di tabella) sfoglia l\'anteprima
               in finestre di 100 righe — il limite di navigazione è diventato la dimensione della pagina
  Ctrl+Enter   Esegue la query corrente
  Ctrl+R       Esegue la query corrente
  Ctrl+E       Esegue la query corrente
  EXPLAIN …    (Editor Query, parola chiave iniziale) un EXPLAIN digitato passa da
               ExplainView: il pannello Rows mostra l\'albero del piano colorato, non
               la griglia piatta del provider — e qualsiasi modificatore ANALYZE viene
               RIMOSSO, l\'ispettore non esegue mai ciò che ispeziona
  e / o / D    (Pannello Rows, anteprima di tabella) modifica la cella / inserisce una
               riga / elimina la riga — vedere MODIFICA; h/l spostano il cursore di cella
  Ctrl+Space   Completa al cursore (editor Query): parole chiave SQL, nomi di tabella
               dopo FROM/JOIN/INTO/UPDATE e colonne nel contesto (WHERE/SELECT/ORDER BY,
               qualificate `alias.` / `table.`, l\'elenco INSERT INTO t (...)) dallo
               schema caricato. Un candidato viene completato, un prefisso comune
               avanza, altrimenti la riga di stato elenca i candidati
  Ctrl+F       Aggiunge la query corrente all\'elenco dei preferiti (solo stato di
               sessione — nessun pannello lo mostra e non viene salvato)
  Ctrl+Shift+F Rimuove la query corrente dall\'elenco dei preferiti
  1-8          Salta a un pannello di amministrazione (solo con il pannello Admin attivo)
  q            Esce dai pannelli Tables/Rows; da Admin torna a Tables
  x            (Tables/Rows, risultato live) esporta le righe correnti in un percorso
               CSV — il rifiuto di sovrascrittura resta armato finché Invio non conferma
  i            (Tables/Rows) importa un file CSV in una tabella indicata
  Ctrl+S       Esporta il risultato/anteprima live in CSV da qualsiasi pannello
  Esc          Esce dai pannelli Tables/Rows; annulla una finestra di dialogo Admin aperta
  Backspace    Elimina il carattere prima del cursore (editor Query)',
    'help.see_also' => 'VEDERE ANCHE
  https://github.com/sugarcraft/candy-sql',
];
