<?php

/**
 * Japanese translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16色インデックスは 0–15 である必要があります：{index}',
    'color.invalid_hex' => '無効な16進色: {hex}',
    'colormath.invalid_xyz' => 'XYZ成分 {key} が存在しないか、有限の数値ではありません',
    'deltae.invalid_lab' => 'Lab配列 {label} に数値成分 "{key}" がありません',
    'deltae.non_finite_lab' => 'Lab配列 {label} の成分 "{key}" が有限値ではありません',
    'distance.euclidean_needs_rgb' => 'ユークリッド距離はRGBバイトを比較します — betweenLab() の代わりに between() を使用してください',
    'nearest.empty_palette' => '空のパレットに対して色を照合できません',
];
