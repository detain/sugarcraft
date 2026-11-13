<?php

/**
 * Korean translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => '{bin} 실행 실패',
    'launcher.transport_not_executable' => '트랜스포트 바이너리를 찾을 수 없거나 실행 가능하지 않습니다: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: 엔드포인트 {endpoint}: 사용자 지정 트랜스포트가 {legs}을(를) 무시합니다',
    'config.not_found'         => 'wishlist 구성을 찾을 수 없음: {path}',
    'config.json_top_level'    => 'wishlist json: 최상위 값은 배열이어야 합니다',
    'config.json_entry_object' => 'wishlist json: 각 항목은 객체여야 합니다',
    'config.yaml_continuation' => "wishlist yaml: '- name:' 블록이 시작되기 전에 계속 줄이 있습니다",
    'config.yaml_unparseable'  => 'wishlist yaml: 구문 분석할 수 없는 줄 {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: `{field}:` 아래 블록 매핑(줄 {line}: {found}) — 이 필드는 `- value` 항목 목록이어야 합니다',
    'config.yaml_unterminated_quote' => 'wishlist yaml: 값에 닫히지 않은 따옴표: {found}',
    'config.entry_missing_field' => '위시리스트 항목 #{index}: 필수 필드 누락: {field}',
    'config.unknown_key' => '알 수 없는 필드 `{key}` 무시 — 문서화된 키가 아닙니다',
    'config.unknown_key_nearmiss' => '알 수 없는 필드 `{key}` 무시 — `{field}`을(를) 의도했나요?',
    'config.duplicate_name' => '중복 엔드포인트 이름 `{name}` — 모든 행이 로드됩니다; 이름은 식별자입니다',
    'config.field_not_scalar'  => 'wishlist 항목: 필드 [{field}]는 스칼라여야 하며, {type}를 받음',
    'config.too_large'          => '위시리스트 설정 파일이 제한 {limit}바이트를 초과합니다: {path}',
    'config.list_too_long'      => '위시리스트 항목: [{field}] 필드가 로더의 {limit}개 초과 항목을 두고 있습니다',
    'config.field_not_list'       => '위시리스트 항목: [{field}] 필드는 키-값 지도가 아니라 값의 목록이어야 합니다',
    'config.encoding_utf16'       => '{path}은(는) UTF-16 인코딩입니다 — 설정을 UTF-8로 다시 저장하세요',
    'config.unreadable'       => 'wishlist 구성을 읽을 수 없음: {path}',
    'config.home_unset'       => '$HOME 없이 ~ 확장 불가: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: 인라인 플로우 컬렉션 미지원: {value} (중첩 목록 사용)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist 엔드포인트: 필드 [{field}]에 대시로 시작하는 안전하지 않은 값 [{value}]이(가) 있습니다',
    'endpoint.host_empty' => '엔드포인트 `{name}`의 호스트가 비어 있습니다 — 선택 가능한 행에는 호스트 이름이 필요합니다',
    'endpoint.name_empty' => '엔드포인트 이름이 비어 있습니다 — 선택 가능한 행에는 이름이 필요합니다',
    'endpoint.option_empty' => '엔드포인트 `{name}`에 빈 옵션 항목이 있습니다 — 빈 -o 값은 거부됩니다',
    'endpoint.port_invalid'     => 'wishlist 엔드포인트 [{host}]: 잘못된 포트 [{port}] — 1에서 65535 사이의 정수여야 합니다',

    'cli.usage'         => '사용법: candy-ssh-launcher [--config <경로>] [--ssh <ssh-binary>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: 알 수 없는 인수: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: 구성을 찾을 수 없습니다. ~/.config/wishlist.yml을 작성하세요(README의 빠른 시작 참조). 또는 --config <경로>를 전달하세요.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: 취소됨.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh 바이너리를 찾을 수 없거나 실행할 수 없습니다: {bin} — ssh가 다른 경로에 있으면 --ssh <경로>를 전달하세요',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} 값이 필요합니다',
    'cli.no_endpoints'   => 'candy-ssh-launcher: {path}에 엔드포인트 없음',
    'cli.not_a_terminal' => '픽커에는 대화형 터미널이 필요합니다 — stdin과 stdout은 TTY여야 합니다. 기계 가독 출력에는 --list를 사용하십시오.',
    'cli.config_unowned'   => 'candy-ssh-launcher: 다른 사용자가 소유한 구성은 거부합니다: {path} — 구성은 신뢰되는 입력입니다. README의 Security를 참조하세요.',
    'cli.config_writable'  => 'candy-ssh-launcher: 그룹 또는 모든 사용자가 쓸 수 있는 구성은 거부합니다: {path} — 0644 또는 0600으로 조이거나 --config로 명시적으로 전달하세요.',
    'cli.project_local'    => 'candy-ssh-launcher: 프로젝트 로컬 wishlist 사용 중: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: 엔드포인트가 -o {option}을(를) 요청합니다 — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => '호스트 키 검증이 완화되었습니다 — 서버 키가 검증되지 않을 수 있습니다',
    'cli.unsafe_option_effect_user_known_hosts_file' => '사용자의 알려진 호스트 신뢰 데이터베이스가 리디렉션되었습니다',
    'cli.unsafe_option_effect_global_known_hosts_file' => '시스템 전역 신뢰 데이터베이스가 리디렉션되었습니다',
    'cli.unsafe_option_effect_log_level' => 'ssh 경고와 알림이 숨겨집니다',
    'cli.unsafe_option_effect_identity_agent' => '인증이 다른 에이전트 소켓으로 위임됩니다',
    'cli.unsafe_option_effect_certificate_file' => '추가 인증 자격 증명이 제시됩니다',
    'cli.ssh_config_source'  => 'candy-ssh-launcher: ssh 구성에서 엔드포인트를 가져옵니다: {path}',
    'cli.ssh_config_missing' => 'candy-ssh-launcher: {path}에 ssh 구성이 없습니다 — 다른 파일을 지정하려면 --from-ssh-config와 함께 --config <path>를 사용하세요',
    'cli.config_is_ssh_config' => 'candy-ssh-launcher: {path}은(는) wishlist 파일이 아니라 OpenSSH 구성으로 보입니다 — --from-ssh-config로 가져오세요 (필요하면 --config <path>로 파일을 지정)',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => '필터: ',
    'picker.no_matches'  => '  (일치 없음 — Backspace 또는 Esc)',
    'picker.help'        => '↑/↓ 선택 · Enter 연결 · Esc 종료 · 입력으로 필터',
    'picker.help_keys'   => '⌫ 삭제 · Ctrl+U/W 지우기 · PgUp/PgDn 페이지 · Home/End 이동',
    'picker.count'       => '  ({total} 중 {shown})',
];
