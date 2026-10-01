<?php

/**
 * Korean translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr을(를) 열 수 없음',
    'logger.cannot_open_target'      => '로그 대상을 열 수 없음: {target}',
    'logger.invalid_target'          => '로거 대상은 경로, 리소스 또는 null이어야 합니다',
    'bubbletea.bad_factory'          => 'BubbleTea 팩터리는 run() 메서드가 있는 객체를 반환해야 합니다; 받음: {got}',
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
    'ratelimit.exceeded'              => '요청 제한을 초과했습니다. 나중에 다시 시도하세요.',
    'auth.unauthorized'               => '권한이 없습니다. ({reason})',
    'passwordauth.permission_denied'  => '권한이 거부되었습니다.',
    'certificateauth.required'        => '증명서가 필요하지만 제시되지 않았습니다.',
    'certificateauth.rejected'        => '증명서가 거부되었습니다.',
    'keyboardinteractive.auth_failed' => '인증에 실패했습니다.',
];
