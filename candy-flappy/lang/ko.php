<?php

/**
 * Korean translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => '점수: {score}',
    'ui.best'                => ' 최고: {best}',
    'ui.new_best'            => ' 🏆 신기록: {best}',
    'ui.plain_new_best'      => ' 신기록: {best}',
    'ui.crash_full'          => '💥 충돌 — r로 다시 시도, q로 종료',
    'ui.crash_short'         => '💥 충돌 — r 재시도 · q 종료',
    'ui.crash_floor'         => 'r 재시도 · q 종료',
    'ui.ready_full'          => 'space/⏎/↑/w 시작 · r 재시작 · q 종료',
    'ui.ready_short'         => 'space로 시작 · q 종료',
    'ui.live_full'           => 'space/⏎/↑/w 날갯짓 · r 재시작 · q 종료',
    'ui.live_short'          => 'space로 날갯짓 · q 종료',
    'ui.plain_crash_full'    => 'x 충돌 - r로 다시 시도, q로 종료',
    'ui.plain_crash_short'   => 'x 충돌 - r 재시도 - q 종료',
    'ui.plain_crash_floor'   => 'r 재시도 - q 종료',
    'ui.plain_ready_full'    => 'space/Enter/^/w 시작 . r 재시작 . q 종료',
    'ui.plain_ready_short'   => 'space로 시작 . q 종료',
    'ui.plain_live_full'     => 'space/Enter/^/w 날갯짓 . r 재시작 . q 종료',
    'ui.plain_live_short'    => 'space로 날갯짓 . q 종료',
    'ui.quit_hint' => 'q를 눌러 종료합니다.',
    'notty.line1'            => 'candy-flappy에는 대화형 터미널이 필요합니다.',
    'notty.line2'            => '사용할 수 있는 색상 기능을 찾지 못했습니다 (stdout이 TTY가 아니거나 TERM=dumb).',
    'size.notice'            => 'candy-flappy에는 {need} 터미널이 필요합니다; 이 터미널은 {have}입니다.',
    'size.resume'            => '창 크기를 조정하면 바로 여기서 게임이 이어집니다.',
];
