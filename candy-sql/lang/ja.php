<?php

/**
 * Japanese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite ファイルが見つかりません：{path}',
    'complete.in_literal' => '文字列やコメントの中では補完できません',
    'complete.nothing' => '補完するものがありません — キーワードの先頭を入力してください',
    'complete.none_keyword' => '"{word}" を補完するキーワードはありません',
    'complete.none_table' => '"{word}" を補完するテーブルはありません',
    'complete.none_column' => '"{word}" を補完する列はありません',
    'complete.none_qualified' => '{qualifier} に "{word}" を補完する列はありません',
    'complete.list' => '"{word}" の補完候補 {count} 件: {list}{more}',
    'complete.done' => '補完しました {word} → {insert}',
    'complete.inserted' => '挿入しました {insert}',
];
