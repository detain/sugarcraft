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
    'cli.usage'         => '사용법: wishlist [--config <경로>] [--ssh <ssh-binary>]',
    'cli.unknown_arg'   => 'wishlist: 알 수 없는 인수: {arg}',
    'cli.no_config'     => 'wishlist: 구성을 찾을 수 없습니다. --config <경로>를 전달하세요.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: 취소됨.',
];
