<?php

/**
 * Korean translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => '보드가 너무 작습니다',
    'board.minecount_out_of_range' => '지뢰 수가 범위를 벗어남',
    'board.rows_shape_mismatch' => '그리드 데이터가 {width}×{height} 보드와 일치하지 않습니다',
    'custom.min_rows' => '행은 최소 2개 이상이어야 합니다',
    'custom.min_cols' => '열은 최소 2개 이상이어야 합니다',
    'custom.max_rows' => '행은 최대 50개 이하여야 합니다',
    'custom.max_cols' => '열은 최대 50개 이하여야 합니다',
    'custom.min_mines' => '지뢰는 최소 1개 필요합니다',
    'custom.max_mines' => '지뢰가 너무 많습니다 — 안전한 시작 영역을 남기세요',
    'ui.status' => '지뢰: {mines}  ·  깃발: {flags}  ·  남은: {remaining}  ·  시간: {time}',
    'ui.status_stats' => '{preset}  ·  깃발: {flags}  ·  남은: {remaining}  ·  시간: {time}  ·  최고: {best}',
    'ui.preset_easy' => '초급',
    'ui.preset_standard' => '표준',
    'ui.preset_medium' => '중급',
    'ui.preset_expert' => '전문가',
    'ui.boom' => '💥 펑 — 다시 시작하려면 r',
    'ui.cleared' => '★ 클리어 — 다시 하려면 r',
    'ui.help_rules' => '숫자는 주변 지뢰 수를 뜻함',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  이동  ·  space / enter  열기',
    'ui.help_board' => '왼쪽 클릭  열기 · f / 오른쪽 클릭  깃발 · c / 가운데 클릭  주변 열기',
    'ui.help_session' => 'r  재시작  ·  S  저장  ·  L  불러오기  ·  q / Esc / Ctrl+C  종료',
    'ui.saved' => '게임 저장됨',
    'ui.restarted' => '보드 재시작',
    'ui.loaded' => '세이브 로드됨',
    'ui.no_save' => '로드할 세이브 없음',
    'ui.load_refused' => '세이브 거부됨(읽을 수 없거나 변조됨)',
    'ui.save_failed' => '저장 실패',
    'ui.chord_not_ready' => '주변 열려면 깃발 수가 숫자와 맞아야 함',
    'ui.resume' => '세이브가 기다리는 중 — L로 로드',
    'ui.new_best' => '신기록',
    'ui.beat_best' => '{prev} → 신기록',
    'ui.fit_notice' => 'candy-minesweeper에는 {need}줄 높이의 터미널이 필요합니다. 현재 {have}줄입니다. 창을 키우면 게임이 곧바로 재개됩니다. q로 종료.',
];
