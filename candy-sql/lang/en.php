<?php

/**
 * English (default) translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'no such SQLite file: {path}',
    'complete.in_literal' => 'nothing to complete inside a string or comment',
    'complete.nothing' => 'nothing to complete — type the start of a keyword',
    'complete.none_keyword' => 'no keyword completes "{word}"',
    'complete.none_table' => 'no table completes "{word}"',
    'complete.none_column' => 'no column completes "{word}"',
    'complete.none_qualified' => 'no column of {qualifier} completes "{word}"',
    'complete.list' => '{count} complete "{word}": {list}{more}',
    'complete.done' => 'completed {word} → {insert}',
    'complete.inserted' => 'inserted {insert}',
    'edit.refuse_readonly' => 'read-only session — editing is disabled',
    'edit.refuse_no_table' => 'open a table preview first — query results cannot be edited in place',
    'edit.refuse_no_rows' => 'no row under the cursor',
    'edit.refuse_no_pk' => 'this table has no primary key — rows cannot be addressed safely; write the statement in the Query pane',
    'edit.refuse_pk_hidden' => 'this row\'s primary key is NULL or a binary placeholder — it cannot be addressed',
    'edit.refuse_binary' => 'binary or large cells are not editable here — use a statement in the Query pane',
    'edit.refuse_schema' => 'cannot read the schema of {table} — editing refused',
    'edit.prompt_cell' => 'edit {table}.{column} = {value}  (enter previews · ctrl+n NULL · esc cancels)',
    'edit.prompt_insert' => 'new row in {table} — {column} ({type}) [{n}/{total}] = {value}  (enter next · empty = DEFAULT · ctrl+n NULL · esc cancels)',
    'edit.prompt_confirm' => '{sql}  (enter commits · esc cancels)',
    'edit.cancelled' => 'edit cancelled',
    'edit.unchanged' => 'value unchanged — nothing written',
    'edit.done_update' => 'updated 1 row in {table}',
    'edit.done_insert' => 'inserted 1 row into {table}',
    'edit.done_delete' => 'deleted 1 row from {table}',
    'edit.none_changed' => 'no row changed — the value was already there or the row is gone; preview refreshed',
    'edit.failed' => 'write failed — rolled back, nothing changed: {reason}',
    'edit.too_many' => 'refused: the statement would touch {count} rows — rolled back, nothing changed',
];
