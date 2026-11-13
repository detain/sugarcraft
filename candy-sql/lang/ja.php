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
    'edit.refuse_readonly' => '読み取り専用セッションです — 編集は無効です',
    'edit.refuse_no_table' => '先にテーブルのプレビューを開いてください — クエリ結果はその場で編集できません',
    'edit.refuse_no_rows' => 'カーソル位置に行がありません',
    'edit.refuse_no_pk' => 'このテーブルには主キーがありません — 行を安全に特定できないため、クエリペインで文を書いてください',
    'edit.refuse_pk_hidden' => 'この行の主キーは NULL かバイナリのプレースホルダーです — 特定できません',
    'edit.refuse_binary' => 'バイナリや大きなセルはここでは編集できません — クエリペインで文を使ってください',
    'edit.refuse_schema' => '{table} のスキーマを読み取れません — 編集を拒否しました',
    'edit.prompt_cell' => '{table}.{column} を編集 = {value}  (Enter でプレビュー · Ctrl+N で NULL · Esc で中止)',
    'edit.prompt_insert' => '{table} に新しい行 — {column} ({type}) [{n}/{total}] = {value}  (Enter で次へ · 空 = DEFAULT · Ctrl+N で NULL · Esc で中止)',
    'edit.prompt_confirm' => '{sql}  (Enter で確定 · Esc で中止)',
    'edit.cancelled' => '編集を中止しました',
    'edit.unchanged' => '値は変わっていません — 何も書き込みませんでした',
    'edit.done_update' => '{table} の 1 行を更新しました',
    'edit.done_insert' => '{table} に 1 行を挿入しました',
    'edit.done_delete' => '{table} から 1 行を削除しました',
    'edit.none_changed' => '変更された行はありません — 値が同じか行が存在しません。プレビューを更新しました',
    'edit.failed' => '書き込みに失敗しました — ロールバック済み、変更はありません: {reason}',
    'edit.too_many' => '拒否しました: この文は {count} 行に影響します — ロールバック済み、変更はありません',
];
