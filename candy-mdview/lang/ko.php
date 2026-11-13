<?php

/**
 * Korean translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => '알 수 없는 테마: {name} — JSON 스타일 파일은 --theme-config를 사용하세요',
    'render.style_overrides_theme' => '참고: --style "{style}"이(가) --theme "{theme}"보다 우선합니다',
    'render.config_unreadable' => '설정 파일을 읽을 수 없습니다: {path}',
    'render.config_too_large' => '설정 파일이 {limit}바이트를 초과합니다: {path}',
    'render.config_invalid' => '설정 파일이 유효한 JSON 객체가 아닙니다: {path} ({error})',
    'render.config_unknown_key' => '설정 파일에 알 수 없는 키가 있습니다: {path} ({keys})',
    'render.config_bad_value' => '{path}의 {key} 값이 올바르지 않습니다: {expected} 필요',
    'render.theme_config_unreadable' => '테마 설정을 읽을 수 없습니다: {path}',
    'render.theme_config_too_large' => '테마 설정이 {limit}바이트를 초과합니다: {path}',
    'render.theme_config_unrecognized' => '테마 구성이 플랫 스키마와 글래머 스키마 모두에 일치하지 않습니다: {path}',
    'glamour.json_invalid' => '테마 설정이 유효한 JSON이 아닙니다',
    'glamour.json_object' => '테마 설정은 JSON 객체여야 합니다',
    'glamour.decoration_unsafe' => '테마 {slot}에는 줄 바꿈, 탭, 공백만 사용할 수 있습니다',
    'glamour.value_too_large' => '테마 {key}이(가) 최대값 {limit}을(를) 초과합니다',
    'render.no_input_tty' => '입력 없음: 파일을 지정하거나 Markdown을 표준 입력으로 파이프하거나 --help를 실행하세요',
    'render.no_input_empty' => '입력 없음: 표준 입력이 비어 있습니다',
    'render.pager_needs_tty' => '페이지에는 터미널이 필요합니다. -p 없이 표준 출력으로 렌더링하세요',
    'render.file_missing' => '{path} 열 수 없음: 파일이 없습니다',
    'render.file_is_directory' => '{path} 열 수 없음: 디렉터리입니다',
    'render.file_unreadable' => '{path} 열 수 없음: 파일을 읽을 수 없습니다',
    'render.binary_input' => '{path} 렌더링 거부: 텍스트 파일이 아닙니다',
    'render.input_too_large' => '입력이 {limit}바이트를 초과합니다: {path}',
    'render.fetch_failed' => '가져올 수 없음 {url}: {reason}',
    'render.directory_empty' => '{path}에 Markdown 문서가 없음: README.md, README.markdown, index.md, 단일 *.md를 검색했습니다',
    'render.directory_ambiguous' => '{path}에 단일 문서가 없음: {found} — 하나를 명시하세요',
    'render.directory_chosen' => '디렉터리 인자 {path}에서 {chosen} 렌더링 중',
    'render.width_invalid' => '잘못된 --width 값: {value} (0~9999 정수 필요; 0은 줄바꿈 해제)',
];
