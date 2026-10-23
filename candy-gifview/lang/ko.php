<?php

/**
 * Korean translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => '사용법: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: composer autoload.php을(를) 찾을 수 없음',
    'cli.bad_flag'    => 'candy-gifview: 알 수 없는 옵션 --{flag}',
    'cli.bad_value'   => 'candy-gifview: {flag}의 잘못된 값 \'{value}\' (양수를 입력하세요. --speed는 Nfps 또는 max도 허용합니다)',
    'cli.too_many'    => 'candy-gifview: 예상치 못한 인자 \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag}은(는) 값을 받지 않습니다',
];
