<?php

/**
 * Czech translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'nelze otevřít cíl metrik: {target}',
    'jsonstream.cannot_open_stderr' => 'nelze otevřít php://stderr',
    'jsonstream.invalid_target'     => 'cíl musí být cesta, prostředek nebo null',
    'statsd.socket_not_resource'    => 'existingSocket musí být prostředek',
    'statsd.connect_failed'         => 'připojení statsd selhalo: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: nelze otevřít {path}',
    'prom.rename_failed'            => 'prometheus textfile: přejmenování selhalo: {tmp} -> {dest}',
];
