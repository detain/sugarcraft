<?php

/**
 * Korean UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => '점수: %d
줄:   %d
레벨: %d',
    'help_card'  => '이동
시계 방향 회전
반시계 방향 회전
소프트 드롭
하드 드롭
홀드
일시 정지
재시작(종료 후)
종료',
    'next_label' => '다음:',
    'hold_label' => '홀드:',
    'paused'     => '[ 일시 정지 — p로 재개 ]',

    // Game-over banner
    'over.title'                => '게임 오버',
    'over.final_score'          => '최종 점수: {points}',
    'over.restart_hint'         => 'r로 재시작 — q로 종료',
    'over.reason'               => '이유: {reason}',
    'over.reason.spawn_blocked' => '다음 조각을 둘 자리가 없습니다',
    'over.reason.top_out'       => '층이 천장에 닿았습니다',
    'over.reason.crushed'       => '가비지에 깔렸습니다',

    // On-frame cues
    'hold_used'  => '(사용함)',
    'combo_line' => '콤보 x%d',
    'b2b_line'   => 'B2B 대기',
    'over.best'    => '최고 점수: {points}',
    'over.new_best' => '신기록!',

    // Versus renderer
    'label.player'   => '플레이어',
    'label.computer' => '컴퓨터',
    'win.player'     => '당신이 이겼습니다!',
    'win.computer'   => '컴퓨터가 이겼습니다!',
    'over.quit_hint' => 'q로 종료',
    'vs_keys'        => '드롭
홀드
정지
재시작
종료',
    'vs.record' => '전적: {wins}승 {losses}패',
    'intro.title' => 'candy-tetris에 오신 것을 환영합니다',
    'intro.goal' => '줄을 지우세요 - 위에 닿으면 끝',
    'intro.press' => '{keys} 눌러 시작',
    'intro.vs' => '대전 모드: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: 알 수 없는 모드 "{flag}"',
    'help_text'    => 'candy-tetris - 테트리스 클론

사용법: candy-tetris [옵션]
  (없음)     1인 게임 시작
  -v, --vs   컴퓨터 대전 모드
  -h, --help 이 도움말 표시
  --version  버전 출력
',
    'cli.no_tty' => 'candy-tetris는 대화형 터미널이 필요합니다. 파이프나 리디렉션이 아닌 TTY에서, TERM을 dumb 이외의 값으로 설정해 실행하세요.',
];
