<?php

/**
 * Korean translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB 구성 요소가 범위를 벗어남 [0,255]: {value}',
    'color.invalid_hex'           => '잘못된 16진수 색상: {hex}',
    'color.ansi_out_of_range'     => 'ANSI 인덱스가 범위를 벗어남 [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256 인덱스가 범위를 벗어남 [0,255]: {index}',
    'ansi.invalid_fg_code'        => '잘못된 16색 전경색 코드: {code}',
    'ansi.invalid_bg_code'        => '잘못된 16색 배경색 코드: {code}',
    'ansi.component_out_of_range' => '{label}이(가) 범위를 벗어남 [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open 실패: {cmd}',
    'color.parse_empty' => '빈 색 이름은 파싱할 수 없습니다',
    'color.parse_unknown' => '알 수 없는 색 이름: {name}',
    'ansi.invalid_scs_slot' => '잘못된 SCS 슬롯: {slot} (G0-G3은 0-3 예상)',
    'ansi.invalid_scs_designator' => '잘못된 SCS 지정자: {designator} (0x30-0x7E의 한 바이트 예상)',
    'ansi.invalid_sixel_repeat' => 'sixel 반복 횟수는 음수일 수 없습니다: {count}',
    'semaphore.limit_invalid' => '세마포 한계값은 최소 1이어야 합니다. 입력값: {limit}',
    'semaphore.limit_busy' => '세마포가 사용 중입니다: {held} 실행 중, {waiting} 대기 중',
    'semaphore.shutdown' => '허가가 부여되기 전에 세마포가 닫혔습니다',
    'semaphore.closed' => '세마포가 닫혀 있어 더 이상 허가를 부여할 수 없습니다',
    'semaphore.derive_closed' => '닫힌 세마포에서 새 풀을 파생할 수 없습니다',
    'lru_map.capacity_invalid' => 'LRU 용량은 최소 1이어야 합니다. 입력값: {capacity}',
    'lru_map.capacity_too_large' => 'LRU 용량 {capacity}이(가) 증가 가드 {max}를 초과합니다',
    'lru_map.evict_from_empty' => '빈 LRU 맵에서 내쫓을 수 없습니다',
    'errors.negative_not_allowed' => '{name}에 음수 값은 허용되지 않습니다',
    'errors.value_must_be_positive' => '{name}은(는) 양수여야 합니다. 입력값: {value}',
    'errors.value_out_of_range' => '{name}은(는) {min}과(와) {max} 사이여야 합니다. 입력값: {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
];
