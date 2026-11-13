<?php

/**
 * English (default) translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'cannot open php://stderr',
    'middleware.cannot_open_stream' => 'cannot open {target}',
    'middleware.stream_not_resource' => '{target} must be a resource',
    'logger.cannot_open_target'      => 'cannot open log target: {target}',
    'logger.invalid_target'          => 'Logger target must be a path, resource, or null',
    'bubbletea.bad_factory'          => 'BubbleTea factory must return an object with a run() method; got {got}',
    'bubbletea.requires_host_sshd'   => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a sugar-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to mount the Program on the supervisor stdio, or migrate to Spawn middleware with a wrapper script (see the BubbleTea class doc).',
    'transport.bad_stdin'            => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'           => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'             => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'       => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                  => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'       => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'     => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => 'Rate limit exceeded. Try again later.',
    'ratelimit.unavailable_denying'   => 'Rate limiter unavailable, denying connection: {why}',
    'ratelimit.degraded_allowing'     => 'Rate limiter degraded, allowing connection (fail-open): {why}',
    'ratelimit.state_lost'            => 'Rate limiter could not persist state (this update is lost): {why}',
    'auth.unauthorized'               => 'Unauthorized. ({reason})',
    'passwordauth.permission_denied'  => 'Permission denied.',
    'certificateauth.required'        => 'Certificate required but none presented.',
    'certificateauth.rejected'        => 'Certificate rejected.',
    'certificateauth.env_missing'     => 'No client certificate in the environment (looked for: {vars}); certificate authentication checked nothing.',
    'keyboardinteractive.auth_failed' => 'Authentication failed.',
    'keyboardinteractive.input_visible' => 'This terminal is not silenced: everything you type here is echoed back.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'Environment entries from the peer exceeded the accepted budget and were dropped.',
    'transport.pty_outside_loop'      => 'pty() called outside of active pump loop',
    'keyboardinteractive.bad_challenge' => 'Challenge {index} must be a prompt string or an array with a string "prompt" key; got {got}.',
    'auth.reject_user'                => 'user not allowed: {name}',
    'auth.reject_key'                 => 'key not allowed: {fingerprint}',
    'auth.reject_unpaired'            => 'key not paired with the requested user: {fingerprint} as {name}',
    'auth.reject_validation'          => 'key validation failed: {fingerprint}',
    'middleware.async_auth_failed'    => 'auth failed: {why}',
];
