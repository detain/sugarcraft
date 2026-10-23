<?php

/**
 * Japanese translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '色が空です',
    'style.unrecognised_color' => '認識できない色：{value}',
    'style.hex_digit_count' => "16進色の桁数は3または6である必要があります（例: '#f80' または '#ff8800'）。取得: '{value}'",
    'style.padding_token_int' => "padding/margin トークンは整数である必要があります；取得：'{token}'",
    'style.padding_count'     => 'padding/margin は 1、2、または 4 つの整数が必要です；取得：{count}',
    'style.bad_entry'         => "--style エントリは 'key=value' または 'element.prop=value' である必要があります；取得：'{raw}'",
    'style.unknown_prop'      => "不明なスタイルプロパティ：'{prop}'",
    'process.spawn_failed'    => '子プロセスの起動に失敗',
    'border.unknown'          => '不明なボーダー 스타일：{name}',
    'log.unknown_level'       => '不明なログレベル：{name}',
    'log.encode_failed' => 'ログエントリのjsonエンコードに失敗しました',
    'log.bad_format' => '無効な --format 文字列: {format}',
    'spinner.unknown_style'   => '不明なスピナースタイル：{name}',
    'format.unknown_type'     => '不明な --type：{type}',
    'format.unknown_theme'    => '不明なテーマ：{name}',
    'io.read_failed' => 'ファイルを読み込めません: {path}',
    'io.write_failed' => 'ファイルに書き込めません: {path}',
    'log.unknown_formatter' => '不明な --formatter: {name}',
    'completion.unknown_shell' => 'サポートされていないシェル: {shell}',
    'confirm.unknown_default' => '不明な --default 値: {value}',
    'cursor_mode.unknown' => '不明なカーソルモード: {mode}',
];
