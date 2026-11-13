<?php

/**
 * Russian translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'файл SQLite не найден: {path}',
    'complete.in_literal' => 'внутри строки или комментария нечего дополнять',
    'complete.nothing' => 'нечего дополнять — введите начало ключевого слова',
    'complete.none_keyword' => 'нет ключевого слова для "{word}"',
    'complete.none_table' => 'нет таблицы для "{word}"',
    'complete.none_column' => 'нет столбца для "{word}"',
    'complete.none_qualified' => 'в {qualifier} нет столбца для "{word}"',
    'complete.list' => '{count} вариантов для "{word}": {list}{more}',
    'complete.done' => 'дополнено {word} → {insert}',
    'complete.inserted' => 'вставлено {insert}',
];
