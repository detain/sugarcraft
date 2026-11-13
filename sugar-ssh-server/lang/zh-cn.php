<?php

/**
 * Simplified Chinese translations for sugar-ssh-server.
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
    'bubbletea.requires_host_sshd'    => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a sugar-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to mount the Program on the supervisor stdio, or migrate to Spawn middleware with a wrapper script (see the BubbleTea class doc).',
    'transport.bad_stdin'             => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'            => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'              => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'        => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                   => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'         => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'      => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => '请求频率超限，请稍后重试。',
    'ratelimit.unavailable_denying'   => '速率限制器不可用，正在拒绝连接：{why}',
    'ratelimit.degraded_allowing'     => '速率限制器已降级，允许连接（失败放开）：{why}',
    'ratelimit.state_lost'            => '无法保存速率限制器状态（此更新将丢失）：{why}',
    'auth.unauthorized'               => '未授权。（{reason}）',
    'passwordauth.permission_denied'  => '权限被拒绝。',
    'certificateauth.required'        => '需要证书但未提供。',
    'certificateauth.rejected'        => '证书被拒绝。',
    'certificateauth.env_missing'     => '环境中没有客户端证书（已查找: {vars}）；证书认证未检查任何内容。',
    'keyboardinteractive.auth_failed' => '认证失败。',
    'keyboardinteractive.input_visible' => '此终端未静音: 您在此处输入的任何内容都会被回显.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => '对端提供的环境变量超出允许的预算，已被丢弃。',
    'transport.pty_outside_loop' => 'pty() 在活动的泵循环之外被调用',
    'keyboardinteractive.bad_challenge' => 'Challenge {index} 必须是提示字符串或带字符串 "prompt" 键的数组；得到：{got}。',
    'auth.reject_user' => '不允许的用户：{name}',
    'auth.reject_key' => '不允许的密钥：{fingerprint}',
    'auth.reject_unpaired' => '密钥与请求的用户不匹配：{fingerprint} 作为 {name}',
    'auth.reject_validation' => '密钥验证失败：{fingerprint}',
    'auth.allowlist_unsupported' => '认证允许列表包含不受支持的类型条目：{type} — 已跳过，永远不会匹配',
    'middleware.async_auth_failed' => '认证失败：{why}',
];
