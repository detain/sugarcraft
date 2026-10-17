<?php

/**
 * Polish translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'nie można otworzyć celu metryk: {target}',
    'jsonstream.cannot_open_stderr' => 'nie można otworzyć php://stderr',
    'jsonstream.invalid_target'     => 'cel musi być ścieżką, zasobem lub null',
    'statsd.socket_not_resource'    => 'existingSocket musi być zasobem',
    'statsd.connect_failed'         => 'połączenie statsd nie powiodło się: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: nie można otworzyć {path}',
    'prom.rename_failed'            => 'prometheus textfile: zmiana nazwy nie powiodła się: {tmp} -> {dest}',
];
