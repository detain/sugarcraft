<?php

/**
 * Korean translations for candy-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB 구성 요소가 범위를 벗어남 [0,255]: {value}',
    'color.invalid_hex'           => '잘못된 16진수 색상: {hex}',
    'color.ansi_out_of_range'     => 'ANSI 인덱스가 범위를 벗어남 [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256 인덱스가 범위를 벗어남 [0,255]: {index}',
    'ansi.invalid_fg_code'        => '잘못된 16색 전경색 코드: {code}',
    'ansi.invalid_bg_code'        => '잘못된 16색 배경색 코드: {code}',
    'ansi.component_out_of_range' => '{label}이(가) 범위를 벗어남 [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open 실패: {cmd}',
];
