<?php

/**
 * Dutch translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite-bestand niet gevonden: {path}',
    'complete.in_literal' => 'niets aan te vullen binnen een tekenreeks of opmerking',
    'complete.nothing' => 'niets aan te vullen — typ het begin van een sleutelwoord',
    'complete.none_keyword' => 'geen sleutelwoord vult "{word}" aan',
    'complete.none_table' => 'geen tabel vult "{word}" aan',
    'complete.none_column' => 'geen kolom vult "{word}" aan',
    'complete.none_qualified' => 'geen kolom van {qualifier} vult "{word}" aan',
    'complete.list' => '{count} vullen "{word}" aan: {list}{more}',
    'complete.done' => 'aangevuld {word} → {insert}',
    'complete.inserted' => 'ingevoegd {insert}',
    'edit.refuse_readonly' => 'alleen-lezen sessie — bewerken is uitgeschakeld',
    'edit.refuse_no_table' => 'open eerst een tabelvoorbeeld — queryresultaten zijn niet ter plaatse te bewerken',
    'edit.refuse_no_rows' => 'geen rij onder de cursor',
    'edit.refuse_no_pk' => 'deze tabel heeft geen primaire sleutel — rijen zijn niet veilig aan te wijzen; schrijf de instructie in het queryvenster',
    'edit.refuse_pk_hidden' => 'de primaire sleutel van deze rij is NULL of een binaire plaatshouder — de rij is niet aan te wijzen',
    'edit.refuse_binary' => 'binaire of grote cellen zijn hier niet te bewerken — gebruik een instructie in het queryvenster',
    'edit.refuse_schema' => 'schema van {table} niet te lezen — bewerken geweigerd',
    'edit.prompt_cell' => 'bewerk {table}.{column} = {value}  (enter voorbeeld · ctrl+n NULL · esc annuleert)',
    'edit.prompt_insert' => 'nieuwe rij in {table} — {column} ({type}) [{n}/{total}] = {value}  (enter volgende · leeg = DEFAULT · ctrl+n NULL · esc annuleert)',
    'edit.prompt_confirm' => '{sql}  (enter voert uit · esc annuleert)',
    'edit.cancelled' => 'bewerking geannuleerd',
    'edit.unchanged' => 'waarde ongewijzigd — niets geschreven',
    'edit.done_update' => '1 rij bijgewerkt in {table}',
    'edit.done_insert' => '1 rij ingevoegd in {table}',
    'edit.done_delete' => '1 rij verwijderd uit {table}',
    'edit.none_changed' => 'geen rij gewijzigd — de waarde stond er al of de rij is weg; voorbeeld ververst',
    'edit.failed' => 'schrijven mislukt — teruggedraaid, niets gewijzigd: {reason}',
    'edit.too_many' => 'geweigerd: de instructie zou {count} rijen raken — teruggedraaid, niets gewijzigd',
    'ui.title.tables' => ' tabellen ',
    'ui.title.rows' => ' rijen ',
    'ui.title.query' => ' query ',
    'ui.title.admin' => ' beheer ',
    'ui.title.explain' => ' plan ',
    'ui.help.query' => 'tab  paneel wisselen  ·  ctrl+r / ctrl+e / ctrl+enter  uitvoeren  ·  enter  nieuwe regel  ·  ↑/↓  geschiedenis  ·  esc / ctrl+c  lopende query annuleren  ·  ctrl+c  afsluiten bij inactiviteit',
    'ui.help.rows' => 'tab  paneel wisselen  ·  j/k  rij  ·  h/l  cel  ·  e  bewerken  ·  o  nieuwe rij  ·  D  verwijderen  ·  y  kopiëren  ·  x  exporteren  ·  q / esc  stoppen',
    'ui.help.browse' => 'tab  paneel wisselen  ·  enter  tabel laden  ·  x  exporteren  ·  i  importeren  ·  q / esc  stoppen',
    'ui.help.empty' => 'tab  paneel wisselen  ·  q / esc  stoppen',
    'param.prompt' => 'placeholder {index}/{total} ({label}) - typ de waarde, Enter bindt (leeg = NULL), Esc breekt af',
    'param.line' => 'parameter {label} ({index}/{total}) = {value} · leeg = NULL · Enter volgende · Esc afbreken',
    'param.cancel' => 'parameters invoeren afgebroken - query niet uitgevoerd',
    'param.mixed' => '? en :name mengen wordt niet ondersteund - kies één stijl',
    'help.title' => 'toetshulp - {pane}-venster',
    'help.close' => 'druk op een willekeurige toets om te sluiten',
    'help.cycle' => 'wissel van focusvenster',
    'help.list_cursor' => 'lijstcursor verplaatsen',
    'help.editor_cursor' => 'editorcursor verplaatsen',
    'help.cell_cursor' => 'celcursor verplaatsen',
    'help.edit_cell' => 'bewerk de cel onder de cursor',
    'help.insert_row' => 'rij invoegen',
    'help.delete_row' => 'verwijder de rij onder de cursor',
    'help.yank_cell' => 'cel naar klembord kopiëren',
    'help.load_table' => 'gemarkeerde tabel laden',
    'help.newline' => 'nieuwe regel in de editor',
    'help.run' => 'query uitvoeren',
    'help.fav_add' => 'opslaan in favorieten',
    'help.fav_remove' => 'verwijderen uit favorieten',
    'help.backspace' => 'laatste teken wissen',
    'help.file_line' => 'export-/importregel openen',
    'help.history_trend' => 'geschiedenistrend lezen',
    'help.tx' => 'transactiebesturing (:tx)',
    'help.param_prompt' => 'vraagt elke gebonden parameter',
    'help.overlay' => 'deze toetshulp',
    'help.quit' => 'annuleren / stoppen per venster',
    'help.cancel_quit' => 'lopende query annuleren, anders stoppen',
    'help.ad_pause' => 'automatisch verversen pauzeren/hervatten',
    'help.ad_reset' => 'paginagegevens verversen (cache legen)',
    'help.ad_rows' => 'rijen navigeren',
    'help.ad_edit' => 'geselecteerde variabele bewerken',
    'help.ad_rw_filter' => 'lees/schrijf-filter wisselen',
    'help.ad_search' => 'zoekveld focussen',
    'help.ad_sleeping_filter' => 'slapende verbergen wisselen',
    'help.ad_kill' => 'kill van verbinding/query voorbereiden',
    'help.ad_confirm' => 'voorbereide actie uitvoeren (anders annuleren)',
    'help.ad_stage' => 'commit voorbereiden (PerfSchema)',
    'help.ad_units' => 'eenheidnotatie wisselen (Rapporten)',
    'help.ad_csv' => 'CSV in paginastaat bouwen (Rapporten)',
    'help.ad_alerts' => 'alle waarschuwingen sluiten (Dashboard)',
    'help.ad_debug' => 'log / foustriep wissen (Debug)',
    'help.ad_pages' => 'beheerpagina wisselen',
    'yank.refuse_no_cell' => 'geen cel onder de cursor',
    'yank.osc52' => 'cel gekopieerd naar klembord - terminal kan OSC 52 negeren',
    'yank.plain' => 'geen OSC 52 in deze terminal - celtekst: {text}',
    'yank.plain_null' => 'geen OSC 52 in deze terminal - de cel is NULL',
    'yank.too_big' => 'cel is {bytes} bytes - boven de OSC 52-limiet',
    'ui.help.result' => 'tab  paneel wisselen  ·  j/k  rij  ·  h/l  cel  ·  y  kopiëren  ·  x  exporteren  ·  q / esc  stoppen',
    'ui.help.admin' => '1-{count}  pagina kiezen  ·  j/k  navigeren  ·  p  pauzeren  ·  r  resetten  ·  tab  paneel wisselen  ·  q  terug  ·  esc  dialoog of query annuleren',
    'ui.no_tables' => '(geen tabellen)',
    'ui.no_match' => '(geen overeenkomst)',
    'ui.loading' => 'laden…',
    'ui.empty' => '(leeg)',
    'ui.admin_unavailable' => 'beheerpagina niet beschikbaar: {reason}',
    'ui.error' => 'fout: {message}',
    'io.prompt_export' => 'naar CSV → {path}  (enter schrijft · esc annuleert)',
    'io.prompt_replace' => '{path} VERVANGEN?  (enter bevestigt · esc annuleert)',
    'io.prompt_import_path' => 'CSV importeren ← {path}  (enter · esc annuleert)',
    'io.prompt_import_table' => '{source} importeren → tabel {table}  (enter · esc annuleert)',
    'bar.tables' => 'Tabellen: {count}',
    'bar.cycle' => 'Tab:wisselen',
    'bar.navigate' => '↑↓:navigeren',
    'bar.load' => 'Enter:laden',
    'bar.run' => 'Ctrl+R:uitvoeren',
    'bar.select' => '1-{count}:kiezen',
    'bar.nav' => 'j/k:nav',
    'bar.quit' => 'q:stoppen',
    'bar.paused' => '[GEPAUZEERD]',
    'bar.tx' => '[TX]',
    'picker.title' => 'opgeslagen verbindingen',
    'picker.help' => '[↑↓/j/k] kiezen   [Enter] verbinden   [q] annuleren',
    'result.empty' => '(lege resultatenset)',
    'explain.no_plan' => '(geen queryplan — de query leverde geen rijen op)',
    'explain.title' => ' QUERYPLAN ',
    'status.live_refresh_off' => 'live vernieuwen van beheer uitgeschakeld — TLS-geverifieerde ssl-mode (alleen synchroon transport)',
    'status.async_failed' => 'Asynchroon commando mislukt: {message}',
    'status.file_cancelled' => 'bestandsregel geannuleerd',
    'status.path_required' => 'een pad is vereist — esc annuleert',
    'status.no_such_file' => 'bestand niet gevonden — blijf typen of esc annuleert',
    'status.table_required' => 'geef de doeltabel op — esc annuleert',
    'status.export_failed' => 'exporteren mislukt: {message}',
    'status.exported' => '{count} rijen geëxporteerd naar {path}',
    'status.import_failed' => 'importeren mislukt: {message}',
    'status.imported' => '{path} geïmporteerd in {table}',
    'status.history' => 'geschiedenis {pos}/{count}',
    'status.conn_lost' => 'verbinding verbroken; probeer opnieuw',
    'status.destructive' => '⚠ destructieve instructie — ctrl+r nogmaals (ongewijzigd) om te bevestigen',
    'status.running' => 'query wordt uitgevoerd…',
    'status.explain_failed' => 'EXPLAIN mislukt: {message}',
    'status.explain_plan' => 'uitvoeringsplan — elke query of tabelvoorbeeld vervangt het',
    'status.end_of_table' => 'einde van tabel — geen volgende pagina',
    'status.first_page' => 'al op de eerste pagina',
    'status.nothing_to_save' => 'niets om op te slaan',
    'status.saved' => 'opgeslagen ({count})',
    'status.removed' => 'verwijderd ({count})',
    'status.row_one' => '1 rij',
    'status.rows' => '{count} rijen',
    'status.page' => 'pagina {page} (rijen {first}–{last})',
    'editor.placeholder' => '-- typ SQL, ctrl+r om uit te voeren --',
    'err.async_query_unsupported' => 'Dit stuurprogramma ondersteunt geen asynchrone queries',
    'err.async_browse_unsupported' => 'Asynchroon bladeren wordt door dit stuurprogramma niet ondersteund',
    'dash.title' => 'Prestatiedashboard',
    'dash.fetching' => 'Servermetrieken ophalen...',
    'dash.back_hint' => 'Druk op [q] om terug te keren naar de tabelweergave',
    'dash.recent_queries' => 'Recente queries',
    'dash.no_queries' => '(nog geen queries)',
    'dash.header_pg' => 'Prestatiedashboard{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Prestatiedashboard{status} | MySQL {version} | Uptime: {uptime}',
    'dash.uptime_segment' => ' | Uptime: {uptime}',
    'pane.process_list' => 'Proceslijst',
    'pane.variables' => 'Variabelen',
    'pane.status' => 'Status',
    'pane.query_stats' => 'Querystatistieken',
    'pane.dashboard' => 'Dashboard',
    'pane.table_stats' => 'Tabelstatistieken',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Debug',
    'page.no_data' => 'Nog geen gegevens beschikbaar.',
    'page.error' => 'Fout: {message}',
    'page.loading' => 'Laden…',
    'page.fetching' => 'Gegevens ophalen van de server.',
    'debug.title' => 'Debuglog',
    'debug.entries' => ' ({count} items)',
    'debug.errors_only' => '[alleen fouten]',
    'debug.empty' => '(geen logregels)',
    'debug.more' => '↑ nog {count} items',
    'debug.help' => '[j/k] nav  [f] alleen fouten  [c] wissen  [q] terug',
    'reports.cannot_access' => 'Geen toegang tot prestatierapporten: {message}',
    'reports.title' => 'Prestatierapporten',
    'reports.categories' => 'Categorieën',
    'reports.no_data' => '(geen gegevens)',
    'reports.loading' => 'Rapporten laden…',
    'reports.loading_one' => '{caption} laden…',
    'reports.no_data_for' => 'Geen gegevens beschikbaar voor {caption}',
    'reports.help' => '[j/k] rijen  [h/l] categorie  [[]/[]] rapport  [,/.] kolom  [c] eenheid  [r] vernieuwen  [x] exporteren  [q] terug',
    'reports.unavailable' => 'Prestatierapporten niet beschikbaar',
    'reports.ensure' => 'Zorg voor MySQL 5.6.6+ met performance_schema=enabled en het sys-schema geïnstalleerd.',
    'conn.kill_cancelled' => 'Kill geannuleerd',
    'conn.none_selected' => 'Geen verbinding geselecteerd',
    'conn.refuse_background' => 'Weigert een achtergrondthread te beëindigen',
    'conn.verb_kill_query' => 'Query beëindigen',
    'conn.verb_kill' => 'Beëindigen',
    'conn.sent' => '{verb} verzonden naar verbinding {id}',
    'conn.failed' => '{verb} mislukt voor verbinding {id}',
    'conn.confirm_verb_query' => 'Lopende query annuleren op',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} verbinding {id} ({user}@{host})?  [y] bevestigen   [andere toets] annuleren',
    'conn.help_empty' => '[j/k] nav  [f] filter  [r] vernieuwen',
    'conn.help_full' => '[j/k] nav  [K] kill  [X] query-kill  [f] filter  [r] vernieuwen',
    'conn.vars_fail' => 'Servervariabelen kunnen niet worden opgehaald',
    'vars.load_fail' => 'Variabelen kunnen niet worden geladen: {message}',
    'vars.unknown_error' => 'Onbekende fout',
    'vars.not_dynamic' => 'Fout 1238: \'{name}\' is niet dynamisch — druk op [p] voor PERSIST_ONLY',
    'vars.new_value' => 'nieuwe waarde > ',
    'vars.edit_head' => 'Bewerken ({mode}) {name} (huidig: {current})',
    'vars.scope_explain' => 'GLOBAL = alleen runtime (verloren bij herstart) · PERSIST = runtime + configuratiebestand · PERSIST_ONLY = alleen configuratiebestand (geldt bij volgende herstart)',
    'vars.edit_help' => 'Voer de nieuwe waarde in, [p] modus wisselen, [Enter] bevestigen, [Esc] annuleren',
    'reports.back' => '[q] terug',
    'err.dsn_empty' => 'DSN mag niet leeg zijn',
    'err.dsn_no_separator' => 'Ongeldige DSN: scheidingsteken "://" ontbreekt',
    'err.driver_unsupported' => 'Niet-ondersteund stuurprogramma: {driver}',
    'err.dsn_sqlite_invalid' => 'Ongeldige SQLite-DSN',
    'err.dsn_parse_failed' => 'Ongeldige DSN: parse_url() mislukt',
    'err.dsn_no_host' => 'Ongeldige DSN: host ontbreekt',
    'err.driver_choose' => 'Niet-ondersteund stuurprogramma: {driver} — kies uit {list}',
    'err.args_missing' => 'Vereiste argumenten ontbreken: geef zowel --driver als --db op (of gebruik --dsn)',
    'err.opt_needs_value' => 'Optie --{name} vereist een waarde',
    'err.opt_unknown' => 'Onbekende optie: --{name}',
    'err.csv_not_found' => 'CSV-bestand niet gevonden: {path}',
    'err.csv_unreadable' => 'Het CSV-bestand \'{path}\' kan niet worden gelezen: controleer of het bestaat en leesbaar is',
    'err.csv_no_headers' => 'CSV-koppen kunnen niet worden gelezen uit: {path}',
    'err.csv_no_columns' => 'CSV-bestand heeft geen kolommen: {path}',
    'err.dest_unwritable' => 'De bestemming \'{path}\' kan niet worden geopend voor schrijven: controleer of de map bestaat en beschrijfbaar is',
    'err.export_buffer' => 'De exportbuffer kon niet worden aangemaakt',
    'err.csv_partial' => 'CSV-export van {table} schreef {written} van {expected} rijen — de tabel veranderde tijdens het exporteren of het stuurprogramma negeerde het paginavenster; een onvolledig bestand wordt niet als compleet gepresenteerd',
    'err.sql_partial' => 'SQL-dump van {table} schreef {written} van {expected} rijen — de tabel veranderde tijdens de dump of het stuurprogramma negeerde het paginavenster; een onvolledig bestand wordt niet als compleet gepresenteerd',
    'err.table_unreadable' => 'Tabel niet gevonden of onleesbaar: {table} ({message})',
    'err.dml_no_prepare' => 'deze verbinding kan geen prepared statements uitvoeren',
    'err.dml_refused' => 'de server weigerde de instructie',
    'alert.connection_critical' => 'Verbindingsgebruik kritiek hoog',
    'alert.connection_elevated' => 'Verbindingsgebruik verhoogd',
    'alert.aborted_elevated' => 'Percentage afgebroken verbindingen verhoogd',
    'alert.thread_elevated' => 'Aantal actieve threads verhoogd',
    'alert.slow_query_time' => 'Trage-querytijd ingesteld op {seconds}s',
    'alert.connections_at' => 'Verbindingen op {pct}% van het maximum ({connected}/{max})',
    'alert.connection_error_one' => '{count} verbindingsfout gedetecteerd',
    'alert.connection_error_other' => '{count} verbindingsfouten gedetecteerd',
    'alert.threshold_ratio_connection_warning' => 'Waarschuwingsdrempel voor verbindingen moet tussen 0.0 en 1.0 liggen',
    'alert.threshold_ratio_connection_critical' => 'Kritieke drempel voor verbindingen moet tussen 0.0 en 1.0 liggen',
    'alert.threshold_ratio_thread_running' => 'Drempel voor actieve threads moet tussen 0.0 en 1.0 liggen',
    'alert.threshold_aborted_rate' => 'Drempel voor afbreekpercentage moet >= 0.0 zijn',
    'alert.threshold_slow_query' => 'Drempel voor trage queries moet >= 0.0 zijn',
    'alert.threshold_connection_errors' => 'Drempel voor verbindingsfouten moet >= 0 zijn',
    'history.err_mkdir' => 'Kan geschiedenismap niet aanmaken: {dir}',
    'history.err_chmod' => 'Kan geschiedenisbestand niet beveiligen: {path} (modus {mode})',
    'history.err_unusable' => 'Geschiedenisopslag is onbruikbaar: {error} — verwijder het geschiedenisbestand of herstel de rechten.',
    'query.cancelled' => 'Asynchrone query geannuleerd',
    'tx.unknown' => 'Onbekend commando — :tx start, bevestigt of rolt een transactie terug',
    'tx.async_refusal' => ':tx dekt de synchrone sessie; de asynchrone transport heeft nog geen gecoördineerde transactietak — voer expliciet BEGIN/COMMIT uit of gebruik het synchrone pad',
    'tx.already_open' => 'Er is al een transactie open — :tx commit of :tx rollback',
    'tx.no_tx' => 'Geen transactie open — :tx opent er een',
    'tx.opened' => 'Transactie gestart — opdrachten rijden mee tot :tx commit of :tx rollback',
    'tx.committed' => 'Transactie bevestigd',
    'tx.rolled_back' => 'Transactie teruggedraaid',
    'tx.arm_rollback' => 'ROLLBACK verwijdert alle wijzigingen sinds BEGIN — druk nogmaals op de uitvoertoets om te bevestigen',
    'tx.failed' => 'Transactiecommando mislukt: {message}',
    'history.none' => 'Niets te lezen — de statusgeschiedenis registreert alleen MySQL-sessies (optioneel met --history)',
    'history.empty' => 'Geen monsters in de laatste {minutes} minuten — de trend heeft er minimaal twee nodig',
    'history.unreadable' => 'Geschiedenisspeicher onleesbaar: {message}',
    'history.status' => 'Geschiedenistrend — {samples} rijen over de laatste {minutes} minuten',
    'query.timed_out' => 'Asynchrone query verlopen na {seconds}s',
    'reports.err_meta_missing' => 'Metadatabestand van rapporten niet gevonden: {path}',
    'reports.err_meta_read' => 'Metadatabestand van rapporten kon niet worden gelezen: {path}',
    'reports.err_not_in_catalog' => 'Rapport niet gevonden in catalogus: {name}',
    'reports.err_view_unavailable' => 'Weergave niet beschikbaar op deze server: {name}',
    'reports.err_loading' => 'Fout bij laden van rapport: {error}',
    'variables.err_meta_missing' => 'Metadatabestand van variabelen niet gevonden: {path}',
    'variables.err_meta_read' => 'Metadatabestand van variabelen kon niet worden gelezen: {path}',
    'resilience.statement_timeout_default' => 'Time-out bij uitvoeren van instructie',
    'resilience.statement_timeout_after' => 'Time-out bij uitvoeren van instructie na {seconds} s',
    'variables.err_prepare' => 'Kan de instructie op deze verbinding niet voorbereiden',
    'variables.err_not_editable' => 'Variabele niet bewerkbaar',
    'variables.err_persist_mariadb' => 'Persisteren vereist MariaDB 11.1+ — deze server implementeert SET PERSIST niet.',
    'variables.err_persist_mysql' => 'Persisteren vereist MySQL 8.0.16+ — deze server implementeert SET PERSIST niet.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: lege gebruikersnaam — weiger MySQL te benaderen als de geraden identiteit "root"; geef expliciet een gebruiker op',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: lege gebruikersnaam — weiger PostgreSQL te benaderen als de geraden identiteit "postgres"; geef expliciet een gebruiker op',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: onbekende sslmode "{mode}"; verwacht een van {allowed} — een typefout stilzwijgend negeren zou terugvallen op "prefer", dat platte tekst toestaat',
    'store.err_read' => 'Kan het verbindingenbestand niet lezen: {path} (verwijder het of herstel de rechten)',
    'store.err_json' => 'Het verbindingenbestand is geen geldige JSON ({error}): {path}',
    'store.err_shape' => 'Het verbindingenbestand moet een JSON-object met een "connections"-map zijn: {path}',
    'store.err_connections_type' => 'De waarde "connections" moet een JSON-object naam -> instellingen zijn: {path}',
    'store.err_unknown' => 'Onbekende verbinding \'{name}\' — opgeslagen verbindingen: {saved}',
    'store.none' => '(geen)',
    'store.err_pass_env_unset' => 'Verbinding \'{name}\' leest haar wachtwoord uit ${var} — exporteer het voor het verbinden',
    'store.err_mkdir' => 'Kan de verbindingenmap niet aanmaken: {dir}',
    'store.err_write' => 'Kan het verbindingenbestand niet schrijven: {path}',
    'store.err_replace' => 'Kan het verbindingenbestand niet vervangen: {path}',
    'store.err_entry_type' => 'Verbinding \'{name}\' moet verwijzen naar een JSON-object met instellingen',
    'store.err_plaintext_pass' => 'Verbinding \'{name}\' bewaart een \'pass\' in platte tekst — connections.json bevat nooit geheimen: vervang door "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'Verbinding \'{name}\' heeft een onbekende instelling \'{field}\' — toegestaan: {allowed}',
    'store.err_setting_type' => 'Instelling \'{field}\' van verbinding \'{name}\' moet een tekenreeks of getal zijn',
    'store.err_setting_empty' => 'Instelling \'{field}\' van verbinding \'{name}\' mag niet leeg zijn',
    'store.err_needs_driver' => 'Verbinding \'{name}\' heeft een \'driver\' nodig',
    'store.err_driver' => 'Verbinding \'{name}\' gebruikt een niet-ondersteund stuurprogramma \'{driver}\' — kies uit: {allowed}',
    'store.err_port' => 'Instelling \'port\' van verbinding \'{name}\' moet een getal zijn',
    'store.err_pass_env' => 'Instelling \'pass-env\' van verbinding \'{name}\' moet een omgevingsvariabelenaam zijn',
    'store.err_name' => 'Ongeldige verbindingsnaam \'{name}\' — gebruik letters, cijfers, punt, streepje of underscore (1-64 tekens)',
    'store.err_secure' => 'Kan {path} niet beveiligen (modus {mode})',
    'db.err_dsn_semicolon' => 'Databasehost en -naam mogen geen \';\' bevatten — het is het optiescheidingsteken van de PDO-DSN',
    'db.err_unknown_pg_ssl' => 'Onbekende PostgreSQL ssl-mode: {mode}',
    'db.err_unknown_mysql_ssl' => 'Onbekende MySQL ssl-mode: {mode}',
    'db.err_pg_driver' => 'Kan met de PostgreSQL-connector niet verbinden met een niet-PostgreSQL-stuurprogramma',
    'db.err_mysql_driver' => 'De MySQL-connector accepteert alleen het mysql-stuurprogramma (kreeg \'{driver}\')',
    'db.err_reconnect' => 'Opnieuw verbinden na verbindingsfout mislukt: {error}',
    'db.err_quote_no_connection' => 'Kan niet quoten zonder verbinding',
    'dml.err_key_required' => 'een rijsleutel is vereist: instructie die meer dan één rij kan raken wordt geweigerd',
    'dml.err_key_null' => 'sleutelkolom {column} is NULL: de rij kan niet worden geadresseerd',
    'dml.err_empty_table' => 'tabelnaam mag niet leeg zijn',
    'dml.err_empty_column' => 'kolomnaam mag niet leeg zijn',
    'dml.err_empty_key_column' => 'naam van de sleutelkolom mag niet leeg zijn',
    'status.already_saved' => 'al opgeslagen ({count})',
    'status.not_in_favorites' => 'niet in favorieten ({count})',
    'status.gtid_mariadb' => 'GTID_MODE is niet beschikbaar: MariaDB heeft geen gtid_mode-variabele.',
    'status.gtid_version' => 'GTID_MODE vereist MySQL 5.7.6 of nieuwer (server is {version}).',
    'caption.blocks_hit' => 'Blok-hits',
    'caption.blocks_read' => 'Gelezen blokken',
    'caption.buffer_pool_read_reqs' => 'Buffer-pool-leesverzoeken',
    'caption.buffer_pool_usage' => 'Buffer-poolgebruik',
    'caption.buffer_pool_write_reqs' => 'Buffer-pool-schrijfverzoeken',
    'caption.bytes_in' => 'Binnenkomende bytes',
    'caption.bytes_out' => 'Uitgaande bytes',
    'caption.cache_hit_rate' => 'Cache-hitratio',
    'caption.commits' => 'Commits',
    'caption.connections' => 'Verbindingen',
    'caption.deadlocks' => 'Deadlocks',
    'caption.disk_reads_not_from_pool' => 'Schijflezingen (niet uit pool)',
    'caption.disk_write_requests' => 'Schijfschrijfverzoeken',
    'caption.doublewrite_writes' => 'Doublewrite-schrijfacties',
    'caption.innodb_disk_reads' => 'InnoDB-schijflezingen',
    'caption.innodb_disk_writes' => 'InnoDB-schijfschrijfacties',
    'caption.insert_buffer' => 'Invoegbuffer',
    'caption.pages_created' => 'Aangemaakte pagina’s',
    'caption.pages_flushed' => 'Weggeschreven pagina’s',
    'caption.pages_read' => 'Gelezen pagina’s',
    'caption.read_ahead' => 'Read-ahead',
    'caption.redo_log_bytes_written' => 'Geschreven redo-logbytes',
    'caption.redo_log_writes' => 'Redo-logschrijfacties',
    'caption.rollbacks' => 'Rollbacks',
    'caption.row_lock_time' => 'Rijvergrendelingstijd',
    'caption.row_lock_waits' => 'Wachten op rijvergrendeling',
    'caption.shared_buffers' => 'Gedeelde buffers',
    'caption.sql_statements' => 'SQL-instructies',
    'caption.table_open_cache' => 'Table-open-cache',
    'caption.temp_bytes' => 'Tijdelijke bytes',
    'caption.temp_files' => 'Tijdelijke bestanden',
    'caption.transactions' => 'Transacties',
    'caption.tuples_fetched' => 'Opgehaalde tuples',
    'caption.tuples_returned' => 'Geretourneerde tuples',
    'caption.traffic' => 'Verkeer',
    'caption.key_efficiency' => 'Sleutelefficiëntie',
    'caption.selects_per_second' => 'Selects per seconde',
    'caption.innodb_buffer_usage' => 'InnoDB-buffergebruik',
    'caption.innodb_reads_per_second' => 'InnoDB-leesacties per seconde',
    'caption.innodb_writes_per_second' => 'InnoDB-schrijfacties per seconde',
    'widget.tip.bytes_in' => 'Inkomend verkeer: {Bytes_received} bytes totaal',
    'widget.tip.bytes_out' => 'Uitgaand verkeer: {Bytes_sent} bytes totaal',
    'widget.tip.client_connections' => 'Clientverbindingen: {Threads_connected}',
    'widget.tip.connections_level' => 'Verbindingsniveau t.o.v. max_connections',
    'widget.tip.table_open_cache' => 'Hitratio van de table-open-cache',
    'widget.tip.sql_statements' => 'SQL-instructiesnelheden: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'InnoDB-buffer-poolgebruik in procenten (bytegebaseerd)',
    'widget.tip.buffer_pool_read_reqs' => 'InnoDB-buffer-poolleesverzoeken per seconde',
    'widget.tip.buffer_pool_write_reqs' => 'InnoDB-buffer-poolschrijfverzoeken per seconde',
    'widget.tip.disk_reads_not_from_pool' => 'InnoDB-buffer-poolleesacties van schijf per seconde',
    'widget.tip.innodb_disk_writes' => 'InnoDB-databytes naar schijf geschreven per seconde',
    'widget.tip.disk_write_requests' => 'InnoDB-gegevensbestandschrijfverzoeken per seconde',
    'widget.tip.innodb_disk_reads' => 'InnoDB-databytes van schijf gelezen per seconde',
    'widget.tip.row_lock_waits' => 'InnoDB-rijvergrendelingswachten per seconde',
    'widget.tip.row_lock_time' => 'InnoDB-rijvergrendelingstijd per seconde (milliseconden)',
    'widget.tip.pages_flushed' => 'InnoDB-pagina’s weggeschreven per seconde',
    'widget.tip.pages_created' => 'InnoDB-pagina’s aangemaakt per seconde',
    'widget.tip.pages_read' => 'InnoDB-pagina’s gelezen per seconde',
    'widget.tip.insert_buffer' => 'Grootte van InnoDB-invoegbuffer (pagina’s)',
    'widget.tip.read_ahead' => 'InnoDB-buffer-pool-read-ahead per seconde',
    'widget.tip.redo_log_bytes_written' => 'InnoDB-redo-logbytes geschreven per seconde',
    'widget.tip.redo_log_writes' => 'InnoDB-redo-logschrijfacties per seconde',
    'widget.tip.doublewrite_writes' => 'InnoDB-doublewrite-schrijfacties per seconde',
    'widget.tip.alter_rate' => 'ALTER-opdrachtsnelheid (alle Com_alter_*)',
    'widget.tip.create_rate' => 'CREATE-opdrachtsnelheid (alle Com_create_*)',
    'widget.tip.drop_rate' => 'DROP-opdrachtsnelheid (alle Com_drop_*)',
    'widget.tip.select_rate' => 'SELECT-snelheid',
    'widget.tip.insert_rate' => 'INSERT-snelheid',
    'widget.tip.update_rate' => 'UPDATE-snelheid',
    'widget.tip.delete_rate' => 'DELETE-snelheid',
    'widget.tip.tuples_fetched_total' => 'Uit de DB opgehaalde rijen: {tup_fetched} totaal',
    'widget.tip.tuples_fetched_rate' => 'Rij-ophaalsnelheid',
    'widget.tip.tuples_returned_total' => 'Aan de client geretourneerde rijen: {tup_returned} totaal',
    'widget.tip.tuples_returned_rate' => 'Rij-retourneersnelheid',
    'widget.tip.blocks_read_total' => 'Gelezen schijfblokken: {blks_read} totaal',
    'widget.tip.blocks_hit_total' => 'Buffercache-hits: {blks_hit} totaal',
    'widget.tip.active_backends' => 'Actieve backends: {numbackends}',
    'widget.tip.transaction_rates' => 'Transactiesnelheden: commits / rollbacks',
    'widget.tip.commit_rate' => 'Transactiecommitsnelheid',
    'widget.tip.rollback_rate' => 'Transactierollbacksnelheid',
    'widget.tip.tuple_insert_rate' => 'Tuple-invoegsnelheid',
    'widget.tip.tuple_update_rate' => 'Tuple-updatesnelheid',
    'widget.tip.tuple_delete_rate' => 'Tuple-verwijdersnelheid',
    'widget.tip.deadlock_rate' => 'Deadlocksnelheid',
    'widget.tip.shared_buffers' => 'PostgreSQL-configuratie shared_buffers (buffer-poolgrootte)',
    'widget.tip.cache_hit_rate' => 'Buffercache-hitratio: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Aanmaaksnelheid van tijdelijke bestanden',
    'widget.tip.temp_bytes' => 'Naar tijdelijke bestanden geschreven bytes per seconde',
    'dash.data_written' => 'data geschreven {kb} kB/s',
    'dash.writes_ops' => 'schrijfacties {n} ops/s',
    'dash.log_written' => 'log geschreven {kb} kB/s',
    'dash.read_reqs' => 'leesverzoeken {n} pagina’s/s',
    'dash.write_reqs' => 'schrijfverzoeken {n} pagina’s/s',
    'dash.disk_reads' => 'schijflezingen {n} /s',
    'metrics.busy_proxy' => 'bezettingsproxy {pct}%',
    'metrics.cpu_load' => 'cpu-belasting {load}',
    'metrics.no_samples' => 'nog geen metingen',
    'metrics.connections_count' => '{count} verbindingen',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Servermetrieken',
    'explain.query_block' => 'QUERYBLOK #{id} (kosten: {cost})',
    'variables.footer_tree' => '[j/k] boom',
    'variables.footer_rows' => '[j/k] rijen',
    'variables.footer_categories' => '[c] categorieën',
    'variables.footer_edit' => '[Enter/e] bewerken (dynamisch)',
    'variables.footer_rw_filter' => '[w] rw-filter',
    'variables.footer_search' => '[s] zoeken',
    'variables.footer_back' => '[q] terug',
    'srv.title' => 'Serverstatus',
    'srv.features' => 'Functies',
    'srv.stored_programs' => 'Opgeslagen programma’s',
    'srv.directories' => 'Mappen',
    'srv.data_directory' => 'Gegevensmap',
    'srv.temp_directory' => 'Tijdelijke map',
    'srv.log_directory' => 'Logmap',
    'srv.pid_file' => 'PID-bestand',
    'srv.tls_active' => 'TLS actief',
    'srv.tls_cipher' => 'TLS-cipher',
    'srv.tls_version' => 'TLS-versie',
    'srv.ssl_support' => 'SSL-ondersteuning',
    'srv.ssl_ca' => 'SSL-CA',
    'srv.ssl_cert' => 'SSL-certificaat',
    'srv.ssl_key' => 'SSL-sleutel',
    'srv.tls_secure' => 'TLS / beveiligde verbinding',
    'srv.replication' => 'Replicatie',
    'srv.repl_denied' => 'Onvoldoende rechten (REPLICATION CLIENT)',
    'srv.repl_error' => 'Fout bij toegang tot replicatiestatus',
    'srv.not_configured' => 'Niet geconfigureerd',
    'srv.channel' => 'Kanaal',
    'srv.source_host' => 'Bronhost',
    'srv.source_port' => 'Bronpoort',
    'srv.replica_io' => 'Replica-IO actief',
    'srv.replica_sql' => 'Replica-SQL actief',
    'srv.seconds_behind' => 'Seconden achter bron',
    'srv.relay_log_file' => 'Relay-logbestand',
    'srv.relay_pos' => 'Relay-pos.',
    'srv.repl_channel_title' => 'Replicatie › {channel}',
    'srv.default' => 'Standaard',
    'srv.firewall' => 'Firewall',
    'srv.mysql_firewall' => 'MySQL-firewall',
    'srv.caught_up' => '0 s (bijgewerkt)',
    'srv.host' => 'Host',
    'srv.socket' => 'Socket',
    'srv.port' => 'Poort',
    'srv.version' => 'Versie',
    'srv.uptime' => 'Uptime',
    'srv.running_since' => 'Actief sinds',
    'srv.unknown' => 'Onbekend',
    'srv.server_information' => 'Serverinformatie',
    'perf.tab_easy_setup' => 'Eenvoudige installatie',
    'perf.tab_instruments' => 'Instrumenten',
    'perf.tab_consumers' => 'Consumers',
    'perf.tab_actors' => 'Actoren',
    'perf.tab_objects' => 'Objecten',
    'perf.tab_threads' => 'Threads',
    'perf.tab_options' => 'Opties',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[ALLEEN LEZEN]',
    'perf.nothing_to_commit' => 'Niets om door te voeren',
    'perf.commit_planning_failed' => 'Plannen van doorvoeren mislukt: {error}',
    'perf.err_prepare' => 'Voorbereiden van instructie mislukt: {sql}',
    'perf.committed_one' => '{count} instructie doorgevoerd',
    'perf.committed_other' => '{count} instructies doorgevoerd',
    'perf.applied_one' => '{label} toegepast — {count} instructie',
    'perf.applied_other' => '{label} toegepast — {count} instructies',
    'perf.commit_failed' => '{label} mislukte bij instructie {position} van {total}: {error} — eerdere instructies zijn toegepast, de serverstatus is gedeeltelijk.',
    'perf.err_access_denied' => 'Toegang geweigerd: vereiste rechten ontbreken',
    'perf.err_not_enabled' => 'Performance Schema is niet ingeschakeld',
    'perf.err_cannot_connect' => 'Kan geen verbinding maken met de database',
    'perf.state_fully' => 'Volledig ingeschakeld',
    'perf.state_default' => 'Standaardinstelling',
    'perf.state_custom' => 'Aangepaste instelling',
    'perf.state_disabled' => 'Uitgeschakeld',
    'perf.state_unknown' => 'Onbekend',
    'perf.current_state' => 'Huidige status: {state}',
    'perf.readonly_note' => '(Alleen-lezenmodus - geen rechten om te wijzigen)',
    'perf.keys_hint' => 'Toets "[" Volledige Performance Schema inschakelen   Toets "]" Uitschakelen   Toets "." Standaardwaarden herstellen',
    'perf.default_instruments' => 'Standaardinstrumenten: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Standaardconsumers: events_statements_history, events_waits_history, enz.',
    'perf.none_instruments' => '(geen instrumenten beschikbaar)',
    'perf.none_consumers' => '(geen consumers beschikbaar)',
    'perf.none_actors' => '(geen actoren geconfigureerd)',
    'perf.none_objects' => '(geen objectregels geconfigureerd)',
    'perf.none_threads' => '(geen threads beschikbaar)',
    'perf.total_instruments_one' => 'Totaal: {count} instrument',
    'perf.total_instruments_other' => 'Totaal: {count} instrumenten',
    'perf.total_consumers_one' => 'Totaal: {count} consumer',
    'perf.total_consumers_other' => 'Totaal: {count} consumers',
    'perf.total_actors_one' => 'Totaal: {count} actor',
    'perf.total_actors_other' => 'Totaal: {count} actoren',
    'perf.total_objects_one' => 'Totaal: {count} objectregel',
    'perf.total_objects_other' => 'Totaal: {count} objectregels',
    'perf.total_threads_one' => 'Totaal: {count} thread',
    'perf.total_threads_other' => 'Totaal: {count} threads',
    'perf.col_thread_id' => 'Thread-ID',
    'perf.col_name' => 'Naam',
    'perf.col_type' => 'Type',
    'perf.col_user' => 'Gebruiker',
    'perf.timer_options' => 'Timeropties',
    'perf.none_timers' => '(geen timers beschikbaar - alleen lezen)',
    'perf.col_timer_name' => 'Timernaam',
    'perf.col_implementation' => 'Implementatie',
    'perf.col_scale_factor' => 'Schaalfactor',
    'perf.timer_readonly_note' => 'Timerconfiguratie is alleen-lezen (bepaald door de serverbuild)',
    'perf.footer_nav' => '[j/k] navigeren  [Space] schakelen',
    'perf.footer_actions' => '  [c] doorvoeren voorbereiden ([y] uitvoeren)  [r] terugdraaien',
    'perf.footer_back' => '  [q] terug',
    'perf.pending_one' => 'In behandeling: {count} wijziging',
    'perf.pending_other' => 'In behandeling: {count} wijzigingen',
    'cli.err_not_tty' => 'deze starter bestuurt een interactieve terminal en weigert ANSI naar een pipe te streamen (exit 2) — voer hem uit in een pty (bijv. script -qec \'candy-sql app.sqlite\' /dev/null) of render headless vanuit bibliotheekcode (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'geschiedenis vastleggen UITGESCHAKELD ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' vereist geverifieerde TLS — het ReactPHP-beheertransport kan dat niet waarmaken, dus live verversen van beheer is UITGESCHAKELD en queries/tabelladingen draaien synchroon over de TLS-verbinding.',
    'cli.no_connection_selected' => 'geen verbinding geselecteerd — geef een pad, een DSN of --connect=<name> op',
    'cli.usage_line' => 'GEBRUIK: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # voor volledige gebruiksinformatie',
    'cli.tip_saved_connections' => '       Tip: sla benoemde verbindingen op in {path} en start met --connect=<name>.',
    'help.summary' => 'candy-sql - Terminal-SQL-client voor SQLite, MySQL en PostgreSQL',
    'help.usage' => 'GEBRUIK
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGUMENTEN
  path-or-dsn
    Pad van het SQLite-databasebestand, bijv.: app.sqlite
    Of een DSN-tekenreeks, bijv.:  mysql://user:pass@localhost:3306/dbname

  Helemaal weggelaten: in een interactieve terminal met opgeslagen
  verbindingen biedt een kiezer deze aan; anders wordt een korte
  gebruiksregel afgedrukt en eindigt het proces met status 2 (onjuiste
  aanroep) — voer --help uit voor dit volledige scherm.',
    'help.options' => 'OPTIES
  --help, -h
    Dit helpscherm tonen.

  --version, -V
    Het versienummer tonen.

  --connect=<name>
    Verbinden met de genoemde invoer uit het bestand met opgeslagen verbindingen
    ($XDG_CONFIG_HOME/candy-sql/connections.json, standaard
    $HOME/.config/candy-sql/connections.json). Kan niet worden gecombineerd
    met expliciete verbindingsargumenten. Wachtwoorden worden nooit op
    schijf opgeslagen: een invoer noemt zijn geheim via "pass-env" en de
    variabele wordt bij het verbinden gelezen. Zie OPGESLAGEN VERBINDINGEN hieronder.',
    'help.option_dsn' => '  --dsn=<dsn>
    Volledige DSN-verbindingstekenreeks.
    Indeling: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Voorbeelden:
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
    Afzonderlijke verbindingsparameters (alle behalve --driver en --db zijn optioneel).
    --driver moet een van deze zijn: sqlite, mysql, pgsql
    --ssl-mode is standaard "prefer" voor MySQL. "require" en "verify_*" dwingen
    TLS af met controle van het servercertificaat; het vertrouwen in de CA moet
    aan de serverkant of via my.cnf worden ingesteld (zie README). In die
    gecontroleerde modi wordt het tot platte tekst in staat zijnde ReactPHP-
    transport geweigerd — query\'s lopen synchroon over de TLS-verbinding en
    het live verversen van de beheerpagina\'s is uitgeschakeld (een
    opstartmelding en de statusregel van de app geven dit aan).
    Gebruik liever CANDY_SQL_PASS dan --pass= zodat het wachtwoord niet in de
    opdrachtregel van het proces staat (zichtbaar in ps).',
    'help.examples' => 'VOORBEELDEN
  # SQLite (standaard — kaal pad of DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL via DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL via afzonderlijke opties
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'OPGESLAGEN VERBINDINGEN
  Som benoemde servers één keer op en verbind er voortaan direct mee:

    $HOME/.config/candy-sql/connections.json  (map 0700, bestand 0600)',
    'help.saved_fields' => '  Velden: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Elk ander veld wordt geweigerd, en een "pass"-veld in platte
  tekst wordt altijd geweigerd — laat pass-env naar een variabele wijzen.
  Een start zonder argumenten in een interactieve terminal toont deze
  invoeren als kiezer; --connect=<name> verbindt direct met één ervan.',
    'help.editing' => 'BEWERKEN
  Het Rows-paneel van een TABLE-voorbeeld is een bewerkbaar raster: h/l
  (of ←/→) verplaatsen de celcursor, en drie toetscombinaties wijzigen
  gegevens — elk toont de exacte instructie op de statusregel en schrijft
  niets totdat Enter bevestigt:

    e   de cel onder de cursor bewerken (Ctrl+N zet NULL)
    o   een rij invoegen (Enter loopt door de kolommen; een lege invoer
        laat de kolom op zijn DEFAULT, Ctrl+N slaat NULL op)
    D   de rij onder de cursor verwijderen

  Rijen worden alleen aangesproken via hun volledige PRIMARY KEY: een
  tabel zonder sleutel, een NULL-/binaire sleutel, een binaire cel en elk
  queryresultaat worden geweigerd met een benoemde statusregel. Waarden
  zijn gebonden parameters; elke schrijfactie draait in een transactie die
  wordt teruggedraaid tenzij precies één rij is geraakt (een UPDATE die de
  al aanwezige waarde schrijft, wordt als ongewijzigd gemeld). Esc breekt
  een geopende bewerkregel af.',
    'help.editing_readonly' => '  --read-only
    Schakelt de bewerktoetsen voor de sessie uit. Dit beschermt tegen een
    misstap; het filtert geen opdrachten in het Query-paneel — kies voor
    een harde garantie een verbinding als alleen-lezen databasegebruiker.',
    'help.history' => 'GESCHIEDENIS
  Standaard wordt elke beheer-pollmomentopname vastgelegd in
  $HOME/.candy-sql/history.db (map 0700 / bestand 0600) als offline
  auditspoor. De pagina\'s Dashboard en Reports in de app lezen dit
  bestand niet — ze bevragen de live server.',
    'help.history_flags' => '  --no-history
    De registratie volledig uitschakelen (er wordt niets naar schijf geschreven).

  --history=<path>
    Momentopnamen opslaan in <path> in plaats van op de standaardlocatie.',
    'help.keyboard' => 'SNELTOETSEN (TUI)
  Tab          De focus door de panelen laten rouleren (Tables → Rows → Query → Admin)
  ↑ / ↓        Door lijstrijen navigeren (of j/k) — in het Tables-paneel is een
               verplaatsing alleen lokaal; er wordt nooit een voorbeeld opgehaald
  /            (Tables-paneel) een live filterregel over tabelnamen openen; letters
               verlengen hem, ↑/↓ verplaatsen de cursor, Enter bevestigt en laadt,
               Backspace corrigeert, Esc verwerpt en wist
  Enter        De rijen van de geselecteerde tabel laden
  Space        De rijen van de geselecteerde tabel laden (alias voor Enter)
  PgDn / PgUp  (Tables-/Rows-paneel, op een tabelvoorbeeld) het voorbeeld in vensters
               van 100 rijen pagineren — de bladerlimiet werd een paginagrootte
  Ctrl+Enter   De huidige query uitvoeren
  Ctrl+R       De huidige query uitvoeren
  Ctrl+E       De huidige query uitvoeren
  EXPLAIN …    (Query-editor, voorafgaand sleutelwoord) een getypte EXPLAIN loopt via
               ExplainView: het Rows-paneel toont de kleurgecodeerde plan-boom, niet
               het platte providerraster — en elke ANALYZE-modifier wordt VERWIJDERD,
               de inspecteur voert nooit uit wat hij inspecteert
  e / o / D    (Rows-paneel, tabelvoorbeeld) de cel bewerken / een rij invoegen /
               de rij verwijderen — zie BEWERKEN; h/l verplaatsen de celcursor
  Ctrl+Space   Aanvullen bij de cursor (Query-editor): SQL-sleutelwoorden, tabelnamen
               na FROM/JOIN/INTO/UPDATE en kolommen in context (WHERE/SELECT/ORDER BY,
               gekwalificeerd als `alias.` / `table.`, de INSERT INTO t (...)-lijst)
               uit het geladen schema. Eén kandidaat wordt aangevuld, een gedeeld
               voorvoegsel schuift op, anders toont de statusregel de kandidaten
  Ctrl+F       De huidige query aan de favorietenlijst toevoegen (alleen sessiestatus
               — geen paneel toont hem en hij wordt niet bewaard)
  Ctrl+Shift+F De huidige query uit de favorietenlijst verwijderen
  1-8          Naar een beheerpaneel springen (alleen als het Admin-paneel focus heeft)
  q            Afsluiten vanuit de panelen Tables/Rows; vanuit Admin terug naar Tables
  x            (Tables/Rows, live resultaat) de huidige rijen naar een CSV-pad
               exporteren — overschrijfweigering blijft actief tot Enter bevestigt
  i            (Tables/Rows) een CSV-bestand importeren in een benoemde tabel
  Ctrl+S       Het live resultaat/voorbeeld vanuit elk paneel naar CSV exporteren
  Esc          Afsluiten vanuit de panelen Tables/Rows; sluit een open Admin-dialoog
  Backspace    Het teken vóór de cursor verwijderen (Query-editor)',
    'help.see_also' => 'ZIE OOK
  https://github.com/sugarcraft/candy-sql',
];
