<?php

/**
 * Turkish translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr açılamadı',
    'logger.cannot_open_target'      => 'günlük hedefi açılamadı: {target}',
    'logger.invalid_target'          => 'Günlükçü hedefi bir yol, kaynak veya null olmalıdır',
    'bubbletea.bad_factory'          => 'BubbleTea fabrikası run() metodu olan bir nesne döndürmelidir; alınan: {got}',
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
    'ratelimit.exceeded'              => 'İstek sınırı aşıldı. Daha sonra tekrar deneyin.',
    'auth.unauthorized'               => 'Yetkisiz. ({reason})',
    'passwordauth.permission_denied'  => 'İzin reddedildi.',
    'certificateauth.required'        => 'Sertifika gerekiyor ancak sunulmadı.',
    'certificateauth.rejected'        => 'Sertifika reddedildi.',
    'keyboardinteractive.auth_failed' => 'Kimlik doğrulama başarısız.',
];
