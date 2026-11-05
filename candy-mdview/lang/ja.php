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
    'glamour.json_invalid' => 'テーマ設定が有効な JSON ではありません',
    'glamour.json_object' => 'テーマ設定は JSON オブジェクトでなければなりません',
    'render.no_input_tty' => '入力なし: ファイルを指定するか Markdown を標準入力にパイプしてください',
    'render.no_input_empty' => '入力なし: 標準入力が空です',
    'render.file_missing' => '{path} を開けません: ファイルが存在しません',
    'render.file_is_directory' => '{path} を開けません: ディレクトリです',
    'render.file_unreadable' => '{path} を開けません: ファイルを読み取れません',
    'render.binary_input' => '{path} をレンダリングできません: テキストファイルではありません',
    'render.input_too_large' => '入力が {limit} バイトを超えています: {path}',
    'render.width_invalid' => '無効な --width 値: {value} (0〜9999 の整数が必要です。0 は折り返し無効)',
];
