<?php

/**
 * Italian translations for candy-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'impossibile aprire la destinazione delle metriche: {target}',
    'jsonstream.cannot_open_stderr' => 'impossibile aprire php://stderr',
    'jsonstream.invalid_target'     => 'la destinazione deve essere un percorso, una risorsa o null',
    'statsd.socket_not_resource'    => 'existingSocket deve essere una risorsa',
    'statsd.connect_failed'         => 'connessione statsd fallita: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: impossibile aprire {path}',
    'prom.rename_failed'            => 'prometheus textfile: ridenominazione fallita: {tmp} -> {dest}',
];
