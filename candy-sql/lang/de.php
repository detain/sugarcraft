<?php

/**
 * German translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite-Datei nicht gefunden: {path}',
    'complete.in_literal' => 'in Zeichenkette oder Kommentar gibt es nichts zu vervollständigen',
    'complete.nothing' => 'nichts zu vervollständigen — Anfang eines Schlüsselworts eingeben',
    'complete.none_keyword' => 'kein Schlüsselwort vervollständigt "{word}"',
    'complete.none_table' => 'keine Tabelle vervollständigt "{word}"',
    'complete.none_column' => 'keine Spalte vervollständigt "{word}"',
    'complete.none_qualified' => 'keine Spalte von {qualifier} vervollständigt "{word}"',
    'complete.list' => '{count} vervollständigen "{word}": {list}{more}',
    'complete.done' => 'vervollständigt {word} → {insert}',
    'complete.inserted' => 'eingefügt {insert}',
    'edit.refuse_readonly' => 'schreibgeschützte Sitzung — Bearbeiten ist deaktiviert',
    'edit.refuse_no_table' => 'zuerst eine Tabellenvorschau öffnen — Abfrageergebnisse lassen sich nicht direkt bearbeiten',
    'edit.refuse_no_rows' => 'keine Zeile unter dem Cursor',
    'edit.refuse_no_pk' => 'diese Tabelle hat keinen Primärschlüssel — Zeilen lassen sich nicht sicher adressieren; Anweisung im Abfragebereich schreiben',
    'edit.refuse_pk_hidden' => 'der Primärschlüssel dieser Zeile ist NULL oder ein Binär-Platzhalter — sie lässt sich nicht adressieren',
    'edit.refuse_binary' => 'binäre oder große Zellen sind hier nicht bearbeitbar — Anweisung im Abfragebereich verwenden',
    'edit.refuse_schema' => 'Schema von {table} nicht lesbar — Bearbeiten abgelehnt',
    'edit.prompt_cell' => 'bearbeite {table}.{column} = {value}  (Enter Vorschau · Strg+N NULL · Esc bricht ab)',
    'edit.prompt_insert' => 'neue Zeile in {table} — {column} ({type}) [{n}/{total}] = {value}  (Enter weiter · leer = DEFAULT · Strg+N NULL · Esc bricht ab)',
    'edit.prompt_confirm' => '{sql}  (Enter führt aus · Esc bricht ab)',
    'edit.cancelled' => 'Bearbeitung abgebrochen',
    'edit.unchanged' => 'Wert unverändert — nichts geschrieben',
    'edit.done_update' => '1 Zeile in {table} aktualisiert',
    'edit.done_insert' => '1 Zeile in {table} eingefügt',
    'edit.done_delete' => '1 Zeile aus {table} gelöscht',
    'edit.none_changed' => 'keine Zeile geändert — der Wert war schon da oder die Zeile ist weg; Vorschau aktualisiert',
    'edit.failed' => 'Schreiben fehlgeschlagen — zurückgerollt, nichts geändert: {reason}',
    'edit.too_many' => 'abgelehnt: die Anweisung würde {count} Zeilen betreffen — zurückgerollt, nichts geändert',
    'ui.title.tables' => ' Tabellen ',
    'ui.title.rows' => ' Zeilen ',
    'ui.title.query' => ' Abfrage ',
    'ui.title.admin' => ' Admin ',
    'ui.title.explain' => ' Plan ',
    'ui.help.query' => 'Tab  Bereich wechseln  ·  Strg+R / Strg+E / Strg+Enter  ausführen  ·  Enter  Zeilenumbruch  ·  ↑/↓  Verlauf  ·  Esc / Strg+C  Abfrage abbrechen  ·  Strg+C  bei Untätigkeit beenden',
    'ui.help.rows' => 'Tab  Bereich wechseln  ·  j/k  Zeile  ·  h/l  Zelle  ·  e  bearbeiten  ·  o  neue Zeile  ·  D  löschen  ·  y  kopieren  ·  x  Export  ·  q / Esc  beenden',
    'ui.help.browse' => 'Tab  Bereich wechseln  ·  Enter  Tabelle laden  ·  x  Export  ·  i  Import  ·  q / Esc  beenden',
    'ui.help.empty' => 'Tab  Bereich wechseln  ·  q / Esc  beenden',
    'param.prompt' => 'Platzhalter {index}/{total} ({label}) - Wert eingeben, Enter bindet ihn (leer = NULL), Esc bricht ab',
    'param.line' => 'Parameter {label} ({index}/{total}) = {value} · leer = NULL · Enter weiter · Esc bricht ab',
    'param.cancel' => 'Parametereingabe abgebrochen - Query nicht ausgeführt',
    'param.mixed' => 'Mischung aus ? und :name wird nicht unterstützt - einen Stil wählen',
    'yank.refuse_no_cell' => 'keine Zelle unter dem Cursor',
    'yank.osc52' => 'Zelle in die Zwischenablage - Terminal kann OSC 52 ignorieren',
    'yank.plain' => 'kein OSC 52 im Terminal - Zelltext: {text}',
    'yank.plain_null' => 'kein OSC 52 im Terminal - die Zelle ist NULL',
    'yank.too_big' => 'Zelle ist {bytes} Bytes - über der OSC-52-Grenze',
    'ui.help.result' => 'Tab  Bereich wechseln  ·  j/k  Zeile  ·  h/l  Zelle  ·  y  kopieren  ·  x  Export  ·  q / Esc  beenden',
    'ui.help.admin' => '1-{count}  Seite wählen  ·  j/k  navigieren  ·  p  pausieren  ·  r  zurücksetzen  ·  Tab  Bereich wechseln  ·  q  zurück  ·  Esc  Dialog oder Abfrage abbrechen',
    'ui.no_tables' => '(keine Tabellen)',
    'ui.no_match' => '(kein Treffer)',
    'ui.loading' => 'lädt…',
    'ui.empty' => '(leer)',
    'ui.admin_unavailable' => 'Admin-Seite nicht verfügbar: {reason}',
    'ui.error' => 'Fehler: {message}',
    'io.prompt_export' => 'nach CSV → {path}  (Enter schreibt · Esc bricht ab)',
    'io.prompt_replace' => '{path} ERSETZEN?  (Enter bestätigt · Esc bricht ab)',
    'io.prompt_import_path' => 'CSV importieren ← {path}  (Enter · Esc bricht ab)',
    'io.prompt_import_table' => 'importiere {source} → Tabelle {table}  (Enter · Esc bricht ab)',
    'bar.tables' => 'Tabellen: {count}',
    'bar.cycle' => 'Tab:wechseln',
    'bar.navigate' => '↑↓:navigieren',
    'bar.load' => 'Enter:laden',
    'bar.run' => 'Strg+R:ausführen',
    'bar.select' => '1-{count}:wählen',
    'bar.nav' => 'j/k:nav',
    'bar.quit' => 'q:beenden',
    'bar.paused' => '[PAUSIERT]',
    'bar.tx' => '[TX]',
    'picker.title' => 'gespeicherte Verbindungen',
    'picker.help' => '[↑↓/j/k] wählen   [Enter] verbinden   [q] abbrechen',
    'result.empty' => '(leere Ergebnismenge)',
    'explain.no_plan' => '(kein Abfrageplan — die Abfrage lieferte keine Zeilen)',
    'explain.title' => ' ABFRAGEPLAN ',
    'status.live_refresh_off' => 'Admin-Live-Aktualisierung deaktiviert — TLS-verifizierter ssl-mode (nur synchroner Transport)',
    'status.async_failed' => 'Asynchroner Befehl fehlgeschlagen: {message}',
    'status.file_cancelled' => 'Dateizeile abgebrochen',
    'status.path_required' => 'ein Pfad ist erforderlich — Esc bricht ab',
    'status.no_such_file' => 'Datei nicht gefunden — weitertippen oder Esc bricht ab',
    'status.table_required' => 'Zieltabelle benennen — Esc bricht ab',
    'status.export_failed' => 'Export fehlgeschlagen: {message}',
    'status.exported' => '{count} Zeilen nach {path} exportiert',
    'status.import_failed' => 'Import fehlgeschlagen: {message}',
    'status.imported' => '{path} in {table} importiert',
    'status.history' => 'Verlauf {pos}/{count}',
    'status.conn_lost' => 'Verbindung verloren; erneut versuchen',
    'status.destructive' => '⚠ destruktive Anweisung — zum Bestätigen erneut Strg+R (unverändert)',
    'status.running' => 'Abfrage läuft…',
    'status.explain_failed' => 'EXPLAIN fehlgeschlagen: {message}',
    'status.explain_plan' => 'Ausführungsplan — jede Abfrage oder Tabellenvorschau ersetzt ihn',
    'status.end_of_table' => 'Tabellenende — keine weitere Seite',
    'status.first_page' => 'bereits auf der ersten Seite',
    'status.nothing_to_save' => 'nichts zu speichern',
    'status.saved' => 'gespeichert ({count})',
    'status.removed' => 'entfernt ({count})',
    'status.row_one' => '1 Zeile',
    'status.rows' => '{count} Zeilen',
    'status.page' => 'Seite {page} (Zeilen {first}–{last})',
    'editor.placeholder' => '-- SQL eingeben, Strg+R zum Ausführen --',
    'err.async_query_unsupported' => 'Dieser Treiber unterstützt keine asynchronen Abfragen',
    'err.async_browse_unsupported' => 'Asynchrones Durchsuchen wird von diesem Treiber nicht unterstützt',
    'dash.title' => 'Leistungs-Dashboard',
    'dash.fetching' => 'Server-Metriken werden abgerufen...',
    'dash.back_hint' => '[q] drücken, um zur Tabellenansicht zurückzukehren',
    'dash.recent_queries' => 'Letzte Abfragen',
    'dash.no_queries' => '(noch keine Abfragen)',
    'dash.header_pg' => 'Leistungs-Dashboard{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Leistungs-Dashboard{status} | MySQL {version} | Laufzeit: {uptime}',
    'dash.uptime_segment' => ' | Laufzeit: {uptime}',
    'pane.process_list' => 'Prozessliste',
    'pane.variables' => 'Variablen',
    'pane.status' => 'Status',
    'pane.query_stats' => 'Abfragestatistik',
    'pane.dashboard' => 'Dashboard',
    'pane.table_stats' => 'Tabellenstatistik',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Debug',
    'page.no_data' => 'Noch keine Daten verfügbar.',
    'page.error' => 'Fehler: {message}',
    'page.loading' => 'Wird geladen…',
    'page.fetching' => 'Daten werden vom Server abgerufen.',
    'debug.title' => 'Debug-Protokoll',
    'debug.entries' => ' ({count} Einträge)',
    'debug.errors_only' => '[nur Fehler]',
    'debug.empty' => '(keine Protokolleinträge)',
    'debug.more' => '↑ {count} weitere Einträge',
    'debug.help' => '[j/k] nav  [f] nur Fehler  [c] leeren  [q] zurück',
    'reports.cannot_access' => 'Zugriff auf Leistungsberichte nicht möglich: {message}',
    'reports.title' => 'Leistungsberichte',
    'reports.categories' => 'Kategorien',
    'reports.no_data' => '(keine Daten)',
    'reports.loading' => 'Berichte werden geladen…',
    'reports.loading_one' => '{caption} wird geladen…',
    'reports.no_data_for' => 'Keine Daten für {caption} verfügbar',
    'reports.help' => '[j/k] Zeilen  [h/l] Kategorie  [[]/[]] Bericht  [,/.] Spalte  [c] Einheit  [r] aktualisieren  [x] Export  [q] zurück',
    'reports.unavailable' => 'Leistungsberichte nicht verfügbar',
    'reports.ensure' => 'MySQL 5.6.6+ mit performance_schema=enabled und installiertem sys-Schema sicherstellen.',
    'conn.kill_cancelled' => 'Beenden abgebrochen',
    'conn.none_selected' => 'Keine Verbindung ausgewählt',
    'conn.refuse_background' => 'Beenden eines Hintergrund-Threads verweigert',
    'conn.verb_kill_query' => 'Abfrage beenden',
    'conn.verb_kill' => 'Beenden',
    'conn.sent' => '{verb} an Verbindung {id} gesendet',
    'conn.failed' => '{verb} für Verbindung {id} fehlgeschlagen',
    'conn.confirm_verb_query' => 'Laufende Abfrage abbrechen auf',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} Verbindung {id} ({user}@{host})?  [y] bestätigen   [andere Taste] abbrechen',
    'conn.help_empty' => '[j/k] nav  [f] Filter  [r] aktualisieren',
    'conn.help_full' => '[j/k] nav  [K] kill  [X] Abfrage-kill  [f] Filter  [r] aktualisieren',
    'conn.vars_fail' => 'Servervariablen konnten nicht abgerufen werden',
    'vars.load_fail' => 'Variablen konnten nicht geladen werden: {message}',
    'vars.unknown_error' => 'Unbekannter Fehler',
    'vars.not_dynamic' => 'Fehler 1238: \'{name}\' ist nicht dynamisch — [p] drücken für PERSIST_ONLY',
    'vars.new_value' => 'neuer Wert > ',
    'vars.edit_head' => 'Bearbeiten ({mode}) {name} (aktuell: {current})',
    'vars.scope_explain' => 'GLOBAL = nur Laufzeit (geht beim Neustart verloren) · PERSIST = Laufzeit + Konfigurationsdatei · PERSIST_ONLY = nur Konfigurationsdatei (gilt ab dem nächsten Neustart)',
    'vars.edit_help' => 'Neuen Wert eingeben, [p] Modus wechseln, [Enter] bestätigen, [Esc] abbrechen',
    'reports.back' => '[q] zurück',
    'err.dsn_empty' => 'DSN darf nicht leer sein',
    'err.dsn_no_separator' => 'Ungültiger DSN: Trennzeichen "://" fehlt',
    'err.driver_unsupported' => 'Nicht unterstützter Treiber: {driver}',
    'err.dsn_sqlite_invalid' => 'Ungültiger SQLite-DSN',
    'err.dsn_parse_failed' => 'Ungültiger DSN: parse_url() fehlgeschlagen',
    'err.dsn_no_host' => 'Ungültiger DSN: Host fehlt',
    'err.driver_choose' => 'Nicht unterstützter Treiber: {driver} — wählen Sie einen von {list}',
    'err.args_missing' => 'Erforderliche Argumente fehlen: --driver und --db angeben (oder --dsn verwenden)',
    'err.opt_needs_value' => 'Option --{name} erfordert einen Wert',
    'err.opt_unknown' => 'Unbekannte Option: --{name}',
    'err.csv_not_found' => 'CSV-Datei nicht gefunden: {path}',
    'err.csv_unreadable' => 'Die CSV-Datei \'{path}\' kann nicht gelesen werden: prüfen Sie, ob sie existiert und lesbar ist',
    'err.csv_no_headers' => 'CSV-Kopfzeile kann nicht gelesen werden aus: {path}',
    'err.csv_no_columns' => 'CSV-Datei hat keine Spalten: {path}',
    'err.dest_unwritable' => 'Das Ziel \'{path}\' kann nicht zum Schreiben geöffnet werden: prüfen Sie, ob das Verzeichnis existiert und beschreibbar ist',
    'err.export_buffer' => 'Der Export-Puffer konnte nicht erstellt werden',
    'err.csv_partial' => 'CSV-Export von {table} schrieb {written} von {expected} Zeilen — die Tabelle änderte sich während des Exports oder der Treiber ignorierte das Paging-Fenster; eine unvollständige Datei wird nicht als vollständig ausgegeben',
    'err.sql_partial' => 'SQL-Dump von {table} schrieb {written} von {expected} Zeilen — die Tabelle änderte sich während des Dumps oder der Treiber ignorierte das Paging-Fenster; eine unvollständige Datei wird nicht als vollständig ausgegeben',
    'err.table_unreadable' => 'Tabelle nicht gefunden oder nicht lesbar: {table} ({message})',
    'err.dml_no_prepare' => 'diese Verbindung kann keine vorbereiteten Anweisungen ausführen',
    'err.dml_refused' => 'der Server hat die Anweisung abgelehnt',
    'alert.connection_critical' => 'Verbindungsauslastung kritisch hoch',
    'alert.connection_elevated' => 'Verbindungsauslastung erhöht',
    'alert.aborted_elevated' => 'Rate abgebrochener Verbindungen erhöht',
    'alert.thread_elevated' => 'Anzahl laufender Threads erhöht',
    'alert.slow_query_time' => 'Langsame-Abfrage-Zeit auf {seconds}s gesetzt',
    'alert.connections_at' => 'Verbindungen bei {pct}% des Maximums ({connected}/{max})',
    'alert.connection_error_one' => '{count} Verbindungsfehler erkannt',
    'alert.connection_error_other' => '{count} Verbindungsfehler erkannt',
    'alert.threshold_ratio_connection_warning' => 'Warnschwelle für Verbindungen muss zwischen 0.0 und 1.0 liegen',
    'alert.threshold_ratio_connection_critical' => 'Kritische Schwelle für Verbindungen muss zwischen 0.0 und 1.0 liegen',
    'alert.threshold_ratio_thread_running' => 'Schwelle für laufende Threads muss zwischen 0.0 und 1.0 liegen',
    'alert.threshold_aborted_rate' => 'Schwelle der Abbruchrate muss >= 0.0 sein',
    'alert.threshold_slow_query' => 'Schwelle für langsame Abfragen muss >= 0.0 sein',
    'alert.threshold_connection_errors' => 'Schwelle für Verbindungsfehler muss >= 0 sein',
    'history.err_mkdir' => 'Verlaufsverzeichnis kann nicht erstellt werden: {dir}',
    'history.err_chmod' => 'Verlaufsdatei kann nicht abgesichert werden: {path} (Modus {mode})',
    'history.err_unusable' => 'Verlaufsspeicher ist unbrauchbar: {error} — Verlaufsdatei löschen oder Berechtigungen korrigieren.',
    'query.cancelled' => 'Asynchrone Abfrage abgebrochen',
    'tx.unknown' => 'Unbekannter Befehl — :tx startet, bestätigt oder verwirft eine Transaktion',
    'tx.async_refusal' => ':tx deckt die synchrone Sitzung ab; der asynchrone Transport hat noch keinen koordinierten Transaktionszweig — führen Sie BEGIN/COMMIT explizit aus oder nutzen Sie den synchronen Pfad',
    'tx.already_open' => 'Es ist bereits eine Transaktion offen — :tx commit oder :tx rollback',
    'tx.no_tx' => 'Keine Transaktion offen — :tx startet eine',
    'tx.opened' => 'Transaktion begonnen — Anweisungen laufen bis :tx commit oder :tx rollback mit',
    'tx.committed' => 'Transaktion bestätigt',
    'tx.rolled_back' => 'Transaktion zurückgerollt',
    'tx.arm_rollback' => 'ROLLBACK verwirft alle Änderungen seit BEGIN — zur Bestätigung erneut die Ausführtaste drücken',
    'tx.failed' => 'Transaktionsbefehl fehlgeschlagen: {message}',
    'history.none' => 'Nichts zu lesen — der Statusverlauf zeichnet nur MySQL-artige Sitzungen auf (optional mit --history)',
    'history.empty' => 'Keine Stichproben in den letzten {minutes} Minuten — der Trend braucht mindestens zwei',
    'history.unreadable' => 'Verlaufsspeicher unlesbar: {message}',
    'history.status' => 'Verlaufstrend — {samples} Zeilen über die letzten {minutes} Minuten',
    'query.timed_out' => 'Asynchrone Abfrage nach {seconds}s abgelaufen',
    'reports.err_meta_missing' => 'Berichts-Metadatendatei nicht gefunden: {path}',
    'reports.err_meta_read' => 'Berichts-Metadatendatei konnte nicht gelesen werden: {path}',
    'reports.err_not_in_catalog' => 'Bericht nicht im Katalog gefunden: {name}',
    'reports.err_view_unavailable' => 'Sicht auf diesem Server nicht verfügbar: {name}',
    'reports.err_loading' => 'Fehler beim Laden des Berichts: {error}',
    'variables.err_meta_missing' => 'Variablen-Metadatendatei nicht gefunden: {path}',
    'variables.err_meta_read' => 'Variablen-Metadatendatei konnte nicht gelesen werden: {path}',
    'resilience.statement_timeout_default' => 'Zeitüberschreitung bei der Ausführung der Anweisung',
    'resilience.statement_timeout_after' => 'Zeitüberschreitung bei der Ausführung der Anweisung nach {seconds} s',
    'variables.err_prepare' => 'Die Anweisung konnte auf dieser Verbindung nicht vorbereitet werden',
    'variables.err_not_editable' => 'Variable nicht bearbeitbar',
    'variables.err_persist_mariadb' => 'Persistieren erfordert MariaDB 11.1+ — dieser Server implementiert SET PERSIST nicht.',
    'variables.err_persist_mysql' => 'Persistieren erfordert MySQL 8.0.16+ — dieser Server implementiert SET PERSIST nicht.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: leerer Benutzername — Verbindung zu MySQL als geratene Identität „root“ verweigert; expliziten Benutzer angeben',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: leerer Benutzername — Verbindung zu PostgreSQL als geratene Identität „postgres“ verweigert; expliziten Benutzer angeben',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: unbekannter sslmode „{mode}“; erwartet: {allowed} — ein stillschweigend verworfener Tippfehler würde auf das Klartext-fähige „prefer“ herabstufen',
    'store.err_read' => 'Verbindungsdatei kann nicht gelesen werden: {path} (löschen oder Berechtigungen korrigieren)',
    'store.err_json' => 'Die Verbindungsdatei ist kein gültiges JSON ({error}): {path}',
    'store.err_shape' => 'Die Verbindungsdatei muss ein JSON-Objekt mit einer „connections“-Map sein: {path}',
    'store.err_connections_type' => 'Der Wert „connections“ muss ein JSON-Objekt Name -> Einstellungen sein: {path}',
    'store.err_unknown' => 'Unbekannte Verbindung \'{name}\' — gespeicherte Verbindungen: {saved}',
    'store.none' => '(keine)',
    'store.err_pass_env_unset' => 'Verbindung \'{name}\' liest ihr Passwort aus ${var} — vor dem Verbinden exportieren',
    'store.err_mkdir' => 'Verbindungsverzeichnis kann nicht erstellt werden: {dir}',
    'store.err_write' => 'Verbindungsdatei kann nicht geschrieben werden: {path}',
    'store.err_replace' => 'Verbindungsdatei kann nicht ersetzt werden: {path}',
    'store.err_entry_type' => 'Verbindung \'{name}\' muss auf ein JSON-Objekt mit Einstellungen abbilden',
    'store.err_plaintext_pass' => 'Verbindung \'{name}\' speichert ein Klartext-\'pass\' — connections.json enthält nie Geheimnisse: durch "pass-env": "VAR_NAME" ersetzen',
    'store.err_unknown_setting' => 'Verbindung \'{name}\' hat eine unbekannte Einstellung \'{field}\' — erlaubt: {allowed}',
    'store.err_setting_type' => 'Einstellung \'{field}\' der Verbindung \'{name}\' muss eine Zeichenkette oder Zahl sein',
    'store.err_setting_empty' => 'Einstellung \'{field}\' der Verbindung \'{name}\' darf nicht leer sein',
    'store.err_needs_driver' => 'Verbindung \'{name}\' benötigt einen \'driver\'',
    'store.err_driver' => 'Verbindung \'{name}\' verwendet einen nicht unterstützten Treiber \'{driver}\' — wählen Sie: {allowed}',
    'store.err_port' => 'Einstellung \'port\' der Verbindung \'{name}\' muss eine Zahl sein',
    'store.err_pass_env' => 'Einstellung \'pass-env\' der Verbindung \'{name}\' muss ein Umgebungsvariablenname sein',
    'store.err_name' => 'Ungültiger Verbindungsname \'{name}\' — Buchstaben, Ziffern, Punkt, Bindestrich oder Unterstrich verwenden (1-64 Zeichen)',
    'store.err_secure' => '{path} kann nicht abgesichert werden (Modus {mode})',
    'db.err_dsn_semicolon' => 'Datenbank-Host und -Name dürfen kein \';\' enthalten — es ist das Optionstrennzeichen des PDO-DSN',
    'db.err_unknown_pg_ssl' => 'Unbekannter PostgreSQL-ssl-mode: {mode}',
    'db.err_unknown_mysql_ssl' => 'Unbekannter MySQL-ssl-mode: {mode}',
    'db.err_pg_driver' => 'Verbindung zu einem Nicht-PostgreSQL-Treiber mit dem PostgreSQL-Konnektor nicht möglich',
    'db.err_mysql_driver' => 'Der MySQL-Konnektor akzeptiert nur den Treiber mysql (erhalten: \'{driver}\')',
    'db.err_reconnect' => 'Wiederverbindung nach Verbindungsfehler fehlgeschlagen: {error}',
    'db.err_quote_no_connection' => 'Ohne Verbindung kann nicht maskiert werden',
    'dml.err_key_required' => 'ein Zeilenschlüssel ist erforderlich: Anweisung, die mehr als eine Zeile betreffen könnte, wird abgelehnt',
    'dml.err_key_null' => 'Schlüsselspalte {column} ist NULL: die Zeile kann nicht adressiert werden',
    'dml.err_empty_table' => 'Tabellenname darf nicht leer sein',
    'dml.err_empty_column' => 'Spaltenname darf nicht leer sein',
    'dml.err_empty_key_column' => 'Name der Schlüsselspalte darf nicht leer sein',
    'status.already_saved' => 'bereits gespeichert ({count})',
    'status.not_in_favorites' => 'nicht in den Favoriten ({count})',
    'status.gtid_mariadb' => 'GTID_MODE ist nicht verfügbar: MariaDB hat keine Variable gtid_mode.',
    'status.gtid_version' => 'GTID_MODE erfordert MySQL 5.7.6 oder neuer (Server ist {version}).',
    'caption.blocks_hit' => 'Block-Treffer',
    'caption.blocks_read' => 'Gelesene Blöcke',
    'caption.buffer_pool_read_reqs' => 'Buffer-Pool-Leseanfragen',
    'caption.buffer_pool_usage' => 'Buffer-Pool-Auslastung',
    'caption.buffer_pool_write_reqs' => 'Buffer-Pool-Schreibanfragen',
    'caption.bytes_in' => 'Eingehende Bytes',
    'caption.bytes_out' => 'Ausgehende Bytes',
    'caption.cache_hit_rate' => 'Cache-Trefferquote',
    'caption.commits' => 'Commits',
    'caption.connections' => 'Verbindungen',
    'caption.deadlocks' => 'Deadlocks',
    'caption.disk_reads_not_from_pool' => 'Datenträgerlesezugriffe (nicht aus dem Pool)',
    'caption.disk_write_requests' => 'Datenträger-Schreibanfragen',
    'caption.doublewrite_writes' => 'Doublewrite-Schreibvorgänge',
    'caption.innodb_disk_reads' => 'InnoDB-Datenträgerlesezugriffe',
    'caption.innodb_disk_writes' => 'InnoDB-Datenträgerschreibzugriffe',
    'caption.insert_buffer' => 'Insert-Puffer',
    'caption.pages_created' => 'Erstellte Seiten',
    'caption.pages_flushed' => 'Geschriebene Seiten',
    'caption.pages_read' => 'Gelesene Seiten',
    'caption.read_ahead' => 'Read-Ahead',
    'caption.redo_log_bytes_written' => 'Geschriebene Redo-Log-Bytes',
    'caption.redo_log_writes' => 'Redo-Log-Schreibvorgänge',
    'caption.rollbacks' => 'Rollbacks',
    'caption.row_lock_time' => 'Zeilensperrzeit',
    'caption.row_lock_waits' => 'Zeilensperren-Wartevorgänge',
    'caption.shared_buffers' => 'Shared Buffers',
    'caption.sql_statements' => 'SQL-Anweisungen',
    'caption.table_open_cache' => 'Table-Open-Cache',
    'caption.temp_bytes' => 'Temporäre Bytes',
    'caption.temp_files' => 'Temporäre Dateien',
    'caption.transactions' => 'Transaktionen',
    'caption.tuples_fetched' => 'Abgerufene Tupel',
    'caption.tuples_returned' => 'Zurückgegebene Tupel',
    'caption.traffic' => 'Datenverkehr',
    'caption.key_efficiency' => 'Schlüsseleffizienz',
    'caption.selects_per_second' => 'Selects pro Sekunde',
    'caption.innodb_buffer_usage' => 'InnoDB-Pufferauslastung',
    'caption.innodb_reads_per_second' => 'InnoDB-Lesezugriffe pro Sekunde',
    'caption.innodb_writes_per_second' => 'InnoDB-Schreibzugriffe pro Sekunde',
    'widget.tip.bytes_in' => 'Eingehender Datenverkehr: {Bytes_received} Bytes gesamt',
    'widget.tip.bytes_out' => 'Ausgehender Datenverkehr: {Bytes_sent} Bytes gesamt',
    'widget.tip.client_connections' => 'Client-Verbindungen: {Threads_connected}',
    'widget.tip.connections_level' => 'Verbindungsniveau vs. max_connections',
    'widget.tip.table_open_cache' => 'Trefferquote des Table-Open-Cache',
    'widget.tip.sql_statements' => 'SQL-Anweisungsraten: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'InnoDB-Buffer-Pool-Auslastung in Prozent (bytebasiert)',
    'widget.tip.buffer_pool_read_reqs' => 'InnoDB-Buffer-Pool-Leseanfragen pro Sekunde',
    'widget.tip.buffer_pool_write_reqs' => 'InnoDB-Buffer-Pool-Schreibanfragen pro Sekunde',
    'widget.tip.disk_reads_not_from_pool' => 'InnoDB-Buffer-Pool-Lesezugriffe von der Platte pro Sekunde',
    'widget.tip.innodb_disk_writes' => 'Auf Datenträger geschriebene InnoDB-Datenbytes pro Sekunde',
    'widget.tip.disk_write_requests' => 'InnoDB-Datendatei-Schreibanfragen pro Sekunde',
    'widget.tip.innodb_disk_reads' => 'Vom Datenträger gelesene InnoDB-Datenbytes pro Sekunde',
    'widget.tip.row_lock_waits' => 'InnoDB-Zeilensperren-Wartevorgänge pro Sekunde',
    'widget.tip.row_lock_time' => 'InnoDB-Zeilensperrzeit pro Sekunde (Millisekunden)',
    'widget.tip.pages_flushed' => 'InnoDB-Seiten pro Sekunde geschrieben',
    'widget.tip.pages_created' => 'InnoDB-Seiten pro Sekunde erstellt',
    'widget.tip.pages_read' => 'InnoDB-Seiten pro Sekunde gelesen',
    'widget.tip.insert_buffer' => 'Größe des InnoDB-Insert-Puffers (Seiten)',
    'widget.tip.read_ahead' => 'InnoDB-Buffer-Pool-Read-Ahead pro Sekunde',
    'widget.tip.redo_log_bytes_written' => 'InnoDB-Redo-Log-Bytes pro Sekunde geschrieben',
    'widget.tip.redo_log_writes' => 'InnoDB-Redo-Log-Schreibvorgänge pro Sekunde',
    'widget.tip.doublewrite_writes' => 'InnoDB-Doublewrite-Schreibvorgänge pro Sekunde',
    'widget.tip.alter_rate' => 'ALTER-Befehlsrate (alle Com_alter_*-Verben)',
    'widget.tip.create_rate' => 'CREATE-Befehlsrate (alle Com_create_*-Verben)',
    'widget.tip.drop_rate' => 'DROP-Befehlsrate (alle Com_drop_*-Verben)',
    'widget.tip.select_rate' => 'SELECT-Rate',
    'widget.tip.insert_rate' => 'INSERT-Rate',
    'widget.tip.update_rate' => 'UPDATE-Rate',
    'widget.tip.delete_rate' => 'DELETE-Rate',
    'widget.tip.tuples_fetched_total' => 'Aus der DB abgerufene Zeilen: {tup_fetched} gesamt',
    'widget.tip.tuples_fetched_rate' => 'Zeilenabrufrate',
    'widget.tip.tuples_returned_total' => 'An den Client zurückgegebene Zeilen: {tup_returned} gesamt',
    'widget.tip.tuples_returned_rate' => 'Zeilenrückgaberate',
    'widget.tip.blocks_read_total' => 'Gelesene Datenträgerblöcke: {blks_read} gesamt',
    'widget.tip.blocks_hit_total' => 'Puffer-Cache-Treffer: {blks_hit} gesamt',
    'widget.tip.active_backends' => 'Aktive Backends: {numbackends}',
    'widget.tip.transaction_rates' => 'Transaktionsraten: Commits / Rollbacks',
    'widget.tip.commit_rate' => 'Transaktions-Commit-Rate',
    'widget.tip.rollback_rate' => 'Transaktions-Rollback-Rate',
    'widget.tip.tuple_insert_rate' => 'Tupel-Einfügerate',
    'widget.tip.tuple_update_rate' => 'Tupel-Aktualisierungsrate',
    'widget.tip.tuple_delete_rate' => 'Tupel-Löschrate',
    'widget.tip.deadlock_rate' => 'Deadlock-Rate',
    'widget.tip.shared_buffers' => 'PostgreSQL-Konfiguration shared_buffers (Buffer-Pool-Größe)',
    'widget.tip.cache_hit_rate' => 'Puffer-Cache-Trefferquote: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Erstellungsrate temporärer Dateien',
    'widget.tip.temp_bytes' => 'Pro Sekunde in temporäre Dateien geschriebene Bytes',
    'dash.data_written' => 'Daten geschrieben {kb} kB/s',
    'dash.writes_ops' => 'Schreibvorgänge {n} Ops/s',
    'dash.log_written' => 'Log geschrieben {kb} kB/s',
    'dash.read_reqs' => 'Leseanfragen {n} Seiten/s',
    'dash.write_reqs' => 'Schreibanfragen {n} Seiten/s',
    'dash.disk_reads' => 'Datenträgerlesezugriffe {n} /s',
    'metrics.busy_proxy' => 'Auslastungs-Proxy {pct}%',
    'metrics.cpu_load' => 'CPU-Last {load}',
    'metrics.no_samples' => 'noch keine Messwerte',
    'metrics.connections_count' => '{count} Verbindungen',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Servermetriken',
    'explain.query_block' => 'ABFRAGEBLOCK #{id} (Kosten: {cost})',
    'variables.footer_tree' => '[j/k] Baum',
    'variables.footer_rows' => '[j/k] Zeilen',
    'variables.footer_categories' => '[c] Kategorien',
    'variables.footer_edit' => '[Enter/e] bearbeiten (dynamisch)',
    'variables.footer_rw_filter' => '[w] RW-Filter',
    'variables.footer_search' => '[s] suchen',
    'variables.footer_back' => '[q] zurück',
    'srv.title' => 'Serverstatus',
    'srv.features' => 'Funktionen',
    'srv.stored_programs' => 'Gespeicherte Programme',
    'srv.directories' => 'Verzeichnisse',
    'srv.data_directory' => 'Datenverzeichnis',
    'srv.temp_directory' => 'Temp-Verzeichnis',
    'srv.log_directory' => 'Log-Verzeichnis',
    'srv.pid_file' => 'PID-Datei',
    'srv.tls_active' => 'TLS aktiv',
    'srv.tls_cipher' => 'TLS-Cipher',
    'srv.tls_version' => 'TLS-Version',
    'srv.ssl_support' => 'SSL-Unterstützung',
    'srv.ssl_ca' => 'SSL-CA',
    'srv.ssl_cert' => 'SSL-Zertifikat',
    'srv.ssl_key' => 'SSL-Schlüssel',
    'srv.tls_secure' => 'TLS / sichere Verbindung',
    'srv.replication' => 'Replikation',
    'srv.repl_denied' => 'Unzureichende Berechtigungen (REPLICATION CLIENT)',
    'srv.repl_error' => 'Fehler beim Zugriff auf den Replikationsstatus',
    'srv.not_configured' => 'Nicht konfiguriert',
    'srv.channel' => 'Kanal',
    'srv.source_host' => 'Quell-Host',
    'srv.source_port' => 'Quell-Port',
    'srv.replica_io' => 'Replica-IO läuft',
    'srv.replica_sql' => 'Replica-SQL läuft',
    'srv.seconds_behind' => 'Sekunden hinter der Quelle',
    'srv.relay_log_file' => 'Relay-Log-Datei',
    'srv.relay_pos' => 'Relay-Pos.',
    'srv.repl_channel_title' => 'Replikation › {channel}',
    'srv.default' => 'Standard',
    'srv.firewall' => 'Firewall',
    'srv.mysql_firewall' => 'MySQL-Firewall',
    'srv.caught_up' => '0 s (aufgeholt)',
    'srv.host' => 'Host',
    'srv.socket' => 'Socket',
    'srv.port' => 'Port',
    'srv.version' => 'Version',
    'srv.uptime' => 'Betriebszeit',
    'srv.running_since' => 'Läuft seit',
    'srv.unknown' => 'Unbekannt',
    'srv.server_information' => 'Serverinformationen',
    'perf.tab_easy_setup' => 'Einfache Einrichtung',
    'perf.tab_instruments' => 'Instrumente',
    'perf.tab_consumers' => 'Consumer',
    'perf.tab_actors' => 'Akteure',
    'perf.tab_objects' => 'Objekte',
    'perf.tab_threads' => 'Threads',
    'perf.tab_options' => 'Optionen',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[SCHREIBGESCHÜTZT]',
    'perf.nothing_to_commit' => 'Nichts zu übernehmen',
    'perf.commit_planning_failed' => 'Planung der Übernahme fehlgeschlagen: {error}',
    'perf.err_prepare' => 'Vorbereiten der Anweisung fehlgeschlagen: {sql}',
    'perf.committed_one' => '{count} Anweisung übernommen',
    'perf.committed_other' => '{count} Anweisungen übernommen',
    'perf.applied_one' => '{label} angewendet — {count} Anweisung',
    'perf.applied_other' => '{label} angewendet — {count} Anweisungen',
    'perf.commit_failed' => '{label} fehlgeschlagen bei Anweisung {position} von {total}: {error} — frühere Anweisungen wurden angewendet, der Serverzustand ist unvollständig.',
    'perf.err_access_denied' => 'Zugriff verweigert: erforderliche Berechtigungen fehlen',
    'perf.err_not_enabled' => 'Performance Schema ist nicht aktiviert',
    'perf.err_cannot_connect' => 'Verbindung zur Datenbank nicht möglich',
    'perf.state_fully' => 'Vollständig aktiviert',
    'perf.state_default' => 'Standardeinrichtung',
    'perf.state_custom' => 'Benutzerdefinierte Einrichtung',
    'perf.state_disabled' => 'Deaktiviert',
    'perf.state_unknown' => 'Unbekannt',
    'perf.current_state' => 'Aktueller Zustand: {state}',
    'perf.readonly_note' => '(Schreibgeschützter Modus – keine Berechtigung zum Ändern)',
    'perf.keys_hint' => 'Taste "[" Performance Schema vollständig aktivieren   Taste "]" Deaktivieren   Taste "." Auf Standard zurücksetzen',
    'perf.default_instruments' => 'Standardinstrumente: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Standard-Consumer: events_statements_history, events_waits_history usw.',
    'perf.none_instruments' => '(keine Instrumente verfügbar)',
    'perf.none_consumers' => '(keine Consumer verfügbar)',
    'perf.none_actors' => '(keine Akteure konfiguriert)',
    'perf.none_objects' => '(keine Objektregeln konfiguriert)',
    'perf.none_threads' => '(keine Threads verfügbar)',
    'perf.total_instruments_one' => 'Gesamt: {count} Instrument',
    'perf.total_instruments_other' => 'Gesamt: {count} Instrumente',
    'perf.total_consumers_one' => 'Gesamt: {count} Consumer',
    'perf.total_consumers_other' => 'Gesamt: {count} Consumer',
    'perf.total_actors_one' => 'Gesamt: {count} Akteur',
    'perf.total_actors_other' => 'Gesamt: {count} Akteure',
    'perf.total_objects_one' => 'Gesamt: {count} Objektregel',
    'perf.total_objects_other' => 'Gesamt: {count} Objektregeln',
    'perf.total_threads_one' => 'Gesamt: {count} Thread',
    'perf.total_threads_other' => 'Gesamt: {count} Threads',
    'perf.col_thread_id' => 'Thread-ID',
    'perf.col_name' => 'Name',
    'perf.col_type' => 'Typ',
    'perf.col_user' => 'Benutzer',
    'perf.timer_options' => 'Timer-Optionen',
    'perf.none_timers' => '(keine Timer verfügbar – schreibgeschützt)',
    'perf.col_timer_name' => 'Timer-Name',
    'perf.col_implementation' => 'Implementierung',
    'perf.col_scale_factor' => 'Skalierungsfaktor',
    'perf.timer_readonly_note' => 'Die Timer-Konfiguration ist schreibgeschützt (durch den Server-Build bestimmt)',
    'perf.footer_nav' => '[j/k] Navigation  [Space] umschalten',
    'perf.footer_actions' => '  [c] Übernahme vormerken ([y] ausführen)  [r] zurücksetzen',
    'perf.footer_back' => '  [q] zurück',
    'perf.pending_one' => 'Ausstehend: {count} Änderung',
    'perf.pending_other' => 'Ausstehend: {count} Änderungen',
    'cli.err_not_tty' => 'Dieser Starter steuert ein interaktives Terminal und verweigert ANSI-Ausgabe in eine Pipe (Exit 2) — in einem pty ausführen (z. B. script -qec \'candy-sql app.sqlite\' /dev/null) oder headless aus Bibliothekscode rendern (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'Verlaufsaufzeichnung DEAKTIVIERT ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' verlangt verifiziertes TLS — der ReactPHP-Admin-Transport kann das nicht erfüllen, daher ist das Admin-Live-Refresh DEAKTIVIERT und Abfragen/Tabellenladevorgänge laufen synchron über die TLS-Verbindung.',
    'cli.no_connection_selected' => 'keine Verbindung ausgewählt — Pfad, DSN oder --connect=<name> angeben',
    'cli.usage_line' => 'VERWENDUNG: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # für vollständige Verwendungshinweise',
    'cli.tip_saved_connections' => '       Tipp: benannte Verbindungen in {path} speichern und mit --connect=<name> starten.',
    'help.summary' => 'candy-sql - Terminal-SQL-Client für SQLite, MySQL und PostgreSQL',
    'help.usage' => 'VERWENDUNG
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGUMENTE
  path-or-dsn
    Pfad der SQLite-Datenbankdatei, z. B.: app.sqlite
    Oder eine DSN-Zeichenkette, z. B.:  mysql://user:pass@localhost:3306/dbname

  Ohne jedes Argument: In einem interaktiven Terminal mit gespeicherten
  Verbindungen bietet eine Auswahl diese an; andernfalls wird eine kurze
  Verwendungszeile ausgegeben und der Prozess endet mit Status 2
  (ungültiger Aufruf) — mit --help erscheint diese vollständige Ansicht.',
    'help.options' => 'OPTIONEN
  --help, -h
    Diese Hilfe anzeigen.

  --version, -V
    Die Versionsnummer anzeigen.

  --connect=<name>
    Den benannten Eintrag aus der Datei mit den gespeicherten Verbindungen
    ($XDG_CONFIG_HOME/candy-sql/connections.json, ersatzweise
    $HOME/.config/candy-sql/connections.json) wählen. Nicht mit
    expliziten Verbindungsargumenten kombinierbar. Passwörter werden nie
    auf der Platte gespeichert: Ein Eintrag nennt sein Geheimnis über
    "pass-env", die Variable wird beim Verbindungsaufbau gelesen.
    Siehe GESPEICHERTE VERBINDUNGEN weiter unten.',
    'help.option_dsn' => '  --dsn=<dsn>
    Vollständige DSN-Verbindungszeichenkette.
    Format: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Beispiele:
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
    Einzelne Verbindungsparameter (alle außer --driver und --db sind optional).
    --driver muss eines sein von: sqlite, mysql, pgsql
    --ssl-mode ist für MySQL standardmäßig "prefer". "require" und "verify_*"
    erzwingen TLS mit Prüfung des Serverzertifikats; das CA-Vertrauen muss
    serverseitig oder über my.cnf konfiguriert werden (siehe README). In diesen
    geprüften Modi wird der unverschlüsselungsfähige ReactPHP-Transport
    verweigert — Abfragen laufen synchron über die TLS-Verbindung und die
    Live-Aktualisierung der Verwaltung ist deaktiviert (ein Starthinweis und
    die Statuszeile der App weisen darauf hin).
    CANDY_SQL_PASS ist --pass= vorzuziehen, damit das Passwort nicht in der
    Prozess-Kommandozeile (sichtbar in ps) steht.',
    'help.examples' => 'BEISPIELE
  # SQLite (Standard — einfacher Pfad oder DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL per DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL über einzelne Optionen
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'GESPEICHERTE VERBINDUNGEN
  Benannte Server einmal auflisten, danach immer direkt verbinden:

    $HOME/.config/candy-sql/connections.json  (Verzeichnis 0700, Datei 0600)',
    'help.saved_fields' => '  Felder: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Jedes andere Feld wird abgelehnt, und ein Klartextfeld "pass"
  wird immer abgelehnt — stattdessen pass-env auf eine Variable zeigen
  lassen. Ein Start ohne Argumente in einem interaktiven Terminal zeigt
  diese Einträge als Auswahl; --connect=<name> verbindet direkt.',
    'help.editing' => 'BEARBEITEN
  Der Rows-Bereich einer TABLE-Vorschau ist ein bearbeitbares Raster: h/l
  (oder ←/→) bewegen den Zellcursor, und drei Tastenfolgen ändern Daten —
  jede zeigt die genaue Anweisung in der Statuszeile und schreibt erst
  nach Bestätigung mit Enter:

    e   die Zelle unter dem Cursor bearbeiten (Ctrl+N setzt NULL)
    o   eine Zeile einfügen (Enter springt durch die Spalten; eine leere
        Eingabe überlässt die Spalte ihrem DEFAULT, Ctrl+N speichert NULL)
    D   die Zeile unter dem Cursor löschen

  Zeilen werden nur über ihren vollständigen PRIMARY KEY adressiert: Eine
  Tabelle ohne einen, ein NULL-/Binärschlüssel, eine Binärzelle und jedes
  Abfrageergebnis werden mit einer benannten Statuszeile abgelehnt. Werte
  sind gebundene Parameter; jeder Schreibvorgang läuft in einer Transaktion,
  die zurückgerollt wird, wenn nicht genau eine Zeile betroffen war (ein
  UPDATE, das den bereits vorhandenen Wert schreibt, wird als unverändert
  gemeldet). Esc bricht eine offene Bearbeitungszeile ab.',
    'help.editing_readonly' => '  --read-only
    Schaltet die Bearbeitungstasten für die Sitzung ab. Das schützt vor
    Ausrutschern; Anweisungen im Query-Bereich werden nicht gefiltert —
    für eine harte Garantie als schreibgeschützter Datenbankbenutzer
    verbinden.',
    'help.history' => 'VERLAUF
  Standardmäßig wird jeder Admin-Poll-Schnappschuss als Offline-
  Prüfspur in $HOME/.candy-sql/history.db (Verzeichnis 0700 / Datei 0600)
  aufgezeichnet. Die Seiten Dashboard und Reports in der App lesen
  diese Datei nicht — sie fragen den Live-Server ab.',
    'help.history_flags' => '  --no-history
    Die Aufzeichnung vollständig abschalten (es wird nichts auf die Platte geschrieben).

  --history=<path>
    Schnappschüsse unter <path> statt am Standardort speichern.',
    'help.keyboard' => 'TASTENKÜRZEL (TUI)
  Tab          Fokus durch die Bereiche wechseln (Tables → Rows → Query → Admin)
  ↑ / ↓        In Listenzeilen navigieren (oder j/k) — im Tables-Bereich bewegt sich
               nur die Auswahl; es wird nie eine Vorschau geladen
  /            (Tables-Bereich) eine Live-Filterzeile über die Tabellennamen öffnen;
               Buchstaben erweitern sie, ↑/↓ bewegen den Cursor, Enter übernimmt und
               lädt, Backspace korrigiert, Esc verwirft und leert
  Enter        Zeilen der gewählten Tabelle laden
  Space        Zeilen der gewählten Tabelle laden (Alias für Enter)
  PgDn / PgUp  (Tables-/Rows-Bereich, in einer Tabellenvorschau) die Vorschau in
               100-Zeilen-Fenstern blättern — die Anzeigegrenze wurde zur Seitengröße
  Ctrl+Enter   Die aktuelle Abfrage ausführen
  Ctrl+R       Die aktuelle Abfrage ausführen
  Ctrl+E       Die aktuelle Abfrage ausführen
  EXPLAIN …    (Query-Editor, führendes Schlüsselwort) ein getipptes EXPLAIN läuft
               über ExplainView: der Rows-Bereich zeigt den farbcodierten Planbaum,
               nicht das flache Provider-Raster — und jeder ANALYZE-Zusatz wird
               ENTFERNT, der Inspektor führt nie aus, was er untersucht
  e / o / D    (Rows-Bereich, Tabellenvorschau) die Zelle bearbeiten / eine Zeile
               einfügen / die Zeile löschen — siehe BEARBEITEN; h/l bewegen den Zellcursor
  Ctrl+Space   Am Cursor vervollständigen (Query-Editor): SQL-Schlüsselwörter,
               Tabellennamen nach FROM/JOIN/INTO/UPDATE und Spalten im Kontext
               (WHERE/SELECT/ORDER BY, `alias.` / `table.` qualifiziert, die
               INSERT INTO t (...)-Liste) aus dem geladenen Schema. Ein Kandidat wird
               übernommen, ein gemeinsames Präfix rückt vor, sonst listet die
               Statuszeile die Kandidaten
  Ctrl+F       Die aktuelle Abfrage zur Favoritenliste hinzufügen (nur Sitzungs-
               zustand — kein Bereich zeigt sie an, und sie wird nicht gespeichert)
  Ctrl+Shift+F Die aktuelle Abfrage aus der Favoritenliste entfernen
  1-8          Zu einem Admin-Bereich springen (nur bei fokussiertem Admin-Bereich)
  q            Beenden aus den Bereichen Tables/Rows; aus Admin zurück zu Tables
  x            (Tables/Rows, Live-Ergebnis) die aktuellen Zeilen in einen CSV-
               Pfad exportieren — Überschreibschutz, bis Enter bestätigt
  i            (Tables/Rows) eine CSV-Datei in eine benannte Tabelle importieren
  Ctrl+S       Das Live-Ergebnis bzw. die Vorschau aus jedem Bereich als CSV exportieren
  Esc          Beenden aus den Bereichen Tables/Rows; schließt einen offenen Admin-Dialog
  Backspace    Das Zeichen vor dem Cursor löschen (Query-Editor)',
    'help.see_also' => 'SIEHE AUCH
  https://github.com/sugarcraft/candy-sql',
];
