<?php

/**
 * Dutch translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'kan php://stderr niet openen',
    'logger.cannot_open_target'      => 'kan logbestemming niet openen: {target}',
    'logger.invalid_target'          => 'Loggerbestemming moet een pad, resource of null zijn',
    'bubbletea.bad_factory'          => 'BubbleTea-fabriek moet een object met run()-methode retourneren; gekregen: {got}',
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
    'ratelimit.exceeded'              => 'Snelheidslimiet overschreden. Probeer het later opnieuw.',
    'auth.unauthorized'               => 'Niet geautoriseerd. ({reason})',
    'passwordauth.permission_denied'  => 'Toegang geweigerd.',
    'certificateauth.required'        => 'Certificaat vereist maar niet aangeboden.',
    'certificateauth.rejected'        => 'Certificaat geweigerd.',
    'keyboardinteractive.auth_failed' => 'Verificatie mislukt.',
];
