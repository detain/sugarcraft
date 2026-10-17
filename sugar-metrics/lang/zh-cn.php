<?php

/**
 * Simplified Chinese translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => '无法打开指标目标：{target}',
    'jsonstream.cannot_open_stderr' => '无法打开 php://stderr',
    'jsonstream.invalid_target'     => '目标必须是路径、资源或 null',
    'statsd.socket_not_resource'    => 'existingSocket 必须是资源',
    'statsd.connect_failed'         => 'statsd 连接失败：{errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile：无法打开 {path}',
    'prom.rename_failed'            => 'prometheus textfile：重命名失败：{tmp} -> {dest}',
];
