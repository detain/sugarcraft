<?php

/**
 * Korean translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation: 파일을 찾을 수 없음: {path}',
    'decoder.no_gd'              => 'sugar-animation: GIF를 디코딩하려면 GD 이미지 확장(ext-gd)을 설치하십시오 - .ansi 아트 재생에는 필요하지 않습니다',
    'decoder.not_gif'            => 'sugar-animation: GIF 파일 아님',
    'decoder.unreadable'         => 'sugar-animation: 파일을 읽을 수 없습니다: {path}',
    'decoder.input_too_large'    => 'sugar-animation: 파일이 너무 큽니다 (최대 {max})',
    'decoder.not_a_file' => 'sugar-animation: 파일이 아님: {path}',
    'decoder.too_many_frames' => 'sugar-animation: 프레임이 너무 많음 (최대 {max})',
    'decoder.total_cells_too_large' => 'sugar-animation: 총 셀 예산 초과 (최대 {max} 셀)',
    'decoder.grid_too_large'     => 'sugar-animation: 셀 그리드가 너무 큽니다 (최대 {max} 셀)',
    'decoder.grid_too_small'     => 'sugar-animation: 셀 그리드 차원은 양수여야 함',
    'decoder.truncated'          => 'sugar-animation: 잘린 GIF',
    'decoder.screen_too_large'   => 'sugar-animation: GIF 화면 크기가 최대값({max} 픽셀) 초과',
    'decoder.screen_too_small'   => 'sugar-animation: GIF 화면 치수는 양수여야 합니다',
    'artlibrary.not_a_directory' => 'sugar-animation: 디렉터리가 아닙니다: {dir}',
    'artlibrary.cannot_list'     => 'sugar-animation: 디렉터리를 나열할 수 없습니다: {dir}',
    'ansiart.cannot_read'        => 'sugar-animation: 아트 파일을 읽을 수 없습니다: {path}',
    'ansiart.read_failed'        => 'sugar-animation: 아트 파일 읽기 실패: {path}',
];
