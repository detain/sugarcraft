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
];
