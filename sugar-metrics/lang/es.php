<?php

/**
 * Spanish translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'no se puede abrir el destino de métricas: {target}',
    'jsonstream.cannot_open_stderr' => 'no se puede abrir php://stderr',
    'jsonstream.invalid_target'     => 'el destino debe ser ruta, recurso o null',
    'statsd.socket_not_resource'    => 'existingSocket debe ser un recurso',
    'statsd.connect_failed'         => 'conexión statsd fallida: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: no se puede abrir {path}',
    'prom.rename_failed'            => 'prometheus textfile: error al renombrar: {tmp} -> {dest}',
];
