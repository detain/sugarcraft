<?php

/**
 * Korean translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => '치명적 로그: {message}',
    'logger.invalid_stream' => '스트림은 유효한 리소스여야 합니다',
    'psrbridge.not_string' => '로그 레벨은 PSR-3 LogLevel 상수 중 하나여야 합니다. 입력: {got}',
    'psrbridge.unknown_level' => '알 수 없는 로그 레벨 \'{level}\'. PSR-3 LogLevel 상수 중 하나를 기대했습니다',
    'parts.unknown' => '알 수 없는 로그 줄 항목: {parts}',
    'parts.duplicate' => '중복된 로그 줄 항목: {parts}',
    'log.fatal_error' => '치명적 오류',
    'log.fatal_parse' => '구문 분석 오류',
    'log.fatal_core' => '코어 오류',
    'log.fatal_compile' => '컴파일 오류',
    'log.fatal_user' => '사용자 오류',
    'log.fatal_shutdown' => '종료 오류',
    'logger.unopenable_stream' => '로그 파일 \'{path}\' 열 수 없습니다',
];
