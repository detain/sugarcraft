<?php

/**
 * Japanese translations for candy-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB コンポーネントが範囲外 [0,255]：{value}',
    'color.invalid_hex'           => '無効な16進カラー：{hex}',
    'color.ansi_out_of_range'     => 'ANSI インデックスが範囲外 [0,15]：{index}',
    'color.ansi256_out_of_range'  => 'ANSI256 インデックスが範囲外 [0,255]：{index}',
    'ansi.invalid_fg_code'        => '無効な16色前景色コード：{code}',
    'ansi.invalid_bg_code'        => '無効な16色背景色コード：{code}',
    'ansi.component_out_of_range' => '{label} が範囲外 [0,255]：{value}',
    'program.proc_open_failed'    => 'proc_open 失敗：{cmd}',
];
