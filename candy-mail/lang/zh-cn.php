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
];
