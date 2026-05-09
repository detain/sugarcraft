<?php

/**
 * English (default) translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'cannot open php://stderr',
    'middleware.stderr_not_resource' => 'stderr must be a resource',
    'logger.cannot_open_target'      => 'cannot open log target: {target}',
    'logger.invalid_target'          => 'Logger target must be a path, resource, or null',
    'bubbletea.bad_factory'          => 'BubbleTea factory must return an object with a run() method; got {got}',
    'bubbletea.requires_host_sshd'   => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a candy-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to keep pre-PTY-upgrade behaviour, or migrate to Spawn middleware with a wrapper script (see BubbleTea class doc).',
    'transport.bad_stdin'            => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'           => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'             => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'       => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                  => 'Spawn factory cmd must be a non-empty list of argv strings',
];
