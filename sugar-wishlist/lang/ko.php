<?php

/**
 * Korean translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec을(를) 사용할 수 없음; ext-pcntl 필요',
    'launcher.exec_failed'     => '{bin} 실행 실패',
    'config.not_found'         => 'wishlist 구성을 찾을 수 없음: {path}',
    'config.json_top_level'    => 'wishlist json: 최상위 값은 배열이어야 합니다',
    'config.json_entry_object' => 'wishlist json: 각 항목은 객체여야 합니다',
    'config.yaml_continuation' => "wishlist yaml: '- name:' 블록 앞에는 Continuation을 설정할 수 없습니다",
    'config.yaml_unparseable'  => 'wishlist yaml: 구문 분석할 수 없는 줄: {line}',
    'config.entry_missing_field' => '필수 필드가 누락된 wishlist 항목: name 또는 host',
    'config.field_not_scalar'  => 'wishlist 항목: 필드 [{field}]는 스칼라여야 하며, {type}를 받음',

    // endpoint security
    'endpoint.option_injection' => 'wishlist 엔드포인트: 필드 [{field}]에 대시로 시작하는 안전하지 않은 값 [{value}]이(가) 있습니다',
    'endpoint.port_invalid'     => 'wishlist 엔드포인트 [{host}]: 잘못된 포트 [{port}] — 1에서 65535 사이의 정수여야 합니다',

    'cli.usage'         => '사용법: wishlist [--config <경로>] [--ssh <ssh-binary>]',
    'cli.unknown_arg'   => 'wishlist: 알 수 없는 인수: {arg}',
    'cli.no_config'     => 'wishlist: 구성을 찾을 수 없습니다. --config <경로>를 전달하세요.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: 취소됨.',
    'cli.ssh_not_executable' => 'wishlist: ssh 바이너리를 찾을 수 없거나 실행할 수 없습니다: {bin}',
];
