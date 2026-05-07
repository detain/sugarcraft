<?php

/**
 * Japanese translations for candy-shell.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '色が空です',
    'style.unrecognised_color' => '認識できない色：{value}',
    'style.padding_token_int' => "padding/margin トークンは整数である必要があります；取得：'{token}'",
    'style.padding_count'     => 'padding/margin は 1、2、または 4 つの整数が必要です；取得：{count}',
    'style.bad_entry'         => "--style エントリは 'key=value' または 'element.prop=value' である必要があります；取得：'{raw}'",
    'style.unknown_prop'      => "不明なスタイルプロパティ：'{prop}'",
    'process.spawn_failed'    => '子プロセスの起動に失敗',
    'border.unknown'          => '不明なボーダー 스타일：{name}',
    'log.unknown_level'       => '不明なログレベル：{name}',
    'spinner.unknown_style'   => '不明なスピナースタイル：{name}',
    'format.unknown_type'     => '不明な --type：{type}',
    'format.unknown_theme'    => '不明なテーマ：{name}',
];
