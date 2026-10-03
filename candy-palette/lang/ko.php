<?php

/**
 * Korean translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16색 인덱스는 0–15이어야 합니다: {index}',
    'color.invalid_hex' => '잘못된 16진수 색상: {hex}',
    'colormath.invalid_xyz' => 'XYZ 구성 요소 {key}이(가) 없거나 유한한 수가 아닙니다',
    'deltae.invalid_lab' => 'Lab 배열 {label}에 숫자 "{key}" 구성 요소가 없습니다',
    'deltae.non_finite_lab' => 'Lab 배열 {label}의 "{key}" 구성 요소가 유한하지 않습니다',
    'distance.euclidean_needs_rgb' => '유클리드 거리는 RGB 바이트를 비교합니다 — betweenLab() 대신 between()을 사용하세요',
    'nearest.empty_palette' => '빈 팔레트에서는 색상을 일치시킬 수 없습니다',
];
