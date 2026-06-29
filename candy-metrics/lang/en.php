<?php

/**
 * English (default) translations for candy-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'cannot open metrics target: {target}',
    'jsonstream.cannot_open_stderr' => 'cannot open php://stderr',
    'jsonstream.invalid_target'     => 'target must be path, resource, or null',
    'jsonstream.write_failed'       => 'jsonstream write failed: {name}',
    'statsd.socket_not_resource'    => 'existingSocket must be a resource',
    'statsd.connect_failed'         => 'statsd connect failed: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: cannot open {path}',
    'prom.rename_failed'            => 'prometheus textfile: rename failed: {tmp} -> {dest}',
];
