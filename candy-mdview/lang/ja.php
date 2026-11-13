<?php

/**
 * Japanese translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => '不明なテーマ：{name}',
    'render.theme_config_unreadable' => 'テーマ設定が読み取れません：{path}',
    'render.theme_config_too_large' => 'テーマ設定が{limit}バイトを超えています：{path}',
    'render.theme_config_unrecognized' => 'テーマ設定はフラットスキーマにもglamourスキーマにも一致しません: {path}',
    'glamour.json_invalid' => 'テーマ設定が有効な JSON ではありません',
    'glamour.json_object' => 'テーマ設定は JSON オブジェクトでなければなりません',
    'glamour.decoration_unsafe' => 'テーマ {slot} には改行・タブ・スペースのみ使用可能です',
    'glamour.value_too_large' => 'テーマ {key} が上限 {limit} を超えています',
    'render.no_input_tty' => '入力なし: ファイルを指定するか、Markdown を標準入力にパイプするか、--help を実行してください',
    'render.no_input_empty' => '入力なし: 標準入力が空です',
    'render.pager_needs_tty' => 'ページャーにはターミナルが必要です。-p を外して標準出力へレンダリングしてください',
    'render.file_missing' => '{path} を開けません: ファイルが存在しません',
    'render.file_is_directory' => '{path} を開けません: ディレクトリです',
    'render.file_unreadable' => '{path} を開けません: ファイルを読み取れません',
    'render.binary_input' => '{path} をレンダリングできません: テキストファイルではありません',
    'render.input_too_large' => '入力が {limit} バイトを超えています: {path}',
    'render.fetch_failed' => '取得できません {url}: {reason}',
    'render.directory_empty' => '{path} に Markdown ドキュメントがありません: README.md、README.markdown、index.md、単一の *.md を検索しました',
    'render.directory_ambiguous' => '{path} に単一ドキュメントがありません: {found} — どれか1つを明示してください',
    'render.directory_chosen' => 'ディレクトリ引数 {path} から {chosen} をレンダリング中',
    'render.width_invalid' => '無効な --width 値: {value} (0〜9999 の整数が必要です。0 は折り返し無効)',
];
