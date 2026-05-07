<?php

/**
 * Korean translations for candy-shell.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '빈 색상',
    'style.unrecognised_color' => '인식할 수 없는 색상: {value}',
    'style.padding_token_int' => "padding/margin 토큰은 정수여야 합니다; 받음: '{token}'",
    'style.padding_count'     => 'padding/margin은 1, 2 또는 4개의 정수가 필요합니다; 받음: {count}',
    'style.bad_entry'         => "--style 항목은 'key=value' 또는 'element.prop=value'이어야 합니다; 받음: '{raw}'",
    'style.unknown_prop'      => "알 수 없는 스타일 속성: '{prop}'",
    'process.spawn_failed'    => '하위 프로세스 시작 실패',
    'border.unknown'          => '알 수 없는 테두리 스타일: {name}',
    'log.unknown_level'       => '알 수 없는 로그 수준: {name}',
    'spinner.unknown_style'   => '알 수 없는 스피너 스타일: {name}',
    'format.unknown_type'     => '알 수 없는 --type: {type}',
    'format.unknown_theme'    => '알 수 없는 테마: {name}',
];
