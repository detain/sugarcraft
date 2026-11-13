<?php

/**
 * Korean translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '빈 색상',
    'style.unrecognised_color' => '인식할 수 없는 색상: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "16진수 색상은 3자 또는 6자의 16진 숫자여야 합니다(예: '#f80' 또는 '#ff8800'); 입력: '{value}'",
    'style.padding_token_int' => "padding/margin 토큰은 정수여야 합니다; 받음: '{token}'",
    'style.padding_count'     => 'padding/margin은 1, 2 또는 4개의 정수가 필요합니다; 받음: {count}',
    'style.bad_entry'         => "--style 항목은 'key=value' 또는 'element.prop=value'이어야 합니다; 받음: '{raw}'",
    'style.unknown_prop'      => "알 수 없는 스타일 속성: '{prop}'",
    'process.spawn_failed'    => '하위 프로세스 시작 실패',
    'border.unknown'          => '알 수 없는 테두리 스타일: {name}',
    'log.unknown_level'       => '알 수 없는 로그 수준: {name}',
    'log.encode_failed' => 'log 항목을 json으로 인코딩하지 못함',
    'log.bad_format' => '잘못된 --format 문자열: {format}',
    'spinner.unknown_style'   => '알 수 없는 스피너 스타일: {name}',
    'spinner.windows_unsupported' => 'spin은 네이티브 Windows에서 명령을 실행할 수 없습니다. 프로세스 백엔드가 지원되지 않습니다. 대신 WSL을 사용하세요.',
    'format.unknown_theme'    => '알 수 없는 테마: {name}',
    'io.read_failed' => '파일을 읽을 수 없음: {path}',
    'io.write_failed' => '파일에 쓸 수 없음: {path}',
    'log.unknown_formatter' => '알 수 없는 --formatter: {name}',
    'completion.unknown_shell' => '지원하지 않는 셸: {shell}',
    'confirm.unknown_default' => '알 수 없는 --default 값: {value}',
    'cursor_mode.unknown' => '알 수 없는 커서 모드: {mode}',
    'runtime.no_terminal' => '{command}에는 대화형 터미널이 필요합니다. 제어 터미널을 사용할 수 없습니다.',
    'runtime.bad_timeout' => '--timeout 값 \'{value}\'이(가) 잘못되었습니다 — 0 이상의 초 수를 입력하세요 (0이면 제한 없음).',
    'runtime.unknown_choice' => '{option}의 값 \'{value}\'이(가) 잘못되었습니다. 허용: {allowed}.',
    'hints.choose' => '↑/↓ 이동 · space 선택 전환 (복수) · enter 선택 · esc 취소',
    'hints.filter' => '입력으로 필터 · ↑/↓ 이동 · tab 선택 전환 (복수) · enter 선택 · esc 취소',
    'hints.pager' => 'space/PgDn 아래 · PgUp/b 위 · g 처음 · G 끝 · q 종료',
    'format.template_unresolved' => '템플릿: 자리 표시자 {count}개가 비어 있는 값으로 확장됨: {names} — 변수를 설정하고 --allow-env로 허용해야 합니다.',
    'runtime.empty_input' => '입력에 줄이 없습니다. 작업을 수행하지 않습니다.',
];
