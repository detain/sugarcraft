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
    'score_card' => '점수: {score}
줄:   {lines}
레벨: {level}',
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
    'paused'     => '[ 일시 정지 — {keys}로 재개 ]',

    // Game-over banner
    'over.title'                => '게임 오버',
    'over.final_score'          => '최종 점수: {points}',
    'over.restart_hint'         => '{restart}로 재시작 — {quit}로 종료',
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
    'over.games' => '완료된 게임: {games}',

    // Versus renderer
    'label.player'   => '플레이어',
    'label.computer' => '컴퓨터',
    'win.player'     => '당신이 이겼습니다!',
    'win.computer'   => '컴퓨터가 이겼습니다!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => '줄을 지우면 상대에게 잔해가 내려간다 (구멍 하나)',
    'vs.record' => '전적: {wins}승 {losses}패',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => '나',
    'vs.strip.computer' => '컴퓨터',
    'vs.strip.score' => '점수:',
    'vs.strip.lines' => '줄:',
    'vs.strip.level' => '레벨:',
    'intro.title' => 'candy-tetris에 오신 것을 환영합니다',
    'intro.goal' => '줄을 지우세요 — 위에 닿으면 끝',
    'intro.press' => '{keys} 눌러 시작',
    // candy-tetris-006/-095 (ko): the on-ramp advertises the reachable key reference.
    'intro.keys' => '언제나 {keys}로 키 목록 보기',
    'intro.vs' => '대전 모드: candy-tetris -v',
    'key_or' => ' 또는 ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: 알 수 없는 모드 "{flag}"',
    'help_text'    => 'candy-tetris - 테트리스 클론

사용법: candy-tetris [옵션]
  (없음)     1인 게임 시작
  -v, --vs   컴퓨터 대전 모드
  -h, --help 이 도움말 표시
  --version  버전 출력
',
    // candy-tetris-006/-095 (ko): --help keys section header.
    'help.keys' => '키:',
    // candy-tetris-006/-095 (ko): the press that closes the key reference.
    'help.close' => '한 번 더 {keys}로 닫기',
    // candy-tetris-006/-095 (ko): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => '프레임에는 최소 {cols}x{rows} 터미널이 필요',
    // candy-tetris-006/-095 (ko): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => '{keys} 키 목록',
    'cli.no_tty' => 'candy-tetris는 대화형 터미널이 필요합니다. 파이프나 리디렉션이 아닌 TTY에서, TERM을 dumb 이외의 값으로 설정해 실행하세요.',
    'launch.extras' => 'candy-tetris: 인수 하나만 허용됩니다. 초과 {count}개: {list}',
    'score.save.failed' => 'candy-tetris: 점수를 저장할 수 없습니다 ({problem})',
    'keys.ignored' => 'candy-tetris: 읽을 수 없는 키 파일 무시 ({problem})',
    'scores.ignored' => 'candy-tetris: 읽을 수 없는 점수 파일 무시 ({problem})',
    'config.problem' => '{path}에서: {detail}',
    'cli.refusal' => 'candy-tetris는 대화형 터미널(TTY)이 필요합니다.
색상 없이도 실행됩니다: NO_COLOR를 설정하세요.',
    'cli.too_small' => 'candy-tetris에는 {size} 크기의 터미널이 필요합니다. 현재: {actual}.
창 크기를 바꾸면 여기서 게임이 다시 시작됩니다.',
    'cli.too_small_vs' => 'candy-tetris VS에는 {size} 크기의 터미널이 필요합니다. 현재: {actual}.
창 크기를 바꾸면 대결이 여기서 다시 시작됩니다.',
];
