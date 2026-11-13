<?php

/**
 * Korean translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => '사용법: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--lang=CODE] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: composer autoload.php을(를) 찾을 수 없음',
    'cli.bad_flag'    => 'candy-gifview: 알 수 없는 옵션 --{flag}',
    'cli.empty_flag'  => 'candy-gifview: -- 뒤에 옵션 이름이 없습니다',
    'cli.bad_value'   => 'candy-gifview: {flag}의 잘못된 값 \'{value}\' (양수를 입력하세요)',
    'cli.too_many'    => 'candy-gifview: 예상치 못한 인자 \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag}은(는) 값을 받지 않습니다',
    'cli.bad_preset' => 'candy-gifview: 알 수 없는 프리셋 \'{preset}\' (solid 또는 density 필요)',
    'cli.no_frames' => 'candy-gifview: \'{path}\'에 재생할 프레임이 없습니다',
    'cli.cannot_play' => 'candy-gifview: \'{path}\'을(를) 재생할 수 없습니다: {reason}',
    'cli.reason_frames_over' => '애니메이션이 {max} 프레임 한도를 초과합니다. 불러오기를 거부합니다',
    'cli.reason_bytes_over' => '파일이 {max} MiB 한도를 초과합니다',
    'cli.reason_cells_over' => '이 뷰포트에서 애니메이션이 {max}셀 메모리 한도를 초과합니다',
    'cli.duration_static' => 'candy-gifview: --duration에는 최소 두 프레임이 필요합니다. 이 파일은 {count}프레임으로 디코딩됩니다',
    'cli.need_gd' => 'candy-gifview: GIF 디코딩에는 ext-gd가 필요합니다 (php-gd 설치)',
    'cli.decoding' => 'candy-gifview: \'{path}\' 디코딩 중...',
    'cli.truncated' => 'candy-gifview: 첫 {max}프레임만 표시 — 이 뷰어는 더 긴 애니메이션을 읽지 않습니다',
];
