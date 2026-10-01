<?php

/**
 * Japanese translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr を開けません',
    'logger.cannot_open_target'      => 'ログターゲットを開けません：{target}',
    'logger.invalid_target'          => 'ロガーターゲットはパス、リソース、または null である必要があります',
    'bubbletea.bad_factory'          => 'BubbleTea ファクトリーは run() メソッドを持つオブジェクトを返す必要があります；取得：{got}',
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
    'ratelimit.exceeded'              => 'レート制限を超過しました。しばらくしてから再試行してください。',
    'auth.unauthorized'               => '許可されていません。({reason})',
    'passwordauth.permission_denied'  => 'パーミッションが拒否されました。',
    'certificateauth.required'        => '証明書が必要ですが提示されませんでした。',
    'certificateauth.rejected'        => '証明書が拒否されました。',
    'keyboardinteractive.auth_failed' => '認証に失敗しました。',
];
