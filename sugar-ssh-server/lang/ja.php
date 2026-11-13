<?php

/**
 * Japanese translations for sugar-ssh-server.
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
    'bubbletea.requires_host_sshd'    => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a sugar-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to mount the Program on the supervisor stdio, or migrate to Spawn middleware with a wrapper script (see the BubbleTea class doc).',
    'transport.bad_stdin'             => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'            => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'              => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'        => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                   => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'         => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'      => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => 'レート制限を超過しました。しばらくしてから再試行してください。',
    'ratelimit.unavailable_denying'   => 'レート制限器が使用できないため接続を拒否します: {why}',
    'ratelimit.degraded_allowing'     => 'レート制限器が劣化しています。接続を許可します (fail-open): {why}',
    'ratelimit.state_lost'            => 'レート制限器の状態を保存できませんでした (この更新は失われます): {why}',
    'auth.unauthorized'               => '許可されていません。({reason})',
    'passwordauth.permission_denied'  => 'パーミッションが拒否されました。',
    'certificateauth.required'        => '証明書が必要ですが提示されませんでした。',
    'certificateauth.rejected'        => '証明書が拒否されました。',
    'certificateauth.env_missing'     => '環境内にクライアント証明書がありません（検索済み: {vars}）。証明書による認証は何も検証していません。',
    'keyboardinteractive.auth_failed' => '認証に失敗しました。',
    'keyboardinteractive.input_visible' => 'この端末はエコーをオフにしていません: ここに入力した内容はすべて表示されます。',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'ピアからの環境変数が許容範囲を超えたため破棄されました。',
    'transport.pty_outside_loop' => 'pty() がアクティブなポンプループの外で呼び出されました',
    'keyboardinteractive.bad_challenge' => 'チャレンジ {index} は、プロンプト文字列または文字列の "prompt" キーを持つ配列でなくてはなります。取得した型: {got}。',
    'auth.reject_user' => 'ユーザーは許可されていません: {name}',
    'auth.reject_key' => '鍵は許可されていません: {fingerprint}',
    'auth.reject_unpaired' => '鍵が要求されたユーザーと対応付けられていません: {fingerprint} (ユーザー {name})',
    'auth.reject_validation' => '鍵の検証に失敗しました: {fingerprint}',
    'middleware.async_auth_failed' => '認許失敗: {why}',
];
