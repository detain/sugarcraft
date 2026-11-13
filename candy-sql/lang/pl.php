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
];
