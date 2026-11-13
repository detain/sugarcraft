<?php

/**
 * Korean translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '빈 색상.',
    'style.unrecognised_color' => '인식할 수 없는 색상: {value} — 허용: {allowed}.',
    'style.hex_digit_count' => "16진수 색상은 3자 또는 6자의 16진 숫자여야 합니다(예: '#f80' 또는 '#ff8800'); 입력: '{value}'.",
    'style.padding_token_int' => "Padding/margin 토큰은 정수여야 합니다; 받음: '{token}'.",
    'style.padding_count'     => 'Padding/margin은 1, 2 또는 4개의 정수가 필요합니다; 받음: {count}.',
    'style.bad_entry'         => "--style 항목은 'key=value' 또는 'element.prop=value'이어야 합니다; 받음: '{raw}'.",
    'style.unknown_prop'      => "알 수 없는 스타일 속성: '{prop}'.",
    'process.spawn_failed'    => '하위 프로세스 시작 실패',
    'border.unknown'          => '알 수 없는 테두리 스타일: {name}.',
    'log.unknown_level'       => '알 수 없는 로그 수준: {name}.',
    'log.encode_failed' => 'Log 항목을 json으로 인코딩하지 못함.',
    'log.bad_format' => '잘못된 --format 문자열: {format}.',
    'spinner.unknown_style'   => '알 수 없는 스피너 스타일: {name}.',
    'spinner.windows_unsupported' => 'Spin은 네이티브 Windows에서 명령을 실행할 수 없습니다. 프로세스 백엔드가 지원되지 않습니다. 대신 WSL을 사용하세요.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => '알 수 없는 테마: {name}.',
    'io.read_failed' => '파일을 읽을 수 없음: {path}.',
    'io.write_failed' => '파일에 쓸 수 없음: {path}.',
    'runtime.no_terminal' => '{command}에는 대화형 터미널이 필요합니다. 제어 터미널을 사용할 수 없습니다.',
    'runtime.bad_timeout' => "--timeout 값 '{value}'이(가) 잘못되었습니다 — 0 이상의 초 수 또는 5s/250ms/1m30s 같은 시간 형식을 입력하세요 (0이면 제한 없음).",
    'runtime.bad_number' => "{flag} 값 '{value}'이(가) 올바르지 않습니다 — 음수가 아닌 정수가 필요합니다.",
    'runtime.pcntl_missing' => 'ext-pcntl이 로드되지 않았습니다: SIGINT/SIGTERM은 130/143 종료 코드 매핑 없이 이 프로그램을 종료합니다.',
    'runtime.unknown_choice' => "{option}의 값 '{value}'이(가) 잘못되었습니다. 허용: {allowed}.",
    'hints.choose' => '↑/↓ 이동 · / 필터 · Enter 선택 · Esc 취소',
    'hints.choose.multi' => '↑/↓ 이동 · Space 토글 · / 필터 · Enter 선택 · Esc 취소',
    'hints.filter' => '입력으로 필터 · ↑/↓ 이동 · tab 선택 전환 (복수) · enter 선택 · esc 취소',
    'hints.pager' => '↑/↓ j/k 이동 · ←/→ h/l 좌우 · PgUp/b PgDn/Space 쪽 · Ctrl+U/D 반쪽',
    'hints.pager.more' => 'g/G 또는 Home/End 처음/끝 · q/Esc 종료',
    'format.template_unresolved' => '템플릿: 자리 표시자 {count}개가 비어 있는 값으로 확장됨: {names} — 변수를 설정하고 --allow-env로 허용해야 합니다.',
    'runtime.empty_input' => '입력에 줄이 없습니다. 작업을 수행하지 않습니다.',
    'runtime.empty_input_filter' => '필터할 항목이 없습니다. 표준 입력에 줄이 없습니다.',
    'runtime.empty_input_pager' => '페이지할 항목이 없습니다. 표준 입력에 줄이 없습니다.',
    'runtime.empty_items' => '항목이 없습니다. 인자 또는 --item으로 전달하세요.',
    'runtime.conflicting_flags' => '{first} 및 {second} 플래그는 다른 값과 함께 사용할 수 없습니다.',
    'runtime.empty_selection' => '선택된 항목이 없습니다 — Enter 전에 Space로 최소 한 항목을 표시하십시오.',
    'runtime.legacy_env'        => '{var}은(는) 더 이상 사용되지 않습니다. 대신 {replacement}을(를) 설정하세요.',
];
