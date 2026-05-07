<?php

/**
 * Japanese translations for candy-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'メトリクスのターゲットを開けません：{target}',
    'jsonstream.cannot_open_stderr' => 'php://stderr を開けません',
    'jsonstream.invalid_target'     => 'ターゲットはパス、リソース、または null である必要があります',
    'statsd.socket_not_resource'    => 'existingSocket はリソースである必要があります',
    'statsd.connect_failed'         => 'statsd 接続失敗：{errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile：開けません {path}',
    'prom.rename_failed'            => 'prometheus textfile：リネーム失敗：{tmp} -> {dest}',
];
