<?php

/**
 * Simplified Chinese translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => '无法打开 php://stderr',
    'logger.cannot_open_target'      => '无法打开日志目标：{target}',
    'logger.invalid_target'          => '日志记录器目标必须是路径、资源或 null',
    'bubbletea.bad_factory'          => 'BubbleTea 工厂必须返回具有 run() 方法的对象；实际：{got}',
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
    'ratelimit.exceeded'              => '请求频率超限，请稍后重试。',
    'auth.unauthorized'               => '未授权。（{reason}）',
    'passwordauth.permission_denied'  => '权限被拒绝。',
    'certificateauth.required'        => '需要证书但未提供。',
    'certificateauth.rejected'        => '证书被拒绝。',
    'keyboardinteractive.auth_failed' => '认证失败。',
];
