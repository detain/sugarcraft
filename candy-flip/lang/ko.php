<?php

/**
 * Korean translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'          => 'candy-flip: 파일을 찾을 수 없음: {path}',
    'decoder.no_gd'            => 'candy-flip: ext-gd 필요',
    'decoder.not_gif'          => 'candy-flip: GIF 파일 아님',
    'decoder.grid_too_large'   => 'candy-flip: 셀 그리드 곱이 최대값({max}) 초과',
    'decoder.grid_too_small'   => 'candy-flip: 셀 그리드 차원은 양수여야 함',
    'decoder.truncated'        => 'candy-flip: 잘린 GIF',
    'decoder.screen_too_large' => 'candy-flip: GIF 화면 크기가 최대값({max} 픽셀) 초과',
    'cli.usage'                => '사용법: candy-flip <gif> [solid|density]',
    'cli.no_autoload'          => 'candy-flip: composer autoload.php을(를) 찾을 수 없음',
];
