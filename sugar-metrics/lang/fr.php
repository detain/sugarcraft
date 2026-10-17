<?php

/**
 * French translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'impossible d\'ouvrir la cible des métriques : {target}',
    'jsonstream.cannot_open_stderr' => 'impossible d\'ouvrir php://stderr',
    'jsonstream.invalid_target'     => 'la cible doit être un chemin, une ressource ou null',
    'statsd.socket_not_resource'    => 'existingSocket doit être une ressource',
    'statsd.connect_failed'         => 'connexion statsd échouée : {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile : impossible d\'ouvrir {path}',
    'prom.rename_failed'            => 'prometheus textfile : échec du renommage : {tmp} -> {dest}',
];
