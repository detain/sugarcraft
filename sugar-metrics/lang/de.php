<?php

/**
 * German translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'Metrik-Ziel konnte nicht geöffnet werden: {target}',
    'jsonstream.cannot_open_stderr' => 'php://stderr konnte nicht geöffnet werden',
    'jsonstream.invalid_target'     => 'Ziel muss Pfad, Ressource oder null sein',
    'statsd.socket_not_resource'    => 'existingSocket muss eine Ressource sein',
    'statsd.connect_failed'         => 'Statsd-Verbindung fehlgeschlagen: {errstr} ({errno})',
    'prom.cannot_open'              => 'Prometheus textfile: Öffnen fehlgeschlagen {path}',
    'prom.rename_failed'            => 'Prometheus textfile: Umbenennung fehlgeschlagen: {tmp} -> {dest}',
];
