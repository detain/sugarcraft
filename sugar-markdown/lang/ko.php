<?php

/**
 * Korean translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => '테마 파일을 읽을 수 없음: {path}',
    'theme.file_too_large'         => '테마 파일이 {bytes}바이트 상한을 초과함: {path}',
    'theme.json_invalid'           => '테마 파일에 잘못된 JSON입니다',
    'theme.json_object'            => '테마 JSON은 객체로 디코딩되어야 합니다',
    'theme.bad_color'              => '잘못된 색상 지정: {spec}',
    'renderer.unknown_style'       => '알 수 없는 표준 스타일: {name} — {styles}',
    'renderer.stream_invalid'      => 'write()에는 스트림 리소스가 필요합니다',
    'renderer.stream_not_writable' => 'write()에는 기록 가능한 스트림이 필요합니다. 받은 모드: "{mode}"',
    'renderer.write_failed'        => '출력 스트림에 {bytes}바이트 쓰기 실패',
    'renderer.chunk_invalid'       => 'stream()의 청크는 문자열이어야 합니다',
    'sink.closed'                  => '싱크가 닫혀 있습니다',
    'sink.parent_missing'          => '출력 디렉터리가 존재하지 않습니다: {dir}',
    'sink.path_unwritable'         => '출력 경로에 쓸 수 없습니다: {path}',
    'sink.open_failed'             => '출력 경로를 열 수 없습니다: {path}',
    'sink.flush_failed'            => '출력 스트림 플러시 실패',
    'writer.feed_after_close'      => 'close() 이후에 feed()가 호출되었습니다 — 기록기가 완료되었습니다',
    'blockstack.pop_empty' => '블록 스택이 비어 있습니다',
    'theme.mapper_not_style' => 'Theme::mapStyles()는 매퍼가 Style을 반환해야 합니다. 받은 타입: {type}',
    'theme.with_slot_not_style' => 'Theme::with(): "{slot}"은(는) Style 슬롯이 아닙니다. 유효한 슬롯: {slots}',
];
