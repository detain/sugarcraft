<?php

/**
 * Simplified Chinese translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => '邮件必须至少有一个收件人（to、cc 或 bcc）',
    'mailer.no_from' => '邮件必须有一个发件人地址',
    'smtp.send_failed' => 'SMTP 发送失败：{message}',
    'smtp.connect_failed' => '无法连接到 {addr}：{errstr} ({errno})',
    'smtp.starttls_failed' => 'STARTTLS 协商失败',
    'smtp.not_connected' => '未连接',
    'smtp.no_response' => '服务器未发送响应',
    'smtp.unexpected_response' => '意外的 SMTP 响应：{response}',
    'resend.network_error' => 'Resend 网络错误：{error}',
    'resend.api_error' => 'Resend API 错误 ({status})：{body}',
    'cli.error' => '错误：{message}',
    'cli.transport_error' => '传输错误：{message}',
    'cli.send_failed' => '发送失败：{message}',
    'cli.email_sent' => '✓ 邮件已通过 {transport} 发送。',
    'cli.no_to_recipient' => '未指定 --to 收件人',
    'cli.attachment_not_found' => '附件文件未找到：{file}',
    'cli.no_transport' => '未配置传输。请设置 RESEND_API_KEY 或 CANDY_MAIL_SMTP_HOST 环境变量。',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => '发送开始前已取消',
    'smtp.tls_required' => '需要 TLS 但无法建立：{host} 未提供 STARTTLS',
    'smtp.auth_insecure' => '拒绝通过不安全（未加密）的 SMTP 通道进行身份验证',
    'smtp.empty_response' => '服务器发送了空响应',
    'smtp.send_cancelled' => 'SMTP 发送开始前已取消',
    'smtp.write_error' => '写入 SMTP 服务器失败',
    'smtp.incomplete_write' => '对 SMTP 服务器的写入不完整',
    'smtp.invalid_helo_host' => 'HELO 主机 {helo} 不是有效的主机名(仅允许字母、数字、点和连字符)',
    'smtp.auth_unavailable' => 'SMTP 服务器 {host} 未声明 AUTH；已配置凭据时拒绝未经身份验证的中继',
    'smtp.response_too_long' => 'SMTP 服务器响应超出大小限制',
    'smtp.response_timeout' => 'SMTP 服务器响应超出时间预算',
    'transport.not_serializable' => '持有凭据的传输对象无法序列化或重新导入;请在使用它的地方构造它',
    'email.crlf_in_address' => '在电子邮件地址中检测到 CRLF 注入',
    'email.invalid_address' => '无效的电子邮件地址：{addr}',
    'email.crlf_in_header' => '在 {field} 标头中检测到 CRLF 注入',
    'email.invalid_mime_type' => '附件 MIME 类型无效（应为 类型/子类型）：{mime}',
    'email.invalid_header_char' => '{field} 标头包含禁止的控制字符（仅限可打印文本）',
    'resend.send_cancelled' => 'Resend 发送开始前已取消',
    'resend.curl_missing' => 'Resend 传输需要 ext-curl（SMTP 传输使用普通 SMTP 时无需扩展；使用 TLS 时需要 ext-openssl）',
    'resend.no_api_key' => 'Resend 传输没有 API 密钥：请向构造函数传入密钥或设置 RESEND_API_KEY',
    'attachment.unreadable' => '附件文件不可读：{path}',
    'attachment.no_path' => '按文件名附加时必须提供文件路径',
    'attachment.invalid_cid' => '无效的 Content-ID 格式：{cid}（允许：RFC 2392 msg-id user@host、可选的 cid: 前缀或简单标识符）',
    'attachment.not_a_file' => '附件路径 {path} 不是常规文件',
    'attachment.too_large' => '附件超过 {limit} 字节限制',
    'cli.unknown_option' => '未知选项：{option}',
    'cli.did_you_mean' => '您是指 {suggestion} 吗?',
    'cli.invalid_port' => '无效的 SMTP 端口：{port}（必须介于 1 和 65535 之间）',
    'cli.missing_value' => '选项 {option} 需要一个值（以破折号开头的值请写成 --{option}=值 形式）',
    'cli.no_sender' => '没有发件人地址：请传入 --from=地址 或设置 CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => '环境变量 {name} 的开关值 {value} 无效；请使用 1/true/yes/on 或 0/false/no/off',
    'cli.ca_file_unreadable' => 'CA 文件 {file} 不是可读的常规文件',
    'cli.invalid_timeout' => '超时 {timeout} 不是 1 到 3600 之间的秒数',
    'cli.render_resend_divergence' => '注意：--render 预览 SMTP MIME 信封；已配置的传输方式是 Resend，它提交 JSON 载荷。下面的字节不是实际发送的内容。',
    'cli.empty_message' => '没有可发送的内容：邮件不包含正文、HTML 部分或附件。请使用 --body、通过管道输入，或使用 --html。',
    'cli.deprecated_env' => '{old} 已弃用；请改用 {new}。',
    'cli.resend_ignored_inputs' => '注意：已选择 Resend 传输，因此 SMTP 相关的输入 {inputs} 将被忽略（Resend 使用其固定的 10 秒连接 / 30 秒传输预算）。',
    'cli.invalid_transport' => '未知的 --transport 值 {transport}（允许：smtp、resend）',
    'cli.resend_key_required' => '--transport=resend 需要 API 密钥：请设置 RESEND_API_KEY（此覆盖会忽略 SMTP 主机）',
    'cli.stdin_too_large' => 'STDIN 输入超过了 {limit} 字节上限；请缩减输入或将其作为文件附件。',
];
