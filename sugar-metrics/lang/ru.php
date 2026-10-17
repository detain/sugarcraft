<?php

/**
 * Russian translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'невозможно открыть цель метрик: {target}',
    'jsonstream.cannot_open_stderr' => 'невозможно открыть php://stderr',
    'jsonstream.invalid_target'     => 'цель должна быть путём, ресурсом или null',
    'statsd.socket_not_resource'    => 'existingSocket должен быть ресурсом',
    'statsd.connect_failed'         => 'подключение к statsd не удалось: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: невозможно открыть {path}',
    'prom.rename_failed'            => 'prometheus textfile: переименование не удалось: {tmp} -> {dest}',
];
