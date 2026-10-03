<?php

/**
 * Simplified Chinese translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16 色索引必须为 0–15，实际：{index}',
    'color.invalid_hex' => '无效的十六进制颜色：{hex}',
    'colormath.invalid_xyz' => 'XYZ 分量 {key} 缺失或不是有限数值',
    'deltae.invalid_lab' => 'Lab 数组 {label} 缺少数值型 "{key}" 分量',
    'deltae.non_finite_lab' => 'Lab 数组 {label} 的 "{key}" 分量不是有限数值',
    'distance.euclidean_needs_rgb' => '欧氏度量比较 RGB 字节 — 请使用 between() 而不是 betweenLab()',
    'nearest.empty_palette' => '无法在空调色板上匹配颜色',
];
