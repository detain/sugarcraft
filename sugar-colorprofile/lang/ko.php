<?php

/**
 * Korean translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16색 인덱스는 0–15이어야 합니다: {index}',
    'standard.ansi256_out_of_range' => 'ANSI 256색 인덱스는 0–255이어야 합니다: {index}',
    'color.invalid_hex' => '잘못된 16진수 색상: {hex}',
    'colormath.invalid_xyz' => 'XYZ 구성 요소 {key}이(가) 없거나 유한한 수가 아닙니다',
    'deltae.invalid_lab' => 'Lab 배열 {label}에 숫자 "{key}" 구성 요소가 없습니다',
    'deltae.non_finite_lab' => 'Lab 배열 {label}의 "{key}" 구성 요소가 유한하지 않습니다',
    'distance.euclidean_needs_rgb' => '유클리드 거리는 RGB 바이트를 비교합니다 — betweenLab() 대신 between()을 사용하세요',
    'nearest.empty_palette' => '빈 팔레트에서는 색상을 일치시킬 수 없습니다',
    'probe.unknown_capability' => '알 수 없는 케파빌리티 키: {keys}',
    'nearest.error_shape' => 'withError()는 3개 요소 목록 [r, g, b]을(를) 기대합니다. {count}개 요소를 받았습니다',
    'nearest.error_channel' => 'withError() 누적기 채널은 int 또는 float여야 합니다. 받은 유형: {type}',
    'palette_index.metric' => 'PaletteIndex는 거리를 보존하는 측정법(Euclidean 또는 Cie76)이 필요합니다. {metric}에는 고정된 PCA 기저가 없습니다 - 대신 NearestColor::closest()를 사용하세요',
    'stream.invalid_resource' => '{label} 스트림은 유효한 리소스여야 합니다',
];
