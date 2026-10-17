<?php

/**
 * Portuguese translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'não é possível abrir o destino das métricas: {target}',
    'jsonstream.cannot_open_stderr' => 'não é possível abrir php://stderr',
    'jsonstream.invalid_target'     => 'o destino tem de ser um caminho, recurso ou null',
    'statsd.socket_not_resource'    => 'existingSocket tem de ser um recurso',
    'statsd.connect_failed'         => 'ligação statsd falhou: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: não é possível abrir {path}',
    'prom.rename_failed'            => 'prometheus textfile: rename falhou: {tmp} -> {dest}',
];
