<?php

/**
 * Spanish translations for sugar-ssh-server.
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
    'bubbletea.requires_host_sshd'    => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a sugar-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to mount the Program on the supervisor stdio, or migrate to Spawn middleware with a wrapper script (see the BubbleTea class doc).',
    'transport.bad_stdin'             => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'            => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'              => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'        => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                   => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'         => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'      => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => 'Límite de peticiones superado. Inténtelo de nuevo más tarde.',
    'ratelimit.unavailable_denying'   => 'Limitador de tasa no disponible, conexión denegada: {why}',
    'ratelimit.degraded_allowing'     => 'Limitador de tasa degradado, conexión permitida (fail-open): {why}',
    'ratelimit.state_lost'            => 'No se pudo guardar el estado del limitador (esta actualización se pierde): {why}',
    'auth.unauthorized'               => 'No autorizado. ({reason})',
    'passwordauth.permission_denied'  => 'Permiso denegado.',
    'certificateauth.required'        => 'Se requiere certificado pero no se presentó ninguno.',
    'certificateauth.rejected'        => 'Certificado rechazado.',
    'certificateauth.env_missing'     => 'No hay certificado de cliente en el entorno (buscado: {vars}); la autenticación por certificado no comprobó nada.',
    'keyboardinteractive.auth_failed' => 'Autenticación fallida.',
    'keyboardinteractive.input_visible' => 'Esta terminal no está silenciada: todo lo que escriba aquí se mostrará.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'Las variables de entorno del par excedieron el presupuesto aceptado y se descartaron.',
    'transport.pty_outside_loop' => 'pty() llamado fuera del bucle activo de bombeo',
    'keyboardinteractive.bad_challenge' => 'El desafío {index} debe ser una cadena de prompt o un array con una clave "prompt" de tipo cadena; se obtuvo: {got}.',
    'auth.reject_user' => 'usuario no permitido: {name}',
    'auth.reject_key' => 'clave no permitida: {fingerprint}',
    'auth.reject_unpaired' => 'la clave no está asociada al usuario solicitado: {fingerprint} como {name}',
    'auth.reject_validation' => 'falló la validación de la clave: {fingerprint}',
    'auth.allowlist_unsupported' => 'La lista permitida contiene una entrada de un tipo no admitido: {type} — omitida, nunca podrá coincidir',
    'middleware.async_auth_failed' => 'autenticación fallida: {why}',
];
