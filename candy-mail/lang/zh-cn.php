<?php

/**
 * Simplified Chinese translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => '邮件必须至少有一个收件人（to、cc 或 bcc）',
    'mailer.no_from'             => '邮件必须有一个发件人地址',
    'smtp.send_failed'           => 'SMTP 发送失败：{message}',
    'smtp.connect_failed'        => '无法连接到 {addr}：{errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS 协商失败',
    'smtp.not_connected'         => '未连接',
    'smtp.no_response'           => '服务器未发送响应',
    'smtp.unexpected_response'   => '意外的 SMTP 响应：{response}',
    'resend.network_error'       => 'Resend 网络错误：{error}',
    'resend.api_error'           => 'Resend API 错误 ({status})：{body}',
    'cli.error'                  => '错误：{message}',
    'cli.transport_error'        => '传输错误：{message}',
    'cli.send_failed'            => '发送失败：{message}',
    'cli.email_sent'             => '✓ 邮件已通过 {transport} 发送。',
    'cli.no_to_recipient'        => '未指定 --to 收件人',
    'cli.attachment_not_found'   => '附件文件未找到：{file}',
    'cli.no_transport'           => '未配置传输。请设置 RESEND_API_KEY 或 CANDY_MAIL_SMTP_HOST 环境变量。',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => '发送开始前已取消',
    'smtp.tls_required'            => '需要 TLS 但无法建立：{host} 未提供 STARTTLS',
    'smtp.auth_insecure'           => '拒绝通过不安全（未加密）的 SMTP 通道进行身份验证',
    'smtp.empty_response'          => '服务器发送了空响应',
    'smtp.send_cancelled'          => 'SMTP 发送开始前已取消',
    'smtp.write_error'             => '写入 SMTP 服务器失败',
    'smtp.incomplete_write'        => '对 SMTP 服务器的写入不完整',
    'email.crlf_in_address'        => '在电子邮件地址中检测到 CRLF 注入',
    'email.invalid_address'        => '无效的电子邮件地址：{addr}',
    'email.crlf_in_header'         => '在 {field} 标头中检测到 CRLF 注入',
    'resend.send_cancelled'        => 'Resend 发送开始前已取消',
    'resend.curl_missing'          => 'Resend 传输需要 ext-curl（SMTP 传输不需要任何扩展）',
    'attachment.unreadable'        => '附件文件不可读：{path}',
    'attachment.no_path'           => '按文件名附加时必须提供文件路径',
    'attachment.invalid_cid'       => '无效的 Content-ID 格式：{cid}（RFC 2392 要求 msg-id 格式）',
    'cli.unknown_option'           => '未知选项：{option}',
    'cli.invalid_port'             => '无效的 SMTP 端口：{port}（必须介于 1 和 65535 之间）',
];
