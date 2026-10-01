<?php

/**
 * Spanish translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'no se puede abrir php://stderr',
    'logger.cannot_open_target'      => 'no se puede abrir el destino del log: {target}',
    'logger.invalid_target'          => 'El destino del logger debe ser ruta, recurso o null',
    'bubbletea.bad_factory'          => 'La fábrica de BubbleTea debe devolver un objeto con un método run(); obtenido: {got}',
    'middleware.cannot_open_stream'   => 'cannot open {target}',
    'middleware.stream_not_resource'  => '{target} must be a resource',
    'bubbletea.requires_host_sshd'    => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a candy-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new InProcessTransport()) to keep pre-PTY-upgrade behaviour, or migrate to Spawn middleware with a wrapper script (see BubbleTea class doc).',
    'transport.bad_stdin'             => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'            => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'              => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'        => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                   => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'         => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'      => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => 'Límite de peticiones superado. Inténtelo de nuevo más tarde.',
    'auth.unauthorized'               => 'No autorizado. ({reason})',
    'passwordauth.permission_denied'  => 'Permiso denegado.',
    'certificateauth.required'        => 'Se requiere certificado pero no se presentó ninguno.',
    'certificateauth.rejected'        => 'Certificado rechazado.',
    'keyboardinteractive.auth_failed' => 'Autenticación fallida.',
];
