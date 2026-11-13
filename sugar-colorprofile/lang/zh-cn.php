<?php

/**
 * Simplified Chinese translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16 色索引必须为 0–15，实际：{index}',
    'standard.ansi256_out_of_range' => 'ANSI 256 色索引必须为 0–255，实际：{index}',
    'color.invalid_hex' => '无效的十六进制颜色：{hex}',
    'colormath.invalid_xyz' => 'XYZ 分量 {key} 缺失或不是有限数值',
    'deltae.invalid_lab' => 'Lab 数组 {label} 缺少数值型 "{key}" 分量',
    'deltae.non_finite_lab' => 'Lab 数组 {label} 的 "{key}" 分量不是有限数值',
    'distance.euclidean_needs_rgb' => '欧氏度量比较 RGB 字节 — 请使用 between() 而不是 betweenLab()',
    'nearest.empty_palette' => '无法在空调色板上匹配颜色',
    'probe.unknown_capability' => '未知的功能键: {keys}',
    'nearest.error_shape' => 'withError() 需要一个 3 元素列表 [r, g, b]；收到 {count} 个元素',
    'nearest.error_channel' => 'withError() 累加器通道必须是 int 或 float；收到 {type}',
    'palette_index.metric' => 'PaletteIndex 需要保持距离的度量 (Euclidean 或 Cie76)；{metric} 没有固定的 PCA 基 - 请改用 NearestColor::closest()',
    'stream.invalid_resource' => '{label} 流必须是有效资源',
];
