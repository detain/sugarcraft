<?php

/**
 * Polish translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'nie znaleziono pliku SQLite: {path}',
    'complete.in_literal' => 'w ciągu znaków lub komentarzu nie ma czego uzupełniać',
    'complete.nothing' => 'nie ma czego uzupełnić — wpisz początek słowa kluczowego',
    'complete.none_keyword' => 'żadne słowo kluczowe nie uzupełnia "{word}"',
    'complete.none_table' => 'żadna tabela nie uzupełnia "{word}"',
    'complete.none_column' => 'żadna kolumna nie uzupełnia "{word}"',
    'complete.none_qualified' => 'żadna kolumna z {qualifier} nie uzupełnia "{word}"',
    'complete.list' => '{count} uzupełnia "{word}": {list}{more}',
    'complete.done' => 'uzupełniono {word} → {insert}',
    'complete.inserted' => 'wstawiono {insert}',
    'edit.refuse_readonly' => 'sesja tylko do odczytu — edycja jest wyłączona',
    'edit.refuse_no_table' => 'najpierw otwórz podgląd tabeli — wyników zapytania nie można edytować w miejscu',
    'edit.refuse_no_rows' => 'brak wiersza pod kursorem',
    'edit.refuse_no_pk' => 'ta tabela nie ma klucza głównego — wierszy nie można bezpiecznie adresować; wpisz polecenie w panelu zapytania',
    'edit.refuse_pk_hidden' => 'klucz główny tego wiersza to NULL lub binarny zastępnik — nie można go zaadresować',
    'edit.refuse_binary' => 'komórek binarnych lub dużych nie można tu edytować — użyj polecenia w panelu zapytania',
    'edit.refuse_schema' => 'nie można odczytać schematu {table} — edycja odrzucona',
    'edit.prompt_cell' => 'edycja {table}.{column} = {value}  (enter podgląd · ctrl+n NULL · esc anuluje)',
    'edit.prompt_insert' => 'nowy wiersz w {table} — {column} ({type}) [{n}/{total}] = {value}  (enter dalej · puste = DEFAULT · ctrl+n NULL · esc anuluje)',
    'edit.prompt_confirm' => '{sql}  (enter zatwierdza · esc anuluje)',
    'edit.cancelled' => 'edycja anulowana',
    'edit.unchanged' => 'wartość bez zmian — nic nie zapisano',
    'edit.done_update' => 'zaktualizowano 1 wiersz w {table}',
    'edit.done_insert' => 'wstawiono 1 wiersz do {table}',
    'edit.done_delete' => 'usunięto 1 wiersz z {table}',
    'edit.none_changed' => 'żaden wiersz się nie zmienił — wartość już tam była lub wiersza nie ma; podgląd odświeżono',
    'edit.failed' => 'zapis nie powiódł się — wycofano, nic się nie zmieniło: {reason}',
    'edit.too_many' => 'odrzucono: polecenie dotknęłoby {count} wierszy — wycofano, nic się nie zmieniło',
    'ui.title.tables' => ' tabele ',
    'ui.title.rows' => ' wiersze ',
    'ui.title.query' => ' zapytanie ',
    'ui.title.admin' => ' admin ',
    'ui.title.explain' => ' plan ',
    'ui.help.query' => 'tab  zmień panel  ·  ctrl+r / ctrl+e  uruchom  ·  enter  nowy wiersz  ·  ↑/↓  historia  ·  ctrl+c  wyjdź',
    'ui.help.rows' => 'tab  zmień panel  ·  h/l  komórka  ·  e  edytuj  ·  o  nowy wiersz  ·  D  usuń  ·  x  eksport  ·  q / esc  wyjdź',
    'ui.help.browse' => 'tab  zmień panel  ·  enter  wczytaj tabelę  ·  x  eksport  ·  i  import  ·  q / esc  wyjdź',
    'ui.help.admin' => '1-{count}  wybierz stronę  ·  j/k  nawigacja  ·  p  pauza  ·  r  reset  ·  tab  zmień panel  ·  q  wróć  ·  esc  anuluj okno',
    'ui.no_tables' => '(brak tabel)',
    'ui.no_match' => '(brak dopasowań)',
    'ui.loading' => 'ładowanie…',
    'ui.empty' => '(puste)',
    'ui.admin_unavailable' => 'strona administracyjna niedostępna: {reason}',
    'ui.error' => 'błąd: {message}',
    'io.prompt_export' => 'do CSV → {path}  (enter zapisuje · esc anuluje)',
    'io.prompt_replace' => 'ZASTĄPIĆ {path}?  (enter potwierdza · esc anuluje)',
    'io.prompt_import_path' => 'import CSV ← {path}  (enter · esc anuluje)',
    'io.prompt_import_table' => 'import {source} → tabela {table}  (enter · esc anuluje)',
    'bar.tables' => 'Tabele: {count}',
    'bar.cycle' => 'Tab:zmień',
    'bar.navigate' => '↑↓:nawigacja',
    'bar.load' => 'Enter:wczytaj',
    'bar.run' => 'Ctrl+R:uruchom',
    'bar.select' => '1-{count}:wybór',
    'bar.nav' => 'j/k:nawig',
    'bar.quit' => 'q:wyjdź',
    'bar.paused' => '[WSTRZYMANO]',
    'bar.tx' => '[TX]',
    'picker.title' => 'zapisane połączenia',
    'picker.help' => '[↑↓/j/k] wybierz   [Enter] połącz   [q] anuluj',
    'result.empty' => '(pusty zestaw wyników)',
    'explain.no_plan' => '(brak planu zapytania — zapytanie nie zwróciło wierszy)',
    'explain.title' => ' PLAN ZAPYTANIA ',
    'status.live_refresh_off' => 'odświeżanie na żywo administracji wyłączone — ssl-mode z weryfikacją TLS (tylko transport synchroniczny)',
    'status.async_failed' => 'Polecenie asynchroniczne nie powiodło się: {message}',
    'status.file_cancelled' => 'anulowano wiersz pliku',
    'status.path_required' => 'ścieżka jest wymagana — esc anuluje',
    'status.no_such_file' => 'nie ma takiego pliku — pisz dalej lub esc anuluje',
    'status.table_required' => 'podaj tabelę docelową — esc anuluje',
    'status.export_failed' => 'eksport nie powiódł się: {message}',
    'status.exported' => 'wyeksportowano {count} wierszy do {path}',
    'status.import_failed' => 'import nie powiódł się: {message}',
    'status.imported' => 'zaimportowano {path} do {table}',
    'status.history' => 'historia {pos}/{count}',
    'status.conn_lost' => 'utracono połączenie; spróbuj ponownie',
    'status.destructive' => '⚠ instrukcja destrukcyjna — ctrl+r ponownie (bez zmian), aby potwierdzić',
    'status.running' => 'uruchamianie zapytania…',
    'status.explain_failed' => 'EXPLAIN nie powiódł się: {message}',
    'status.explain_plan' => 'plan wykonania — każde zapytanie lub podgląd tabeli go zastępuje',
    'status.end_of_table' => 'koniec tabeli — brak kolejnej strony',
    'status.first_page' => 'już na pierwszej stronie',
    'status.nothing_to_save' => 'nic do zapisania',
    'status.saved' => 'zapisano ({count})',
    'status.removed' => 'usunięto ({count})',
    'status.row_one' => '1 wiersz',
    'status.rows' => '{count} wierszy',
    'status.page' => 'strona {page} (wiersze {first}–{last})',
    'editor.placeholder' => '-- wpisz SQL, ctrl+r uruchamia --',
    'err.async_query_unsupported' => 'Ten sterownik nie obsługuje zapytań asynchronicznych',
    'err.async_browse_unsupported' => 'Przeglądanie asynchroniczne nie jest obsługiwane przez ten sterownik',
    'dash.title' => 'Panel wydajności',
    'dash.fetching' => 'Pobieranie metryk serwera...',
    'dash.back_hint' => 'Naciśnij [q], aby wrócić do widoku tabel',
    'dash.recent_queries' => 'Ostatnie zapytania',
    'dash.no_queries' => '(brak zapytań)',
    'dash.header_pg' => 'Panel wydajności{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Panel wydajności{status} | MySQL {version} | Czas pracy: {uptime}',
    'dash.uptime_segment' => ' | Czas pracy: {uptime}',
    'pane.process_list' => 'Lista procesów',
    'pane.variables' => 'Zmienne',
    'pane.status' => 'Stan',
    'pane.query_stats' => 'Statystyki zapytań',
    'pane.dashboard' => 'Panel',
    'pane.table_stats' => 'Statystyki tabel',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Debugowanie',
    'page.no_data' => 'Brak jeszcze danych.',
    'page.error' => 'Błąd: {message}',
    'page.loading' => 'Ładowanie…',
    'page.fetching' => 'Pobieranie danych z serwera.',
    'debug.title' => 'Dziennik debugowania',
    'debug.entries' => ' ({count} wpisów)',
    'debug.errors_only' => '[tylko błędy]',
    'debug.empty' => '(brak wpisów dziennika)',
    'debug.more' => '↑ jeszcze {count} wpisów',
    'debug.help' => '[j/k] nawig  [f] tylko błędy  [c] wyczyść  [q] wróć',
    'reports.cannot_access' => 'Brak dostępu do raportów wydajności: {message}',
    'reports.title' => 'Raporty wydajności',
    'reports.categories' => 'Kategorie',
    'reports.no_data' => '(brak danych)',
    'reports.loading' => 'Ładowanie raportów…',
    'reports.loading_one' => 'Ładowanie {caption}…',
    'reports.no_data_for' => 'Brak danych dla {caption}',
    'reports.help' => '[j/k] wiersze  [h/l] kategoria  [[]/[]] raport  [,/.] kolumna  [c] jednostka  [r] odśwież  [x] eksport  [q] wróć',
    'reports.unavailable' => 'Raporty wydajności niedostępne',
    'reports.ensure' => 'Upewnij się, że używasz MySQL 5.6.6+ z performance_schema=enabled i zainstalowanym schematem sys.',
    'conn.kill_cancelled' => 'Anulowano Kill',
    'conn.none_selected' => 'Nie wybrano połączenia',
    'conn.refuse_background' => 'Odmowa zakończenia wątku w tle',
    'conn.verb_kill_query' => 'Zakończ zapytanie',
    'conn.verb_kill' => 'Zakończ',
    'conn.sent' => '{verb} wysłano do połączenia {id}',
    'conn.failed' => '{verb} nie powiódł się dla połączenia {id}',
    'conn.confirm_verb_query' => 'Anuluj uruchomione zapytanie w',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} połączenie {id} ({user}@{host})?  [y] potwierdź   [inny klawisz] anuluj',
    'conn.help_empty' => '[j/k] nawig  [f] filtr  [r] odśwież',
    'conn.help_full' => '[j/k] nawig  [K] kill  [X] kill zapytania  [f] filtr  [r] odśwież',
    'conn.vars_fail' => 'Nie można pobrać zmiennych serwera',
    'vars.load_fail' => 'Nie można wczytać zmiennych: {message}',
    'vars.unknown_error' => 'Nieznany błąd',
    'vars.not_dynamic' => 'Błąd 1238: \'{name}\' nie jest dynamiczna — naciśnij [p], aby użyć PERSIST_ONLY',
    'vars.new_value' => 'nowa wartość > ',
    'vars.edit_head' => 'Edycja ({mode}) {name} (obecnie: {current})',
    'vars.scope_explain' => 'GLOBAL = tylko czas działania (tracone po restarcie) · PERSIST = czas działania + plik konfiguracji · PERSIST_ONLY = tylko plik konfiguracji (obowiązuje od następnego restartu)',
    'vars.edit_help' => 'Wpisz nową wartość, [p] przełącz tryb, [Enter] potwierdź, [Esc] anuluj',
    'reports.back' => '[q] wróć',
    'err.dsn_empty' => 'DSN nie może być pusty',
    'err.dsn_no_separator' => 'Nieprawidłowy DSN: brak separatora "://"',
    'err.driver_unsupported' => 'Nieobsługiwany sterownik: {driver}',
    'err.dsn_sqlite_invalid' => 'Nieprawidłowy DSN SQLite',
    'err.dsn_parse_failed' => 'Nieprawidłowy DSN: parse_url() nie powiodło się',
    'err.dsn_no_host' => 'Nieprawidłowy DSN: brak hosta',
    'err.driver_choose' => 'Nieobsługiwany sterownik: {driver} — wybierz jeden z {list}',
    'err.args_missing' => 'Brak wymaganych argumentów: podaj --driver i --db (lub użyj --dsn)',
    'err.opt_needs_value' => 'Opcja --{name} wymaga wartości',
    'err.opt_unknown' => 'Nieznana opcja: --{name}',
    'err.csv_not_found' => 'Nie znaleziono pliku CSV: {path}',
    'err.csv_unreadable' => 'Nie można odczytać pliku CSV \'{path}\': sprawdź, czy istnieje i jest czytelny',
    'err.csv_no_headers' => 'Nie można odczytać nagłówków CSV z: {path}',
    'err.csv_no_columns' => 'Plik CSV nie ma kolumn: {path}',
    'err.dest_unwritable' => 'Nie można otworzyć miejsca docelowego \'{path}\' do zapisu: sprawdź, czy katalog istnieje i jest zapisywalny',
    'err.export_buffer' => 'Nie można utworzyć bufora eksportu',
    'err.csv_partial' => 'Eksport CSV tabeli {table} zapisał {written} z {expected} wierszy — tabela zmieniła się w trakcie eksportu lub sterownik zignorował okno stronicowania; odmowa przedstawienia niepełnego pliku jako kompletnego',
    'err.sql_partial' => 'Zrzut SQL tabeli {table} zapisał {written} z {expected} wierszy — tabela zmieniła się w trakcie zrzutu lub sterownik zignorował okno stronicowania; odmowa przedstawienia niepełnego pliku jako kompletnego',
    'err.table_unreadable' => 'Nie znaleziono tabeli lub jest nieczytelna: {table} ({message})',
    'err.dml_no_prepare' => 'to połączenie nie może wykonywać przygotowanych instrukcji',
    'err.dml_refused' => 'serwer odrzucił instrukcję',
    'alert.connection_critical' => 'Wykorzystanie połączeń krytycznie wysokie',
    'alert.connection_elevated' => 'Wykorzystanie połączeń podwyższone',
    'alert.aborted_elevated' => 'Odsetek przerwanych połączeń podwyższony',
    'alert.thread_elevated' => 'Liczba uruchomionych wątków podwyższona',
    'alert.slow_query_time' => 'Czas wolnego zapytania ustawiony na {seconds}s',
    'alert.connections_at' => 'Połączenia na poziomie {pct}% maksimum ({connected}/{max})',
    'alert.connection_error_one' => 'Wykryto {count} błąd połączenia',
    'alert.connection_error_other' => 'Wykryto błędów połączenia: {count}',
    'alert.threshold_ratio_connection_warning' => 'Próg ostrzeżenia połączeń musi mieścić się między 0.0 a 1.0',
    'alert.threshold_ratio_connection_critical' => 'Próg krytyczny połączeń musi mieścić się między 0.0 a 1.0',
    'alert.threshold_ratio_thread_running' => 'Próg uruchomionych wątków musi mieścić się między 0.0 a 1.0',
    'alert.threshold_aborted_rate' => 'Próg odsetka przerwań musi wynosić >= 0.0',
    'alert.threshold_slow_query' => 'Próg wolnych zapytań musi wynosić >= 0.0',
    'alert.threshold_connection_errors' => 'Próg błędów połączenia musi wynosić >= 0',
    'history.err_mkdir' => 'Nie można utworzyć katalogu historii: {dir}',
    'history.err_chmod' => 'Nie można zabezpieczyć pliku historii: {path} (tryb {mode})',
    'history.err_unusable' => 'Magazyn historii jest bezużyteczny: {error} — usuń plik historii lub popraw jego uprawnienia.',
    'query.cancelled' => 'Zapytanie asynchroniczne anulowane',
    'tx.unknown' => 'Nieznane polecenie — :tx rozpoczyna, zatwierdza lub wycofuje transakcję',
    'tx.async_refusal' => ':tx obejmuje sesję synchroniczną; asynchroniczny transport nie ma jeszcze skoordynowanego ramienia transakcji — wykonaj jawne BEGIN/COMMIT lub użyj ścieżki synchronicznej',
    'tx.already_open' => 'Transakcja jest już otwarta — :tx commit albo :tx rollback',
    'tx.no_tx' => 'Brak otwartej transakcji — :tx ją otworzy',
    'tx.opened' => 'Transakcja rozpoczęta — instrukcje jedą w niej do :tx commit albo :tx rollback',
    'tx.committed' => 'Transakcja zatwierdzona',
    'tx.rolled_back' => 'Transakcja wycofana',
    'tx.arm_rollback' => 'ROLLBACK odrzuca wszystkie zmiany od BEGIN — naciśnij ponownie klawisz uruchomienia, aby potwierdzić',
    'tx.failed' => 'Polecenie transakcji nie powiodło się: {message}',
    'history.none' => 'Nie ma czego czytać — historia stanu zapisuje tylko sesje rodziny MySQL (włączane --history)',
    'history.empty' => 'Brak próbek w ostatnich {minutes} minutach — trend wymaga co najmniej dwóch',
    'history.unreadable' => 'Magazyn historii nieczytelny: {message}',
    'history.status' => 'Trend historii — {samples} wierszy z ostatnich {minutes} minut',
    'query.timed_out' => 'Zapytanie asynchroniczne przekroczyło limit czasu po {seconds}s',
    'reports.err_meta_missing' => 'Nie znaleziono pliku metadanych raportów: {path}',
    'reports.err_meta_read' => 'Nie udało się odczytać pliku metadanych raportów: {path}',
    'reports.err_not_in_catalog' => 'Nie znaleziono raportu w katalogu: {name}',
    'reports.err_view_unavailable' => 'Widok niedostępny na tym serwerze: {name}',
    'reports.err_loading' => 'Błąd ładowania raportu: {error}',
    'variables.err_meta_missing' => 'Nie znaleziono pliku metadanych zmiennych: {path}',
    'variables.err_meta_read' => 'Nie udało się odczytać pliku metadanych zmiennych: {path}',
    'resilience.statement_timeout_default' => 'Przekroczono limit czasu wykonania instrukcji',
    'resilience.statement_timeout_after' => 'Przekroczono limit czasu wykonania instrukcji po {seconds} s',
    'variables.err_prepare' => 'Nie można przygotować instrukcji na tym połączeniu',
    'variables.err_not_editable' => 'Zmiennej nie można edytować',
    'variables.err_persist_mariadb' => 'Trwałe zapisanie wymaga MariaDB 11.1+ — ten serwer nie implementuje SET PERSIST.',
    'variables.err_persist_mysql' => 'Trwałe zapisanie wymaga MySQL 8.0.16+ — ten serwer nie implementuje SET PERSIST.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: pusta nazwa użytkownika — odmowa połączenia z MySQL jako domyślnie zgadywany "root"; podaj użytkownika jawnie',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: pusta nazwa użytkownika — odmowa połączenia z PostgreSQL jako domyślnie zgadywany "postgres"; podaj użytkownika jawnie',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: nierozpoznany sslmode "{mode}"; oczekiwano jednego z {allowed} — ciche pominięcie literówki obniżyłoby poziom do "prefer" dopuszczającego jawny tekst',
    'store.err_read' => 'Nie można odczytać pliku połączeń: {path} (usuń go lub popraw uprawnienia)',
    'store.err_json' => 'Plik połączeń nie jest poprawnym JSON ({error}): {path}',
    'store.err_shape' => 'Plik połączeń musi być obiektem JSON z mapą "connections": {path}',
    'store.err_connections_type' => 'Wartość "connections" musi być obiektem JSON nazwa -> ustawienia: {path}',
    'store.err_unknown' => 'Nieznane połączenie \'{name}\' — zapisane połączenia: {saved}',
    'store.none' => '(brak)',
    'store.err_pass_env_unset' => 'Połączenie \'{name}\' odczytuje hasło z ${var} — wyeksportuj je przed połączeniem',
    'store.err_mkdir' => 'Nie można utworzyć katalogu połączeń: {dir}',
    'store.err_write' => 'Nie można zapisać pliku połączeń: {path}',
    'store.err_replace' => 'Nie można zastąpić pliku połączeń: {path}',
    'store.err_entry_type' => 'Połączenie \'{name}\' musi wskazywać obiekt JSON z ustawieniami',
    'store.err_plaintext_pass' => 'Połączenie \'{name}\' przechowuje hasło \'pass\' jawnym tekstem — connections.json nigdy nie zawiera sekretów: zastąp je "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'Połączenie \'{name}\' ma nieznane ustawienie \'{field}\' — dozwolone: {allowed}',
    'store.err_setting_type' => 'Ustawienie \'{field}\' połączenia \'{name}\' musi być ciągiem lub liczbą',
    'store.err_setting_empty' => 'Ustawienie \'{field}\' połączenia \'{name}\' nie może być puste',
    'store.err_needs_driver' => 'Połączenie \'{name}\' wymaga \'driver\'',
    'store.err_driver' => 'Połączenie \'{name}\' używa nieobsługiwanego sterownika \'{driver}\' — wybierz jeden z: {allowed}',
    'store.err_port' => 'Ustawienie \'port\' połączenia \'{name}\' musi być liczbą',
    'store.err_pass_env' => 'Ustawienie \'pass-env\' połączenia \'{name}\' musi być nazwą zmiennej środowiskowej',
    'store.err_name' => 'Nieprawidłowa nazwa połączenia \'{name}\' — użyj liter, cyfr, kropki, myślnika lub podkreślenia (1-64 znaków)',
    'store.err_secure' => 'Nie można zabezpieczyć {path} (tryb {mode})',
    'db.err_dsn_semicolon' => 'Host i nazwa bazy danych nie mogą zawierać \';\' — to separator opcji DSN PDO',
    'db.err_unknown_pg_ssl' => 'Nieznany ssl-mode PostgreSQL: {mode}',
    'db.err_unknown_mysql_ssl' => 'Nieznany ssl-mode MySQL: {mode}',
    'db.err_pg_driver' => 'Nie można połączyć się ze sterownikiem innym niż PostgreSQL przy użyciu łącznika PostgreSQL',
    'db.err_mysql_driver' => 'Łącznik MySQL akceptuje tylko sterownik mysql (otrzymano \'{driver}\')',
    'db.err_reconnect' => 'Ponowne połączenie po błędzie połączenia nie powiodło się: {error}',
    'db.err_quote_no_connection' => 'Nie można cytować bez połączenia',
    'dml.err_key_required' => 'wymagany jest klucz wiersza: odrzucono instrukcję, która mogłaby zmienić więcej niż jeden wiersz',
    'dml.err_key_null' => 'kolumna klucza {column} ma wartość NULL: nie można wskazać wiersza',
    'dml.err_empty_table' => 'nazwa tabeli nie może być pusta',
    'dml.err_empty_column' => 'nazwa kolumny nie może być pusta',
    'dml.err_empty_key_column' => 'nazwa kolumny klucza nie może być pusta',
    'status.already_saved' => 'już zapisano ({count})',
    'status.not_in_favorites' => 'nie ma w ulubionych ({count})',
    'status.gtid_mariadb' => 'GTID_MODE jest niedostępny: MariaDB nie ma zmiennej gtid_mode.',
    'status.gtid_version' => 'GTID_MODE wymaga MySQL 5.7.6 lub nowszego (serwer to {version}).',
    'caption.blocks_hit' => 'Trafienia bloków',
    'caption.blocks_read' => 'Odczytane bloki',
    'caption.buffer_pool_read_reqs' => 'Żądania odczytu puli buforów',
    'caption.buffer_pool_usage' => 'Wykorzystanie puli buforów',
    'caption.buffer_pool_write_reqs' => 'Żądania zapisu puli buforów',
    'caption.bytes_in' => 'Bajty przychodzące',
    'caption.bytes_out' => 'Bajty wychodzące',
    'caption.cache_hit_rate' => 'Współczynnik trafień pamięci podręcznej',
    'caption.commits' => 'Zatwierdzenia',
    'caption.connections' => 'Połączenia',
    'caption.deadlocks' => 'Zakleszczenia',
    'caption.disk_reads_not_from_pool' => 'Odczyty z dysku (spoza puli)',
    'caption.disk_write_requests' => 'Żądania zapisu na dysk',
    'caption.doublewrite_writes' => 'Zapisy doublewrite',
    'caption.innodb_disk_reads' => 'Odczyty dysku InnoDB',
    'caption.innodb_disk_writes' => 'Zapisy dysku InnoDB',
    'caption.insert_buffer' => 'Bufor wstawiania',
    'caption.pages_created' => 'Utworzone strony',
    'caption.pages_flushed' => 'Zrzucone strony',
    'caption.pages_read' => 'Odczytane strony',
    'caption.read_ahead' => 'Odczyt z wyprzedzeniem',
    'caption.redo_log_bytes_written' => 'Zapisane bajty dziennika redo',
    'caption.redo_log_writes' => 'Zapisy dziennika redo',
    'caption.rollbacks' => 'Wycofania',
    'caption.row_lock_time' => 'Czas blokad wierszy',
    'caption.row_lock_waits' => 'Oczekiwania na blokady wierszy',
    'caption.shared_buffers' => 'Bufory współdzielone',
    'caption.sql_statements' => 'Instrukcje SQL',
    'caption.table_open_cache' => 'Pamięć podręczna otwartych tabel',
    'caption.temp_bytes' => 'Bajty tymczasowe',
    'caption.temp_files' => 'Pliki tymczasowe',
    'caption.transactions' => 'Transakcje',
    'caption.tuples_fetched' => 'Pobrane krotki',
    'caption.tuples_returned' => 'Zwrócone krotki',
    'caption.traffic' => 'Ruch',
    'caption.key_efficiency' => 'Wydajność kluczy',
    'caption.selects_per_second' => 'Selecty na sekundę',
    'caption.innodb_buffer_usage' => 'Wykorzystanie bufora InnoDB',
    'caption.innodb_reads_per_second' => 'Odczyty InnoDB na sekundę',
    'caption.innodb_writes_per_second' => 'Zapisy InnoDB na sekundę',
    'widget.tip.bytes_in' => 'Ruch przychodzący: łącznie {Bytes_received} bajtów',
    'widget.tip.bytes_out' => 'Ruch wychodzący: łącznie {Bytes_sent} bajtów',
    'widget.tip.client_connections' => 'Połączenia klientów: {Threads_connected}',
    'widget.tip.connections_level' => 'Poziom połączeń względem max_connections',
    'widget.tip.table_open_cache' => 'Współczynnik trafień pamięci podręcznej otwartych tabel',
    'widget.tip.sql_statements' => 'Częstość instrukcji SQL: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'Procent wykorzystania puli buforów InnoDB (w bajtach)',
    'widget.tip.buffer_pool_read_reqs' => 'Żądania odczytu puli buforów InnoDB na sekundę',
    'widget.tip.buffer_pool_write_reqs' => 'Żądania zapisu puli buforów InnoDB na sekundę',
    'widget.tip.disk_reads_not_from_pool' => 'Odczyty puli buforów InnoDB z dysku na sekundę',
    'widget.tip.innodb_disk_writes' => 'Bajty danych InnoDB zapisane na dysk na sekundę',
    'widget.tip.disk_write_requests' => 'Żądania zapisu plików danych InnoDB na sekundę',
    'widget.tip.innodb_disk_reads' => 'Bajty danych InnoDB odczytane z dysku na sekundę',
    'widget.tip.row_lock_waits' => 'Oczekiwania na blokady wierszy InnoDB na sekundę',
    'widget.tip.row_lock_time' => 'Czas blokad wierszy InnoDB na sekundę (milisekundy)',
    'widget.tip.pages_flushed' => 'Strony InnoDB zrzucone na sekundę',
    'widget.tip.pages_created' => 'Strony InnoDB utworzone na sekundę',
    'widget.tip.pages_read' => 'Strony InnoDB odczytane na sekundę',
    'widget.tip.insert_buffer' => 'Rozmiar bufora wstawiania InnoDB (strony)',
    'widget.tip.read_ahead' => 'Odczyt z wyprzedzeniem puli buforów InnoDB na sekundę',
    'widget.tip.redo_log_bytes_written' => 'Bajty dziennika redo InnoDB zapisane na sekundę',
    'widget.tip.redo_log_writes' => 'Zapisy dziennika redo InnoDB na sekundę',
    'widget.tip.doublewrite_writes' => 'Zapisy doublewrite InnoDB na sekundę',
    'widget.tip.alter_rate' => 'Częstość poleceń ALTER (wszystkie Com_alter_*)',
    'widget.tip.create_rate' => 'Częstość poleceń CREATE (wszystkie Com_create_*)',
    'widget.tip.drop_rate' => 'Częstość poleceń DROP (wszystkie Com_drop_*)',
    'widget.tip.select_rate' => 'Częstość SELECT',
    'widget.tip.insert_rate' => 'Częstość INSERT',
    'widget.tip.update_rate' => 'Częstość UPDATE',
    'widget.tip.delete_rate' => 'Częstość DELETE',
    'widget.tip.tuples_fetched_total' => 'Wiersze pobrane z bazy: łącznie {tup_fetched}',
    'widget.tip.tuples_fetched_rate' => 'Częstość pobierania wierszy',
    'widget.tip.tuples_returned_total' => 'Wiersze zwrócone klientowi: łącznie {tup_returned}',
    'widget.tip.tuples_returned_rate' => 'Częstość zwracania wierszy',
    'widget.tip.blocks_read_total' => 'Odczytane bloki dyskowe: łącznie {blks_read}',
    'widget.tip.blocks_hit_total' => 'Trafienia pamięci podręcznej buforów: łącznie {blks_hit}',
    'widget.tip.active_backends' => 'Aktywne backendy: {numbackends}',
    'widget.tip.transaction_rates' => 'Częstość transakcji: zatwierdzenia / wycofania',
    'widget.tip.commit_rate' => 'Częstość zatwierdzeń transakcji',
    'widget.tip.rollback_rate' => 'Częstość wycofań transakcji',
    'widget.tip.tuple_insert_rate' => 'Częstość wstawiania krotek',
    'widget.tip.tuple_update_rate' => 'Częstość aktualizacji krotek',
    'widget.tip.tuple_delete_rate' => 'Częstość usuwania krotek',
    'widget.tip.deadlock_rate' => 'Częstość zakleszczeń',
    'widget.tip.shared_buffers' => 'Konfiguracja shared_buffers PostgreSQL (rozmiar puli buforów)',
    'widget.tip.cache_hit_rate' => 'Współczynnik trafień pamięci podręcznej buforów: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Częstość tworzenia plików tymczasowych',
    'widget.tip.temp_bytes' => 'Bajty zapisane do plików tymczasowych na sekundę',
    'dash.data_written' => 'zapisano danych {kb} kB/s',
    'dash.writes_ops' => 'zapisy {n} op./s',
    'dash.log_written' => 'zapisano logu {kb} kB/s',
    'dash.read_reqs' => 'żądania odczytu {n} stron/s',
    'dash.write_reqs' => 'żądania zapisu {n} stron/s',
    'dash.disk_reads' => 'odczyty z dysku {n} /s',
    'metrics.busy_proxy' => 'proxy zajętości {pct}%',
    'metrics.cpu_load' => 'obciążenie CPU {load}',
    'metrics.no_samples' => 'brak próbek',
    'metrics.connections_count' => '{count} połączeń',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Metryki serwera',
    'explain.query_block' => 'BLOK ZAPYTANIA #{id} (koszt: {cost})',
    'variables.footer_tree' => '[j/k] drzewo',
    'variables.footer_rows' => '[j/k] wiersze',
    'variables.footer_categories' => '[c] kategorie',
    'variables.footer_edit' => '[Enter/e] edytuj (dynamiczne)',
    'variables.footer_rw_filter' => '[w] filtr rw',
    'variables.footer_search' => '[s] szukaj',
    'variables.footer_back' => '[q] wstecz',
    'srv.title' => 'Stan serwera',
    'srv.features' => 'Funkcje',
    'srv.stored_programs' => 'Programy składowane',
    'srv.directories' => 'Katalogi',
    'srv.data_directory' => 'Katalog danych',
    'srv.temp_directory' => 'Katalog tymczasowy',
    'srv.log_directory' => 'Katalog dzienników',
    'srv.pid_file' => 'Plik PID',
    'srv.tls_active' => 'TLS aktywny',
    'srv.tls_cipher' => 'Szyfr TLS',
    'srv.tls_version' => 'Wersja TLS',
    'srv.ssl_support' => 'Obsługa SSL',
    'srv.ssl_ca' => 'CA SSL',
    'srv.ssl_cert' => 'Certyfikat SSL',
    'srv.ssl_key' => 'Klucz SSL',
    'srv.tls_secure' => 'TLS / bezpieczne połączenie',
    'srv.replication' => 'Replikacja',
    'srv.repl_denied' => 'Niewystarczające uprawnienia (REPLICATION CLIENT)',
    'srv.repl_error' => 'Błąd dostępu do stanu replikacji',
    'srv.not_configured' => 'Nie skonfigurowano',
    'srv.channel' => 'Kanał',
    'srv.source_host' => 'Host źródłowy',
    'srv.source_port' => 'Port źródłowy',
    'srv.replica_io' => 'IO repliki działa',
    'srv.replica_sql' => 'SQL repliki działa',
    'srv.seconds_behind' => 'Sekundy opóźnienia względem źródła',
    'srv.relay_log_file' => 'Plik dziennika przekaźnika',
    'srv.relay_pos' => 'Poz. przekaźnika',
    'srv.repl_channel_title' => 'Replikacja › {channel}',
    'srv.default' => 'Domyślny',
    'srv.firewall' => 'Zapora',
    'srv.mysql_firewall' => 'Zapora MySQL',
    'srv.caught_up' => '0 s (zsynchronizowano)',
    'srv.host' => 'Host',
    'srv.socket' => 'Gniazdo',
    'srv.port' => 'Port',
    'srv.version' => 'Wersja',
    'srv.uptime' => 'Czas pracy',
    'srv.running_since' => 'Działa od',
    'srv.unknown' => 'Nieznane',
    'srv.server_information' => 'Informacje o serwerze',
    'perf.tab_easy_setup' => 'Łatwa konfiguracja',
    'perf.tab_instruments' => 'Instrumenty',
    'perf.tab_consumers' => 'Konsumenci',
    'perf.tab_actors' => 'Aktorzy',
    'perf.tab_objects' => 'Obiekty',
    'perf.tab_threads' => 'Wątki',
    'perf.tab_options' => 'Opcje',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[TYLKO DO ODCZYTU]',
    'perf.nothing_to_commit' => 'Brak zmian do zatwierdzenia',
    'perf.commit_planning_failed' => 'Planowanie zatwierdzenia nie powiodło się: {error}',
    'perf.err_prepare' => 'Nie udało się przygotować instrukcji: {sql}',
    'perf.committed_one' => 'Zatwierdzono {count} instrukcję',
    'perf.committed_other' => 'Zatwierdzono instrukcji: {count}',
    'perf.applied_one' => '{label} zastosowano — {count} instrukcja',
    'perf.applied_other' => '{label} zastosowano — instrukcji: {count}',
    'perf.commit_failed' => '{label} nie powiodło się przy instrukcji {position} z {total}: {error} — wcześniejsze instrukcje zostały zastosowane, stan serwera jest częściowy.',
    'perf.err_access_denied' => 'Odmowa dostępu: brak wymaganych uprawnień',
    'perf.err_not_enabled' => 'Performance Schema nie jest włączone',
    'perf.err_cannot_connect' => 'Nie można połączyć się z bazą danych',
    'perf.state_fully' => 'W pełni włączone',
    'perf.state_default' => 'Konfiguracja domyślna',
    'perf.state_custom' => 'Konfiguracja niestandardowa',
    'perf.state_disabled' => 'Wyłączone',
    'perf.state_unknown' => 'Nieznane',
    'perf.current_state' => 'Bieżący stan: {state}',
    'perf.readonly_note' => '(Tryb tylko do odczytu - brak uprawnień do modyfikacji)',
    'perf.keys_hint' => 'Klawisz "[" Włącz pełne Performance Schema   Klawisz "]" Wyłącz   Klawisz "." Przywróć domyślne',
    'perf.default_instruments' => 'Domyślne instrumenty: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Domyślni konsumenci: events_statements_history, events_waits_history itd.',
    'perf.none_instruments' => '(brak dostępnych instrumentów)',
    'perf.none_consumers' => '(brak dostępnych konsumentów)',
    'perf.none_actors' => '(brak skonfigurowanych aktorów)',
    'perf.none_objects' => '(brak skonfigurowanych reguł obiektów)',
    'perf.none_threads' => '(brak dostępnych wątków)',
    'perf.total_instruments_one' => 'Razem: {count} instrument',
    'perf.total_instruments_other' => 'Razem: {count} instrumentów',
    'perf.total_consumers_one' => 'Razem: {count} konsument',
    'perf.total_consumers_other' => 'Razem: {count} konsumentów',
    'perf.total_actors_one' => 'Razem: {count} aktor',
    'perf.total_actors_other' => 'Razem: {count} aktorów',
    'perf.total_objects_one' => 'Razem: {count} reguła obiektu',
    'perf.total_objects_other' => 'Razem: {count} reguł obiektów',
    'perf.total_threads_one' => 'Razem: {count} wątek',
    'perf.total_threads_other' => 'Razem: {count} wątków',
    'perf.col_thread_id' => 'ID wątku',
    'perf.col_name' => 'Nazwa',
    'perf.col_type' => 'Typ',
    'perf.col_user' => 'Użytkownik',
    'perf.timer_options' => 'Opcje timera',
    'perf.none_timers' => '(brak dostępnych timerów - tylko do odczytu)',
    'perf.col_timer_name' => 'Nazwa timera',
    'perf.col_implementation' => 'Implementacja',
    'perf.col_scale_factor' => 'Współczynnik skali',
    'perf.timer_readonly_note' => 'Konfiguracja timera jest tylko do odczytu (określona przez kompilację serwera)',
    'perf.footer_nav' => '[j/k] nawigacja  [Space] przełącz',
    'perf.footer_actions' => '  [c] przygotuj zatwierdzenie ([y] wykonaj)  [r] cofnij',
    'perf.footer_back' => '  [q] wstecz',
    'perf.pending_one' => 'Oczekuje: {count} zmiana',
    'perf.pending_other' => 'Oczekuje: {count} zmian',
    'cli.err_not_tty' => 'ten launcher steruje interaktywnym terminalem i odmawia przesyłania ANSI do potoku (kod wyjścia 2) — uruchom go w pty (np. script -qec \'candy-sql app.sqlite\' /dev/null) lub renderuj bez interfejsu z kodu biblioteki (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'zapis historii WYŁĄCZONY ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' wymaga zweryfikowanego TLS — transport administracyjny ReactPHP nie może tego spełnić, więc odświeżanie na żywo w administracji jest WYŁĄCZONE, a zapytania i ładowanie tabel działają synchronicznie przez połączenie TLS.',
    'cli.no_connection_selected' => 'nie wybrano połączenia — podaj ścieżkę, DSN lub --connect=<name>',
    'cli.usage_line' => 'UŻYCIE: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # pełne informacje o użyciu',
    'cli.tip_saved_connections' => '       Wskazówka: zapisz nazwane połączenia w {path} i uruchom z --connect=<name>.',
    'help.summary' => 'candy-sql - Terminalowy klient SQL dla SQLite, MySQL i PostgreSQL',
    'help.usage' => 'SKŁADNIA
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGUMENTY
  path-or-dsn
    Ścieżka pliku bazy SQLite, np.: app.sqlite
    Albo ciąg DSN, np.:  mysql://user:pass@localhost:3306/dbname

  Po całkowitym pominięciu: w interaktywnym terminalu z zapisanymi
  połączeniami selektor je oferuje; w przeciwnym razie drukowany jest
  krótki wiersz użycia, a proces kończy się statusem 2 (błędne wywołanie)
  — uruchom --help, aby zobaczyć ten pełny ekran.',
    'help.options' => 'OPCJE
  --help, -h
    Pokazuje ten ekran pomocy.

  --version, -V
    Pokazuje numer wersji.

  --connect=<name>
    Łączy się z wpisem o podanej nazwie z pliku zapisanych połączeń
    ($XDG_CONFIG_HOME/candy-sql/connections.json, domyślnie
    $HOME/.config/candy-sql/connections.json). Nie można łączyć z jawnymi
    argumentami połączenia. Hasła nigdy nie są zapisywane na dysku: wpis
    wskazuje swój sekret przez "pass-env", a zmienna jest odczytywana w
    chwili łączenia. Zobacz ZAPISANE POŁĄCZENIA poniżej.',
    'help.option_dsn' => '  --dsn=<dsn>
    Pełny ciąg połączenia DSN.
    Format: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Przykłady:
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
    Pojedyncze parametry połączenia (wszystkie poza --driver i --db są opcjonalne).
    --driver musi być jednym z: sqlite, mysql, pgsql
    --ssl-mode domyślnie ma wartość "prefer" dla MySQL. "require" i "verify_*"
    wymuszają TLS z weryfikacją certyfikatu serwera; zaufanie do CA trzeba
    skonfigurować po stronie serwera lub w my.cnf (zobacz README). W tych
    trybach weryfikowanych transport ReactPHP, dopuszczający zwykły tekst,
    jest odrzucany — zapytania działają synchronicznie przez połączenie TLS,
    a odświeżanie na żywo panelu administracyjnego jest wyłączone (informują
    o tym komunikat startowy i wiersz stanu aplikacji).
    Preferuj CANDY_SQL_PASS zamiast --pass=, aby hasło nie trafiło do wiersza
    poleceń procesu (widocznego w ps).',
    'help.examples' => 'PRZYKŁADY
  # SQLite (domyślnie — sama ścieżka lub DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL przez DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL przez pojedyncze opcje
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'ZAPISANE POŁĄCZENIA
  Wypisz serwery z nazwami raz i łącz się z nimi już zawsze:

    $HOME/.config/candy-sql/connections.json  (katalog 0700, plik 0600)',
    'help.saved_fields' => '  Pola: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Każde inne pole jest odrzucane, a pole "pass" ze zwykłym
  tekstem jest zawsze odrzucane — wskaż pass-env na zmienną. Uruchomienie
  bez argumentów w interaktywnym terminalu pokazuje te wpisy jako
  selektor; --connect=<name> łączy bezpośrednio z jednym z nich.',
    'help.editing' => 'EDYCJA
  Panel Rows podglądu TABLE to edytowalna siatka: h/l (lub ←/→) przesuwają
  kursor komórki, a trzy skróty zmieniają dane — każdy pokazuje dokładną
  instrukcję w wierszu stanu i niczego nie zapisuje, dopóki Enter nie
  potwierdzi:

    e   edytuj komórkę pod kursorem (Ctrl+N ustawia NULL)
    o   wstaw wiersz (Enter przechodzi po kolumnach; puste wejście
        zostawia kolumnie jej DEFAULT, Ctrl+N zapisuje NULL)
    D   usuń wiersz pod kursorem

  Wiersze są adresowane wyłącznie pełnym PRIMARY KEY: tabela bez niego,
  klucz NULL/binarny, komórka binarna i każdy wynik zapytania są odrzucane
  z nazwanym wierszem stanu. Wartości są parametrami powiązanymi; każdy
  zapis działa w transakcji wycofywanej, chyba że dotknięto dokładnie
  jednego wiersza (UPDATE zapisujący już istniejącą wartość jest zgłaszany
  jako brak zmiany). Esc anuluje otwarty wiersz edycji.',
    'help.editing_readonly' => '  --read-only
    Wyłącza skróty edycji na czas sesji. Chroni przed pomyłką; nie filtruje
    instrukcji w panelu Query — dla twardej gwarancji połącz się jako
    użytkownik bazy danych tylko do odczytu.',
    'help.history' => 'HISTORIA
  Domyślnie każda migawka odpytywania administracyjnego jest zapisywana w
  $HOME/.candy-sql/history.db (katalog 0700 / plik 0600) jako ślad audytu
  offline. Strony Dashboard i Reports w aplikacji nie czytają tego pliku —
  odpytują serwer na żywo.',
    'help.history_flags' => '  --no-history
    Całkowicie wyłącza zapis (nic nie jest zapisywane na dysku).

  --history=<path>
    Zapisuje migawki w <path> zamiast w domyślnej lokalizacji.',
    'help.keyboard' => 'SKRÓTY KLAWISZOWE (TUI)
  Tab          Przełącza fokus między panelami (Tables → Rows → Query → Admin)
  ↑ / ↓        Nawiguje po wierszach listy (lub j/k) — w panelu Tables ruch jest
               tylko lokalny; nigdy nie pobiera podglądu
  /            (Panel Tables) otwiera wiersz filtra na żywo po nazwach tabel; litery
               go wydłużają, ↑/↓ przesuwają kursor, Enter zatwierdza i ładuje,
               Backspace poprawia, Esc porzuca i czyści
  Enter        Ładuje wiersze wybranej tabeli
  Space        Ładuje wiersze wybranej tabeli (alias Enter)
  PgDn / PgUp  (Panel Tables/Rows, na podglądzie tabeli) stronicuje podgląd w oknach
               po 100 wierszy — limit przeglądania stał się rozmiarem strony
  Ctrl+Enter   Wykonuje bieżące zapytanie
  Ctrl+R       Wykonuje bieżące zapytanie
  Ctrl+E       Wykonuje bieżące zapytanie
  EXPLAIN …    (Edytor Query, słowo kluczowe na początku) wpisane EXPLAIN przechodzi
               przez ExplainView: panel Rows pokazuje kolorowe drzewo planu, a nie
               płaską siatkę dostawcy — a każdy modyfikator ANALYZE jest USUWANY,
               inspektor nigdy nie wykonuje tego, co bada
  e / o / D    (Panel Rows, podgląd tabeli) edytuje komórkę / wstawia wiersz /
               usuwa wiersz — zobacz EDYCJA; h/l przesuwają kursor komórki
  Ctrl+Space   Uzupełnia przy kursorze (edytor Query): słowa kluczowe SQL, nazwy tabel
               po FROM/JOIN/INTO/UPDATE oraz kolumny w kontekście (WHERE/SELECT/ORDER BY,
               kwalifikowane `alias.` / `table.`, lista INSERT INTO t (...)) z załadowanego
               schematu. Jeden kandydat jest uzupełniany, wspólny prefiks się
               przesuwa, w przeciwnym razie wiersz stanu wypisuje kandydatów
  Ctrl+F       Dodaje bieżące zapytanie do listy ulubionych (tylko stan sesji — żaden
               panel jej nie przegląda i nie jest zapisywana)
  Ctrl+Shift+F Usuwa bieżące zapytanie z listy ulubionych
  1-8          Przeskakuje do panelu administracyjnego (tylko gdy fokus ma panel Admin)
  q            Wychodzi z paneli Tables/Rows; z Admin wraca do Tables
  x            (Tables/Rows, wynik na żywo) eksportuje bieżące wiersze do ścieżki CSV
               — odmowa nadpisania działa, dopóki Enter nie potwierdzi
  i            (Tables/Rows) importuje plik CSV do tabeli o podanej nazwie
  Ctrl+S       Eksportuje wynik/podgląd na żywo do CSV z dowolnego panelu
  Esc          Wychodzi z paneli Tables/Rows; anuluje otwarte okno dialogowe Admin
  Backspace    Usuwa znak przed kursorem (edytor Query)',
    'help.see_also' => 'ZOBACZ TAKŻE
  https://github.com/sugarcraft/candy-sql',
];
