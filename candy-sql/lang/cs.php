<?php

/**
 * Czech translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'soubor SQLite nenalezen: {path}',
    'complete.in_literal' => 'uvnitř řetězce nebo komentáře není co doplňovat',
    'complete.nothing' => 'není co doplnit — napište začátek klíčového slova',
    'complete.none_keyword' => 'žádné klíčové slovo nedoplňuje "{word}"',
    'complete.none_table' => 'žádná tabulka nedoplňuje "{word}"',
    'complete.none_column' => 'žádný sloupec nedoplňuje "{word}"',
    'complete.none_qualified' => 'žádný sloupec z {qualifier} nedoplňuje "{word}"',
    'complete.list' => '{count} doplňuje "{word}": {list}{more}',
    'complete.done' => 'doplněno {word} → {insert}',
    'complete.inserted' => 'vloženo {insert}',
    'edit.refuse_readonly' => 'relace jen pro čtení — úpravy jsou vypnuty',
    'edit.refuse_no_table' => 'nejdřív otevřete náhled tabulky — výsledky dotazu nelze upravovat přímo',
    'edit.refuse_no_rows' => 'pod kurzorem není žádný řádek',
    'edit.refuse_no_pk' => 'tabulka nemá primární klíč — řádky nelze bezpečně adresovat; napište příkaz v panelu dotazu',
    'edit.refuse_pk_hidden' => 'primární klíč řádku je NULL nebo binární zástupce — řádek nelze adresovat',
    'edit.refuse_binary' => 'binární nebo velké buňky zde nelze upravovat — použijte příkaz v panelu dotazu',
    'edit.refuse_schema' => 'nelze načíst schéma tabulky {table} — úprava odmítnuta',
    'edit.prompt_cell' => 'úprava {table}.{column} = {value}  (enter náhled · ctrl+n NULL · esc zruší)',
    'edit.prompt_insert' => 'nový řádek v {table} — {column} ({type}) [{n}/{total}] = {value}  (enter další · prázdné = DEFAULT · ctrl+n NULL · esc zruší)',
    'edit.prompt_confirm' => '{sql}  (enter potvrdí · esc zruší)',
    'edit.cancelled' => 'úprava zrušena',
    'edit.unchanged' => 'hodnota se nezměnila — nic nezapsáno',
    'edit.done_update' => 'aktualizován 1 řádek v {table}',
    'edit.done_insert' => 'vložen 1 řádek do {table}',
    'edit.done_delete' => 'smazán 1 řádek z {table}',
    'edit.none_changed' => 'žádný řádek se nezměnil — hodnota tam už byla nebo řádek zmizel; náhled obnoven',
    'edit.failed' => 'zápis selhal — vráceno zpět, nic se nezměnilo: {reason}',
    'edit.too_many' => 'odmítnuto: příkaz by zasáhl {count} řádků — vráceno zpět, nic se nezměnilo',
    'ui.title.tables' => ' tabulky ',
    'ui.title.rows' => ' řádky ',
    'ui.title.query' => ' dotaz ',
    'ui.title.admin' => ' správa ',
    'ui.title.explain' => ' plán ',
    'ui.help.query' => 'tab  přepnout panel  ·  ctrl+r / ctrl+e  spustit  ·  enter  nový řádek  ·  ↑/↓  historie  ·  ctrl+c  konec',
    'ui.help.rows' => 'tab  přepnout panel  ·  h/l  buňka  ·  e  upravit  ·  o  nový řádek  ·  D  smazat  ·  x  export  ·  q / esc  konec',
    'ui.help.browse' => 'tab  přepnout panel  ·  enter  načíst tabulku  ·  x  export  ·  i  import  ·  q / esc  konec',
    'ui.help.admin' => '1-{count}  vybrat stránku  ·  j/k  pohyb  ·  p  pozastavit  ·  r  reset  ·  tab  přepnout panel  ·  q  zpět  ·  esc  zrušit dialog',
    'ui.no_tables' => '(žádné tabulky)',
    'ui.no_match' => '(žádná shoda)',
    'ui.loading' => 'načítání…',
    'ui.empty' => '(prázdné)',
    'ui.admin_unavailable' => 'stránka správy není dostupná: {reason}',
    'ui.error' => 'chyba: {message}',
    'io.prompt_export' => 'do CSV → {path}  (enter zapíše · esc zruší)',
    'io.prompt_replace' => 'PŘEPSAT {path}?  (enter potvrdí · esc zruší)',
    'io.prompt_import_path' => 'import CSV ← {path}  (enter · esc zruší)',
    'io.prompt_import_table' => 'import {source} → tabulka {table}  (enter · esc zruší)',
    'bar.tables' => 'Tabulky: {count}',
    'bar.cycle' => 'Tab:přepnout',
    'bar.navigate' => '↑↓:pohyb',
    'bar.load' => 'Enter:načíst',
    'bar.run' => 'Ctrl+R:spustit',
    'bar.select' => '1-{count}:výběr',
    'bar.nav' => 'j/k:pohyb',
    'bar.quit' => 'q:konec',
    'bar.paused' => '[POZASTAVENO]',
    'bar.tx' => '[TX]',
    'picker.title' => 'uložená připojení',
    'picker.help' => '[↑↓/j/k] vybrat   [Enter] připojit   [q] zrušit',
    'result.empty' => '(prázdná sada výsledků)',
    'explain.no_plan' => '(žádný plán dotazu — dotaz nevrátil žádné řádky)',
    'explain.title' => ' PLÁN DOTAZU ',
    'status.live_refresh_off' => 'živé obnovování správy vypnuto — ssl-mode s ověřením TLS (pouze synchronní přenos)',
    'status.async_failed' => 'Asynchronní příkaz selhal: {message}',
    'status.file_cancelled' => 'řádek souboru zrušen',
    'status.path_required' => 'cesta je povinná — esc zruší',
    'status.no_such_file' => 'soubor neexistuje — pište dál nebo esc zruší',
    'status.table_required' => 'zadejte cílovou tabulku — esc zruší',
    'status.export_failed' => 'export selhal: {message}',
    'status.exported' => 'exportováno {count} řádků do {path}',
    'status.import_failed' => 'import selhal: {message}',
    'status.imported' => '{path} importován do {table}',
    'status.history' => 'historie {pos}/{count}',
    'status.conn_lost' => 'spojení ztraceno; zkuste znovu',
    'status.destructive' => '⚠ destruktivní příkaz — pro potvrzení znovu ctrl+r (beze změny)',
    'status.running' => 'spouštím dotaz…',
    'status.explain_failed' => 'EXPLAIN selhal: {message}',
    'status.explain_plan' => 'plán dotazu — spuštění dotazu nebo náhled tabulky ho nahradí',
    'status.end_of_table' => 'konec tabulky — žádná další stránka',
    'status.first_page' => 'již na první stránce',
    'status.nothing_to_save' => 'není co uložit',
    'status.saved' => 'uloženo ({count})',
    'status.removed' => 'odebráno ({count})',
    'status.row_one' => '1 řádek',
    'status.rows' => '{count} řádků',
    'status.page' => 'strana {page} (řádky {first}–{last})',
    'editor.placeholder' => '-- napište SQL, ctrl+r spustí --',
    'err.async_query_unsupported' => 'Tento ovladač nepodporuje asynchronní dotazy',
    'err.async_browse_unsupported' => 'Asynchronní procházení tento ovladač nepodporuje',
    'dash.title' => 'Přehled výkonu',
    'dash.fetching' => 'Načítání metrik serveru...',
    'dash.back_hint' => 'Stiskněte [q] pro návrat do zobrazení tabulek',
    'dash.recent_queries' => 'Poslední dotazy',
    'dash.no_queries' => '(zatím žádné dotazy)',
    'dash.header_pg' => 'Přehled výkonu{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Přehled výkonu{status} | MySQL {version} | Doba běhu: {uptime}',
    'dash.uptime_segment' => ' | Doba běhu: {uptime}',
    'pane.process_list' => 'Seznam procesů',
    'pane.variables' => 'Proměnné',
    'pane.status' => 'Stav',
    'pane.query_stats' => 'Statistiky dotazů',
    'pane.dashboard' => 'Přehled',
    'pane.table_stats' => 'Statistiky tabulek',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Ladění',
    'page.no_data' => 'Zatím nejsou k dispozici žádná data.',
    'page.error' => 'Chyba: {message}',
    'page.loading' => 'Načítání…',
    'page.fetching' => 'Načítání dat ze serveru.',
    'debug.title' => 'Ladicí log',
    'debug.entries' => ' ({count} záznamů)',
    'debug.errors_only' => '[pouze chyby]',
    'debug.empty' => '(žádné záznamy)',
    'debug.more' => '↑ dalších {count} záznamů',
    'debug.help' => '[j/k] pohyb  [f] jen chyby  [c] vymazat  [q] zpět',
    'reports.cannot_access' => 'K přehledům výkonu nelze přistoupit: {message}',
    'reports.title' => 'Přehledy výkonu',
    'reports.categories' => 'Kategorie',
    'reports.no_data' => '(žádná data)',
    'reports.loading' => 'Načítání přehledů…',
    'reports.loading_one' => 'Načítání {caption}…',
    'reports.no_data_for' => 'Pro {caption} nejsou k dispozici žádná data',
    'reports.help' => '[j/k] řádky  [h/l] kategorie  [[]/[]] přehled  [,/.] sloupec  [c] jednotka  [r] obnovit  [x] export  [q] zpět',
    'reports.unavailable' => 'Přehledy výkonu nejsou dostupné',
    'reports.ensure' => 'Zajistěte MySQL 5.6.6+ s performance_schema=enabled a nainstalovaným schématem sys.',
    'conn.kill_cancelled' => 'Ukončení zrušeno',
    'conn.none_selected' => 'Není vybráno žádné spojení',
    'conn.refuse_background' => 'Odmítnuto ukončení vlákna na pozadí',
    'conn.verb_kill_query' => 'Ukončit dotaz',
    'conn.verb_kill' => 'Ukončit',
    'conn.sent' => '{verb} odesláno spojení {id}',
    'conn.failed' => '{verb} pro spojení {id} selhalo',
    'conn.confirm_verb_query' => 'Zrušit běžící dotaz na',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} spojení {id} ({user}@{host})?  [y] potvrdit   [jiná klávesa] zrušit',
    'conn.help_empty' => '[j/k] pohyb  [f] filtr  [r] obnovit',
    'conn.help_full' => '[j/k] pohyb  [K] kill  [X] kill dotazu  [f] filtr  [r] obnovit',
    'conn.vars_fail' => 'Nelze načíst proměnné serveru',
    'vars.load_fail' => 'Nelze načíst proměnné: {message}',
    'vars.unknown_error' => 'Neznámá chyba',
    'vars.not_dynamic' => 'Chyba 1238: \'{name}\' není dynamická — stiskněte [p] pro PERSIST_ONLY',
    'vars.new_value' => 'nová hodnota > ',
    'vars.edit_head' => 'Úprava ({mode}) {name} (aktuální: {current})',
    'vars.scope_explain' => 'GLOBAL = jen za běhu (po restartu se ztratí) · PERSIST = za běhu + konfigurační soubor · PERSIST_ONLY = jen konfigurační soubor (platí od příštího restartu)',
    'vars.edit_help' => 'Zadejte novou hodnotu, [p] přepnout režim, [Enter] potvrdit, [Esc] zrušit',
    'reports.back' => '[q] zpět',
    'err.dsn_empty' => 'DSN nesmí být prázdné',
    'err.dsn_no_separator' => 'Neplatné DSN: chybí oddělovač "://"',
    'err.driver_unsupported' => 'Nepodporovaný ovladač: {driver}',
    'err.dsn_sqlite_invalid' => 'Neplatné DSN pro SQLite',
    'err.dsn_parse_failed' => 'Neplatné DSN: parse_url() selhalo',
    'err.dsn_no_host' => 'Neplatné DSN: chybí hostitel',
    'err.driver_choose' => 'Nepodporovaný ovladač: {driver} — vyberte jeden z {list}',
    'err.args_missing' => 'Chybí povinné argumenty: zadejte --driver i --db (nebo použijte --dsn)',
    'err.opt_needs_value' => 'Volba --{name} vyžaduje hodnotu',
    'err.opt_unknown' => 'Neznámá volba: --{name}',
    'err.csv_not_found' => 'Soubor CSV nenalezen: {path}',
    'err.csv_unreadable' => 'Soubor CSV \'{path}\' nelze přečíst: zkontrolujte, že existuje a je čitelný',
    'err.csv_no_headers' => 'Nelze přečíst záhlaví CSV z: {path}',
    'err.csv_no_columns' => 'Soubor CSV nemá žádné sloupce: {path}',
    'err.dest_unwritable' => 'Cíl \'{path}\' nelze otevřít k zápisu: zkontrolujte, že adresář existuje a je zapisovatelný',
    'err.export_buffer' => 'Exportní vyrovnávací paměť nelze vytvořit',
    'err.csv_partial' => 'Export CSV tabulky {table} zapsal {written} z {expected} řádků — tabulka se během exportu změnila nebo ovladač ignoroval stránkování; částečný soubor nebude vydáván za úplný',
    'err.sql_partial' => 'Výpis SQL tabulky {table} zapsal {written} z {expected} řádků — tabulka se během výpisu změnila nebo ovladač ignoroval stránkování; částečný soubor nebude vydáván za úplný',
    'err.table_unreadable' => 'Tabulka nenalezena nebo nečitelná: {table} ({message})',
    'err.dml_no_prepare' => 'toto spojení neumí provádět připravené příkazy',
    'err.dml_refused' => 'server příkaz odmítl',
    'alert.connection_critical' => 'Využití připojení je kriticky vysoké',
    'alert.connection_elevated' => 'Využití připojení je zvýšené',
    'alert.aborted_elevated' => 'Míra přerušených připojení je zvýšená',
    'alert.thread_elevated' => 'Počet běžících vláken je zvýšený',
    'alert.slow_query_time' => 'Doba pomalého dotazu nastavena na {seconds} s',
    'alert.connections_at' => 'Připojení na {pct}% maxima ({connected}/{max})',
    'alert.connection_error_one' => 'Zjištěna {count} chyba připojení',
    'alert.connection_error_other' => 'Zjištěno chyb připojení: {count}',
    'alert.threshold_ratio_connection_warning' => 'Práh varování připojení musí být mezi 0.0 a 1.0',
    'alert.threshold_ratio_connection_critical' => 'Kritický práh připojení musí být mezi 0.0 a 1.0',
    'alert.threshold_ratio_thread_running' => 'Práh běžících vláken musí být mezi 0.0 a 1.0',
    'alert.threshold_aborted_rate' => 'Práh míry přerušení musí být >= 0.0',
    'alert.threshold_slow_query' => 'Práh pomalého dotazu musí být >= 0.0',
    'alert.threshold_connection_errors' => 'Práh chyb připojení musí být >= 0',
    'history.err_mkdir' => 'Nelze vytvořit adresář historie: {dir}',
    'history.err_chmod' => 'Nelze zabezpečit soubor úložiště historie: {path} (režim {mode})',
    'history.err_unusable' => 'Úložiště historie je nepoužitelné: {error} — smažte soubor historie nebo opravte jeho oprávnění.',
    'query.cancelled' => 'Asynchronní dotaz zrušen',
    'tx.unknown' => 'Neznámý příkaz — :tx zahajuje, potvrzuje nebo ruší transakci',
    'tx.async_refusal' => ':tx pokrývá synchronní relaci; asynchronní transport zatím nemá koordinovanou transakční větev — proveďte explicitní BEGIN/COMMIT nebo použijte synchronní cestu',
    'tx.already_open' => 'Transakce je již otevřena — :tx commit nebo :tx rollback',
    'tx.no_tx' => 'Není otevřena žádná transakce — :tx jednu zahájí',
    'tx.opened' => 'Transakce zahájena — příkazy v ní běží do :tx commit nebo :tx rollback',
    'tx.committed' => 'Transakce potvrzena',
    'tx.rolled_back' => 'Transakce vrácena',
    'tx.arm_rollback' => 'ROLLBACK zahodí všechny změny od BEGIN — stiskněte znovu klávesu spuštění pro potvrzení',
    'tx.failed' => 'Příkaz transakce selhal: {message}',
    'query.timed_out' => 'Časový limit asynchronního dotazu vypršel po {seconds} s',
    'reports.err_meta_missing' => 'Soubor metadat reportů nenalezen: {path}',
    'reports.err_meta_read' => 'Nepodařilo se přečíst soubor metadat reportů: {path}',
    'reports.err_not_in_catalog' => 'Report nebyl v katalogu nalezen: {name}',
    'reports.err_view_unavailable' => 'Pohled není na tomto serveru dostupný: {name}',
    'reports.err_loading' => 'Chyba při načítání reportu: {error}',
    'variables.err_meta_missing' => 'Soubor metadat proměnných nenalezen: {path}',
    'variables.err_meta_read' => 'Nepodařilo se přečíst soubor metadat proměnných: {path}',
    'resilience.statement_timeout_default' => 'Časový limit provádění příkazu vypršel',
    'resilience.statement_timeout_after' => 'Časový limit provádění příkazu vypršel po {seconds} s',
    'variables.err_prepare' => 'Na tomto připojení nelze příkaz připravit',
    'variables.err_not_editable' => 'Proměnnou nelze upravit',
    'variables.err_persist_mariadb' => 'Trvalé uložení vyžaduje MariaDB 11.1+ — tento server SET PERSIST neimplementuje.',
    'variables.err_persist_mysql' => 'Trvalé uložení vyžaduje MySQL 8.0.16+ — tento server SET PERSIST neimplementuje.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: prázdné uživatelské jméno — odmítám se připojit k MySQL jako odhadovaný uživatel „root“; zadejte uživatele výslovně',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: prázdné uživatelské jméno — odmítám se připojit k PostgreSQL jako odhadovaný uživatel „postgres“; zadejte uživatele výslovně',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: neznámý sslmode „{mode}“; očekáváno jedno z {allowed} — tiché zahození překlepu by snížilo zabezpečení na „prefer“ umožňující prostý text',
    'store.err_read' => 'Nelze přečíst soubor připojení: {path} (smažte jej nebo opravte oprávnění)',
    'store.err_json' => 'Soubor připojení není platný JSON ({error}): {path}',
    'store.err_shape' => 'Soubor připojení musí být objekt JSON s mapou „connections“: {path}',
    'store.err_connections_type' => 'Hodnota „connections“ musí být objekt JSON název -> nastavení: {path}',
    'store.err_unknown' => 'Neznámé připojení \'{name}\' — uložená připojení: {saved}',
    'store.none' => '(žádná)',
    'store.err_pass_env_unset' => 'Připojení \'{name}\' čte heslo z ${var} — před připojením jej exportujte',
    'store.err_mkdir' => 'Nelze vytvořit adresář připojení: {dir}',
    'store.err_write' => 'Nelze zapsat soubor připojení: {path}',
    'store.err_replace' => 'Nelze nahradit soubor připojení: {path}',
    'store.err_entry_type' => 'Připojení \'{name}\' musí odpovídat objektu JSON s nastavením',
    'store.err_plaintext_pass' => 'Připojení \'{name}\' ukládá heslo \'pass\' v prostém textu — connections.json nikdy neobsahuje tajné údaje: nahraďte jej "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'Připojení \'{name}\' má neznámé nastavení \'{field}\' — povoleno: {allowed}',
    'store.err_setting_type' => 'Nastavení \'{field}\' připojení \'{name}\' musí být řetězec nebo číslo',
    'store.err_setting_empty' => 'Nastavení \'{field}\' připojení \'{name}\' nesmí být prázdné',
    'store.err_needs_driver' => 'Připojení \'{name}\' potřebuje \'driver\'',
    'store.err_driver' => 'Připojení \'{name}\' používá nepodporovaný ovladač \'{driver}\' — vyberte jeden z: {allowed}',
    'store.err_port' => 'Nastavení \'port\' připojení \'{name}\' musí být číslo',
    'store.err_pass_env' => 'Nastavení \'pass-env\' připojení \'{name}\' musí být název proměnné prostředí',
    'store.err_name' => 'Neplatný název připojení \'{name}\' — použijte písmena, číslice, tečku, pomlčku nebo podtržítko (1-64 znaků)',
    'store.err_secure' => 'Nelze zabezpečit {path} (režim {mode})',
    'db.err_dsn_semicolon' => 'Hostitel a název databáze nesmí obsahovat \';\' — je to oddělovač voleb PDO DSN',
    'db.err_unknown_pg_ssl' => 'Neznámý PostgreSQL ssl-mode: {mode}',
    'db.err_unknown_mysql_ssl' => 'Neznámý MySQL ssl-mode: {mode}',
    'db.err_pg_driver' => 'Nelze se připojit k ovladači jiného než PostgreSQL pomocí konektoru PostgreSQL',
    'db.err_mysql_driver' => 'Konektor MySQL přijímá pouze ovladač mysql (obdrženo \'{driver}\')',
    'db.err_reconnect' => 'Opětovné připojení po chybě připojení selhalo: {error}',
    'db.err_quote_no_connection' => 'Bez připojení nelze hodnotu uzavřít do uvozovek',
    'dml.err_key_required' => 'je vyžadován klíč řádku: příkaz, který by mohl zasáhnout více než jeden řádek, je odmítnut',
    'dml.err_key_null' => 'sloupec klíče {column} je NULL: řádek nelze adresovat',
    'dml.err_empty_table' => 'název tabulky nesmí být prázdný',
    'dml.err_empty_column' => 'název sloupce nesmí být prázdný',
    'dml.err_empty_key_column' => 'název sloupce klíče nesmí být prázdný',
    'status.already_saved' => 'již uloženo ({count})',
    'status.not_in_favorites' => 'není v oblíbených ({count})',
    'status.gtid_mariadb' => 'GTID_MODE není k dispozici: MariaDB nemá proměnnou gtid_mode.',
    'status.gtid_version' => 'GTID_MODE vyžaduje MySQL 5.7.6 nebo novější (server je {version}).',
    'caption.blocks_hit' => 'Zásahy bloků',
    'caption.blocks_read' => 'Přečtené bloky',
    'caption.buffer_pool_read_reqs' => 'Požadavky čtení buffer poolu',
    'caption.buffer_pool_usage' => 'Využití buffer poolu',
    'caption.buffer_pool_write_reqs' => 'Požadavky zápisu buffer poolu',
    'caption.bytes_in' => 'Přijaté bajty',
    'caption.bytes_out' => 'Odeslané bajty',
    'caption.cache_hit_rate' => 'Úspěšnost cache',
    'caption.commits' => 'Commity',
    'caption.connections' => 'Připojení',
    'caption.deadlocks' => 'Deadlocky',
    'caption.disk_reads_not_from_pool' => 'Čtení z disku (mimo pool)',
    'caption.disk_write_requests' => 'Požadavky zápisu na disk',
    'caption.doublewrite_writes' => 'Zápisy doublewrite',
    'caption.innodb_disk_reads' => 'Čtení disku InnoDB',
    'caption.innodb_disk_writes' => 'Zápisy disku InnoDB',
    'caption.insert_buffer' => 'Vkládací buffer',
    'caption.pages_created' => 'Vytvořené stránky',
    'caption.pages_flushed' => 'Vyprázdněné stránky',
    'caption.pages_read' => 'Přečtené stránky',
    'caption.read_ahead' => 'Předčítání',
    'caption.redo_log_bytes_written' => 'Zapsané bajty redo logu',
    'caption.redo_log_writes' => 'Zápisy redo logu',
    'caption.rollbacks' => 'Rollbacky',
    'caption.row_lock_time' => 'Doba zámku řádků',
    'caption.row_lock_waits' => 'Čekání na zámky řádků',
    'caption.shared_buffers' => 'Sdílené buffery',
    'caption.sql_statements' => 'Příkazy SQL',
    'caption.table_open_cache' => 'Cache otevřených tabulek',
    'caption.temp_bytes' => 'Dočasné bajty',
    'caption.temp_files' => 'Dočasné soubory',
    'caption.transactions' => 'Transakce',
    'caption.tuples_fetched' => 'Načtené n-tice',
    'caption.tuples_returned' => 'Vrácené n-tice',
    'caption.traffic' => 'Provoz',
    'caption.key_efficiency' => 'Efektivita klíčů',
    'caption.selects_per_second' => 'Selecty za sekundu',
    'caption.innodb_buffer_usage' => 'Využití bufferu InnoDB',
    'caption.innodb_reads_per_second' => 'Čtení InnoDB za sekundu',
    'caption.innodb_writes_per_second' => 'Zápisy InnoDB za sekundu',
    'widget.tip.bytes_in' => 'Příchozí provoz: celkem {Bytes_received} bajtů',
    'widget.tip.bytes_out' => 'Odchozí provoz: celkem {Bytes_sent} bajtů',
    'widget.tip.client_connections' => 'Připojení klientů: {Threads_connected}',
    'widget.tip.connections_level' => 'Úroveň připojení vs. max_connections',
    'widget.tip.table_open_cache' => 'Poměr úspěšnosti cache otevřených tabulek',
    'widget.tip.sql_statements' => 'Četnosti příkazů SQL: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'Procento využití buffer poolu InnoDB (podle bajtů)',
    'widget.tip.buffer_pool_read_reqs' => 'Požadavky čtení buffer poolu InnoDB za sekundu',
    'widget.tip.buffer_pool_write_reqs' => 'Požadavky zápisu buffer poolu InnoDB za sekundu',
    'widget.tip.disk_reads_not_from_pool' => 'Čtení buffer poolu InnoDB z disku za sekundu',
    'widget.tip.innodb_disk_writes' => 'Bajty dat InnoDB zapsané na disk za sekundu',
    'widget.tip.disk_write_requests' => 'Požadavky zápisu datového souboru InnoDB za sekundu',
    'widget.tip.innodb_disk_reads' => 'Bajty dat InnoDB přečtené z disku za sekundu',
    'widget.tip.row_lock_waits' => 'Čekání na zámky řádků InnoDB za sekundu',
    'widget.tip.row_lock_time' => 'Doba zámků řádků InnoDB za sekundu (milisekundy)',
    'widget.tip.pages_flushed' => 'Stránky InnoDB vyprázdněné za sekundu',
    'widget.tip.pages_created' => 'Stránky InnoDB vytvořené za sekundu',
    'widget.tip.pages_read' => 'Stránky InnoDB přečtené za sekundu',
    'widget.tip.insert_buffer' => 'Velikost vkládacího bufferu InnoDB (stránky)',
    'widget.tip.read_ahead' => 'Předčítání buffer poolu InnoDB za sekundu',
    'widget.tip.redo_log_bytes_written' => 'Bajty redo logu InnoDB zapsané za sekundu',
    'widget.tip.redo_log_writes' => 'Zápisy redo logu InnoDB za sekundu',
    'widget.tip.doublewrite_writes' => 'Zápisy doublewrite InnoDB za sekundu',
    'widget.tip.alter_rate' => 'Četnost příkazů ALTER (všechna Com_alter_*)',
    'widget.tip.create_rate' => 'Četnost příkazů CREATE (všechna Com_create_*)',
    'widget.tip.drop_rate' => 'Četnost příkazů DROP (všechna Com_drop_*)',
    'widget.tip.select_rate' => 'Četnost SELECT',
    'widget.tip.insert_rate' => 'Četnost INSERT',
    'widget.tip.update_rate' => 'Četnost UPDATE',
    'widget.tip.delete_rate' => 'Četnost DELETE',
    'widget.tip.tuples_fetched_total' => 'Řádky načtené z DB: celkem {tup_fetched}',
    'widget.tip.tuples_fetched_rate' => 'Četnost načítání řádků',
    'widget.tip.tuples_returned_total' => 'Řádky vrácené klientovi: celkem {tup_returned}',
    'widget.tip.tuples_returned_rate' => 'Četnost vracení řádků',
    'widget.tip.blocks_read_total' => 'Přečtené diskové bloky: celkem {blks_read}',
    'widget.tip.blocks_hit_total' => 'Zásahy do bufferové cache: celkem {blks_hit}',
    'widget.tip.active_backends' => 'Aktivní backendy: {numbackends}',
    'widget.tip.transaction_rates' => 'Četnosti transakcí: commity / rollbacky',
    'widget.tip.commit_rate' => 'Četnost commitů transakcí',
    'widget.tip.rollback_rate' => 'Četnost rollbacků transakcí',
    'widget.tip.tuple_insert_rate' => 'Četnost vkládání n-tic',
    'widget.tip.tuple_update_rate' => 'Četnost aktualizace n-tic',
    'widget.tip.tuple_delete_rate' => 'Četnost mazání n-tic',
    'widget.tip.deadlock_rate' => 'Četnost deadlocků',
    'widget.tip.shared_buffers' => 'Konfigurace PostgreSQL shared_buffers (velikost buffer poolu)',
    'widget.tip.cache_hit_rate' => 'Poměr zásahů bufferové cache: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Četnost vytváření dočasných souborů',
    'widget.tip.temp_bytes' => 'Bajty zapsané do dočasných souborů za sekundu',
    'dash.data_written' => 'zapsáno dat {kb} kB/s',
    'dash.writes_ops' => 'zápisy {n} op/s',
    'dash.log_written' => 'zapsáno logu {kb} kB/s',
    'dash.read_reqs' => 'požadavky čtení {n} stran/s',
    'dash.write_reqs' => 'požadavky zápisu {n} stran/s',
    'dash.disk_reads' => 'čtení z disku {n} /s',
    'metrics.busy_proxy' => 'proxy vytížení {pct}%',
    'metrics.cpu_load' => 'zátěž CPU {load}',
    'metrics.no_samples' => 'zatím žádné vzorky',
    'metrics.connections_count' => '{count} připojení',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Metriky serveru',
    'explain.query_block' => 'BLOK DOTAZU #{id} (cena: {cost})',
    'variables.footer_tree' => '[j/k] strom',
    'variables.footer_rows' => '[j/k] řádky',
    'variables.footer_categories' => '[c] kategorie',
    'variables.footer_edit' => '[Enter/e] upravit (dynamické)',
    'variables.footer_rw_filter' => '[w] filtr rw',
    'variables.footer_search' => '[s] hledat',
    'variables.footer_back' => '[q] zpět',
    'srv.title' => 'Stav serveru',
    'srv.features' => 'Funkce',
    'srv.stored_programs' => 'Uložené programy',
    'srv.directories' => 'Adresáře',
    'srv.data_directory' => 'Datový adresář',
    'srv.temp_directory' => 'Dočasný adresář',
    'srv.log_directory' => 'Adresář logů',
    'srv.pid_file' => 'Soubor PID',
    'srv.tls_active' => 'TLS aktivní',
    'srv.tls_cipher' => 'Šifra TLS',
    'srv.tls_version' => 'Verze TLS',
    'srv.ssl_support' => 'Podpora SSL',
    'srv.ssl_ca' => 'SSL CA',
    'srv.ssl_cert' => 'Certifikát SSL',
    'srv.ssl_key' => 'Klíč SSL',
    'srv.tls_secure' => 'TLS / zabezpečené připojení',
    'srv.replication' => 'Replikace',
    'srv.repl_denied' => 'Nedostatečná oprávnění (REPLICATION CLIENT)',
    'srv.repl_error' => 'Chyba při přístupu ke stavu replikace',
    'srv.not_configured' => 'Nenakonfigurováno',
    'srv.channel' => 'Kanál',
    'srv.source_host' => 'Hostitel zdroje',
    'srv.source_port' => 'Port zdroje',
    'srv.replica_io' => 'IO vlákno repliky běží',
    'srv.replica_sql' => 'SQL vlákno repliky běží',
    'srv.seconds_behind' => 'Sekundy za zdrojem',
    'srv.relay_log_file' => 'Soubor relay logu',
    'srv.relay_pos' => 'Pozice relay',
    'srv.repl_channel_title' => 'Replikace › {channel}',
    'srv.default' => 'Výchozí',
    'srv.firewall' => 'Firewall',
    'srv.mysql_firewall' => 'Firewall MySQL',
    'srv.caught_up' => '0 s (synchronizováno)',
    'srv.host' => 'Hostitel',
    'srv.socket' => 'Socket',
    'srv.port' => 'Port',
    'srv.version' => 'Verze',
    'srv.uptime' => 'Doba běhu',
    'srv.running_since' => 'Běží od',
    'srv.unknown' => 'Neznámé',
    'srv.server_information' => 'Informace o serveru',
    'perf.tab_easy_setup' => 'Snadné nastavení',
    'perf.tab_instruments' => 'Instrumenty',
    'perf.tab_consumers' => 'Konzumenti',
    'perf.tab_actors' => 'Aktéři',
    'perf.tab_objects' => 'Objekty',
    'perf.tab_threads' => 'Vlákna',
    'perf.tab_options' => 'Možnosti',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[POUZE PRO ČTENÍ]',
    'perf.nothing_to_commit' => 'Není co potvrdit',
    'perf.commit_planning_failed' => 'Plánování potvrzení selhalo: {error}',
    'perf.err_prepare' => 'Příprava příkazu selhala: {sql}',
    'perf.committed_one' => 'Potvrzen {count} příkaz',
    'perf.committed_other' => 'Potvrzeno {count} příkazů',
    'perf.applied_one' => '{label} použito — {count} příkaz',
    'perf.applied_other' => '{label} použito — {count} příkazů',
    'perf.commit_failed' => '{label} selhalo u příkazu {position} z {total}: {error} — dřívější příkazy byly použity, stav serveru je částečný.',
    'perf.err_access_denied' => 'Přístup odepřen: chybí požadovaná oprávnění',
    'perf.err_not_enabled' => 'Performance Schema není povoleno',
    'perf.err_cannot_connect' => 'Nelze se připojit k databázi',
    'perf.state_fully' => 'Plně povoleno',
    'perf.state_default' => 'Výchozí nastavení',
    'perf.state_custom' => 'Vlastní nastavení',
    'perf.state_disabled' => 'Zakázáno',
    'perf.state_unknown' => 'Neznámé',
    'perf.current_state' => 'Aktuální stav: {state}',
    'perf.readonly_note' => '(Režim pouze pro čtení – bez oprávnění k úpravám)',
    'perf.keys_hint' => 'Klávesa "[" Povolit celé Performance Schema   Klávesa "]" Zakázat   Klávesa "." Obnovit výchozí',
    'perf.default_instruments' => 'Výchozí instrumenty: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Výchozí konzumenti: events_statements_history, events_waits_history atd.',
    'perf.none_instruments' => '(žádné instrumenty k dispozici)',
    'perf.none_consumers' => '(žádní konzumenti k dispozici)',
    'perf.none_actors' => '(nejsou nakonfigurováni žádní aktéři)',
    'perf.none_objects' => '(nejsou nakonfigurována pravidla objektů)',
    'perf.none_threads' => '(žádná vlákna k dispozici)',
    'perf.total_instruments_one' => 'Celkem: {count} instrument',
    'perf.total_instruments_other' => 'Celkem: {count} instrumentů',
    'perf.total_consumers_one' => 'Celkem: {count} konzument',
    'perf.total_consumers_other' => 'Celkem: {count} konzumentů',
    'perf.total_actors_one' => 'Celkem: {count} aktér',
    'perf.total_actors_other' => 'Celkem: {count} aktérů',
    'perf.total_objects_one' => 'Celkem: {count} pravidlo objektu',
    'perf.total_objects_other' => 'Celkem: {count} pravidel objektů',
    'perf.total_threads_one' => 'Celkem: {count} vlákno',
    'perf.total_threads_other' => 'Celkem: {count} vláken',
    'perf.col_thread_id' => 'ID vlákna',
    'perf.col_name' => 'Název',
    'perf.col_type' => 'Typ',
    'perf.col_user' => 'Uživatel',
    'perf.timer_options' => 'Možnosti časovače',
    'perf.none_timers' => '(žádné časovače k dispozici – pouze pro čtení)',
    'perf.col_timer_name' => 'Název časovače',
    'perf.col_implementation' => 'Implementace',
    'perf.col_scale_factor' => 'Měřítko',
    'perf.timer_readonly_note' => 'Konfigurace časovače je pouze pro čtení (určeno sestavením serveru)',
    'perf.footer_nav' => '[j/k] navigace  [Space] přepnout',
    'perf.footer_actions' => '  [c] připravit potvrzení ([y] provést)  [r] vrátit',
    'perf.footer_back' => '  [q] zpět',
    'perf.pending_one' => 'Čeká: {count} změna',
    'perf.pending_other' => 'Čeká: {count} změn',
    'cli.err_not_tty' => 'tento spouštěč ovládá interaktivní terminál a odmítá posílat ANSI do roury (návratový kód 2) — spusťte jej v pty (např. script -qec \'candy-sql app.sqlite\' /dev/null) nebo renderujte bez rozhraní z kódu knihovny (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'záznam historie ZAKÁZÁN ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' vyžaduje ověřené TLS — administrační přenos ReactPHP to nedokáže splnit, proto je živé obnovování administrace ZAKÁZÁNO a dotazy i načítání tabulek běží synchronně přes připojení TLS.',
    'cli.no_connection_selected' => 'nevybráno žádné připojení — zadejte cestu, DSN nebo --connect=<name>',
    'cli.usage_line' => 'POUŽITÍ: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # úplné informace o použití',
    'cli.tip_saved_connections' => '       Tip: uložte pojmenovaná připojení do {path} a spusťte s --connect=<name>.',
    'help.summary' => 'candy-sql - Terminálový SQL klient pro SQLite, MySQL a PostgreSQL',
    'help.usage' => 'POUŽITÍ
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGUMENTY
  path-or-dsn
    Cesta k souboru databáze SQLite, např.: app.sqlite
    Nebo řetězec DSN, např.:  mysql://user:pass@localhost:3306/dbname

  Při úplném vynechání: v interaktivním terminálu s uloženými připojeními
  je nabídne výběr; jinak se vypíše krátký řádek použití a proces skončí
  se stavem 2 (chybné volání) — pro tuto úplnou obrazovku spusťte --help.',
    'help.options' => 'PŘEPÍNAČE
  --help, -h
    Zobrazí tuto obrazovku nápovědy.

  --version, -V
    Zobrazí číslo verze.

  --connect=<name>
    Připojí se k pojmenované položce ze souboru uložených připojení
    ($XDG_CONFIG_HOME/candy-sql/connections.json, ve výchozím stavu
    $HOME/.config/candy-sql/connections.json). Nelze kombinovat s explicitními
    argumenty připojení. Hesla se nikdy neukládají na disk: položka uvádí své
    tajemství přes "pass-env" a proměnná se čte při připojení. Viz ULOŽENÁ
    PŘIPOJENÍ níže.',
    'help.option_dsn' => '  --dsn=<dsn>
    Úplný připojovací řetězec DSN.
    Formát: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Příklady:
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
    Jednotlivé parametry připojení (všechny kromě --driver a --db jsou volitelné).
    --driver musí být jedno z: sqlite, mysql, pgsql
    --ssl-mode je pro MySQL výchozí "prefer". "require" a "verify_*" vynutí
    TLS s ověřením certifikátu serveru; důvěra v CA se musí nastavit na straně
    serveru nebo přes my.cnf (viz README). V těchto ověřených režimech se
    odmítne transport ReactPHP, který připouští prostý text — dotazy běží
    synchronně přes spojení TLS a živá aktualizace administrace je vypnutá
    (upozorní na to oznámení při startu i stavový řádek aplikace).
    Dejte přednost CANDY_SQL_PASS před --pass=, aby heslo nebylo v příkazovém
    řádku procesu (viditelném v ps).',
    'help.examples' => 'PŘÍKLADY
  # SQLite (výchozí — holá cesta nebo DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL přes DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL přes jednotlivé přepínače
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'ULOŽENÁ PŘIPOJENÍ
  Pojmenované servery vypište jednou a připojujte se k nim navždy:

    $HOME/.config/candy-sql/connections.json  (adresář 0700, soubor 0600)',
    'help.saved_fields' => '  Pole: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Jakékoli jiné pole se odmítne a pole "pass" s prostým textem se
  odmítne vždy — místo toho nasměrujte pass-env na proměnnou. Spuštění bez
  argumentů v interaktivním terminálu tyto položky nabídne jako výběr;
  --connect=<name> se připojí přímo k jedné z nich.',
    'help.editing' => 'ÚPRAVY
  Panel Rows náhledu TABLE je upravitelná mřížka: h/l (nebo ←/→) posouvají
  kurzor buňky a tři akordy mění data — každý zobrazí přesný příkaz na
  stavovém řádku a nic nezapíše, dokud to Enter nepotvrdí:

    e   upravit buňku pod kurzorem (Ctrl+N nastaví NULL)
    o   vložit řádek (Enter prochází sloupce; prázdný vstup nechá sloupci
        jeho DEFAULT, Ctrl+N uloží NULL)
    D   smazat řádek pod kurzorem

  Řádky se adresují pouze celým PRIMARY KEY: tabulka bez něj, klíč NULL/
  binární, binární buňka a jakýkoli výsledek dotazu se odmítnou pojmenovaným
  stavovým řádkem. Hodnoty jsou vázané parametry; každý zápis běží v
  transakci, která se vrátí zpět, pokud nebyl zasažen právě jeden řádek
  (UPDATE zapisující již přítomnou hodnotu se hlásí jako beze změny).
  Esc zruší otevřený editační řádek.',
    'help.editing_readonly' => '  --read-only
    Vypne pro relaci editační akordy. Chrání před překlepem; nefiltruje
    příkazy v panelu Query — pro pevnou záruku se připojte jako databázový
    uživatel jen pro čtení.',
    'help.history' => 'HISTORIE
  Ve výchozím stavu se každý snímek administračního dotazování zaznamenává do
  $HOME/.candy-sql/history.db (adresář 0700 / soubor 0600) jako offline
  auditní stopa. Stránky Dashboard a Reports v aplikaci tento soubor
  nečtou — dotazují se živého serveru.',
    'help.history_flags' => '  --no-history
    Zcela vypne záznam (na disk se nezapisuje nic).

  --history=<path>
    Ukládá snímky do <path> místo výchozího umístění.',
    'help.keyboard' => 'KLÁVESOVÉ ZKRATKY (TUI)
  Tab          Cyklicky přepíná fokus mezi panely (Tables → Rows → Query → Admin)
  ↑ / ↓        Pohyb po řádcích seznamu (nebo j/k) — v panelu Tables je pohyb pouze
               lokální; nikdy nenačítá náhled
  /            (Panel Tables) otevře řádek živého filtru nad názvy tabulek; písmena
               jej prodlužují, ↑/↓ posouvají kurzor, Enter potvrdí a načte, Backspace
               upravuje, Esc zahodí a vymaže
  Enter        Načte řádky vybrané tabulky
  Space        Načte řádky vybrané tabulky (alias pro Enter)
  PgDn / PgUp  (Panel Tables/Rows, v náhledu tabulky) stránkuje náhled po oknech o
               100 řádcích — limit procházení se stal velikostí stránky
  Ctrl+Enter   Provede aktuální dotaz
  Ctrl+R       Provede aktuální dotaz
  Ctrl+E       Provede aktuální dotaz
  EXPLAIN …    (Editor Query, úvodní klíčové slovo) napsaný EXPLAIN prochází přes
               ExplainView: panel Rows ukáže barevný strom plánu, nikoli plochou mřížku
               poskytovatele — a jakýkoli modifikátor ANALYZE se ODSTRANÍ, inspektor
               nikdy neprovede to, co zkoumá
  e / o / D    (Panel Rows, náhled tabulky) upravit buňku / vložit řádek / smazat
               řádek — viz ÚPRAVY; h/l posouvají kurzor buňky
  Ctrl+Space   Doplní u kurzoru (editor Query): klíčová slova SQL, názvy tabulek po
               FROM/JOIN/INTO/UPDATE a sloupce v kontextu (WHERE/SELECT/ORDER BY,
               kvalifikované `alias.` / `table.`, seznam INSERT INTO t (...)) z načteného
               schématu. Jeden kandidát se doplní, společný prefix postoupí, jinak
               stavový řádek vypíše kandidáty
  Ctrl+F       Přidá aktuální dotaz do seznamu oblíbených (pouze stav relace — žádný
               panel jej neprochází a neukládá se)
  Ctrl+Shift+F Odebere aktuální dotaz ze seznamu oblíbených
  1-8          Přeskočí na administrační panel (pouze s fokusem na panelu Admin)
  q            Ukončí z panelů Tables/Rows; z Admin se vrátí do Tables
  x            (Tables/Rows, živý výsledek) exportuje aktuální řádky do cesty CSV —
               ochrana proti přepsání platí, dokud Enter nepotvrdí
  i            (Tables/Rows) importuje soubor CSV do pojmenované tabulky
  Ctrl+S       Exportuje živý výsledek/náhled do CSV z libovolného panelu
  Esc          Ukončí z panelů Tables/Rows; zruší otevřený dialog Admin
  Backspace    Smaže znak před kurzorem (editor Query)',
    'help.see_also' => 'VIZ TAKÉ
  https://github.com/sugarcraft/candy-sql',
];
