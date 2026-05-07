<?php

/**
 * Korean translations for candy-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => '메트릭 대상을 열 수 없음: {target}',
    'jsonstream.cannot_open_stderr' => 'php://stderr을(를) 열 수 없음',
    'jsonstream.invalid_target'     => '대상은 경로, 리소스 또는 null이어야 합니다',
    'statsd.socket_not_resource'    => 'existingSocket은 리소스여야 합니다',
    'statsd.connect_failed'         => 'statsd 연결 실패: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: 열 수 없음 {path}',
    'prom.rename_failed'            => 'prometheus textfile: 이름 변경 실패: {tmp} -> {dest}',
];
