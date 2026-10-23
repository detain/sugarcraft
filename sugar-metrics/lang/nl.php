<?php

/**
 * Dutch translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'kan metrics-doel niet openen: {target}',
    'jsonstream.cannot_open_stderr' => 'kan php://stderr niet openen',
    'jsonstream.invalid_target'     => 'doel moet een pad, resource of null zijn',
    'jsonstream.write_failed'       => 'schrijven naar jsonstream mislukt: {name}',
    'statsd.socket_not_resource'    => 'existingSocket moet een resource zijn',
    'statsd.connect_failed'         => 'statsd-verbinding mislukt: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: kan {path} niet openen',
    'prom.rename_failed'            => 'prometheus textfile: hernoemen mislukt: {tmp} -> {dest}',
    'multibackend.aggregate'        => 'MultiBackend: {count} subbackend(s) mislukt. Fouten: {errors}',
];
