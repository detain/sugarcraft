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
];
