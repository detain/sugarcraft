<?php

/**
 * Korean translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => '{bin} 실행 실패',
    'config.not_found'         => 'wishlist 구성을 찾을 수 없음: {path}',
    'config.json_top_level'    => 'wishlist json: 최상위 값은 배열이어야 합니다',
    'config.json_entry_object' => 'wishlist json: 각 항목은 객체여야 합니다',
    'config.yaml_continuation' => "wishlist yaml: '- name:' 블록 앞에는 Continuation을 설정할 수 없습니다",
    'config.yaml_unparseable'  => 'wishlist yaml: 구문 분석할 수 없는 줄 {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: `{field}:` 아래 블록 매핑(줄 {line}: {found}) — 이 필드는 `- value` 항목 목록이어야 합니다',
    'config.entry_missing_field' => '위시리스트 항목 #{index}: 필수 필드 누락: {field}',
    'config.unknown_key' => '알 수 없는 필드 `{key}` 무시 — 문서화된 키가 아닙니다',
    'config.unknown_key_nearmiss' => '알 수 없는 필드 `{key}` 무시 — `{field}`을(를) 의도했나요?',
    'config.duplicate_name' => '중복 엔드포인트 이름 `{name}` — 모든 행이 로드됩니다; 이름은 식별자입니다',
    'config.field_not_scalar'  => 'wishlist 항목: 필드 [{field}]는 스칼라여야 하며, {type}를 받음',
    'config.unreadable'       => 'wishlist 구성을 읽을 수 없음: {path}',
    'config.home_unset'       => 'HOME 없이 ~ 확장 불가: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: 인라인 플로우 컬렉션 미지원: {value} (중첩 목록 사용)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist 엔드포인트: 필드 [{field}]에 대시로 시작하는 안전하지 않은 값 [{value}]이(가) 있습니다',
    'endpoint.host_empty' => '엔드포인트 `{name}`의 호스트가 비어 있습니다 — 선택 가능한 행에는 호스트 이름이 필요합니다',
    'endpoint.name_empty' => '엔드포인트 이름이 비어 있습니다 — 선택 가능한 행에는 이름이 필요합니다',
    'endpoint.option_empty' => '엔드포인트 `{name}`에 빈 옵션 항목이 있습니다 — 빈 -o 값은 거부됩니다',
    'endpoint.port_invalid'     => 'wishlist 엔드포인트 [{host}]: 잘못된 포트 [{port}] — 1에서 65535 사이의 정수여야 합니다',

    'cli.usage'         => '사용법: candy-ssh-launcher [--config <경로>] [--ssh <ssh-binary>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: 알 수 없는 인수: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: 구성을 찾을 수 없습니다. --config <경로>를 전달하세요.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: 취소됨.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh 바이너리를 찾을 수 없거나 실행할 수 없습니다: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} 값이 필요합니다',
    'cli.no_endpoints'   => 'candy-ssh-launcher: {path}에 엔드포인트 없음',
    'cli.not_a_terminal' => '픽커에는 대화형 터미널이 필요합니다 — stdin과 stdout은 TTY여야 합니다. 기계 가독 출력에는 --list를 사용하십시오.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => '필터: ',
    'picker.no_matches'  => '  (일치 없음)',
    'picker.help'        => '  ↑/↓ 또는 j/k 선택 · Enter 연결 · Esc 종료 · 입력으로 필터',
    'picker.count'       => '  ({total} 중 {shown})',
];
