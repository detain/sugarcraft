<?php

/**
 * Japanese translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'メールには少なくとも 1 人の受信者が必要です（to、cc または bcc）',
    'mailer.no_from'             => 'メールには送信者アドレスが必要です',
    'smtp.send_failed'           => 'SMTP 送信失敗：{message}',
    'smtp.connect_failed'        => '{addr} に接続できません：{errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS ネゴシエーション失敗',
    'smtp.not_connected'         => '接続されていません',
    'smtp.no_response'           => 'サーバーから応答がありません',
    'smtp.unexpected_response'   => '予期しない SMTP 応答：{response}',
    'resend.network_error'       => 'Resend ネットワークエラー：{error}',
    'resend.api_error'           => 'Resend API エラー ({status})：{body}',
    'cli.error'                  => 'エラー：{message}',
    'cli.transport_error'        => 'トランスポートエラー：{message}',
    'cli.send_failed'            => '送信失敗：{message}',
    'cli.email_sent'             => '✓ メールは {transport} で送信されました。',
    'cli.no_to_recipient'        => '--to 受信者が指定されていません',
    'cli.attachment_not_found'   => '添付ファイルが見つかりません：{file}',
    'cli.no_transport'           => 'トランスポートが設定されていません。RESEND_API_KEY または CANDY_MAIL_SMTP_HOST 環境変数を設定してください。',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => '送信を開始する前にキャンセルされました',
    'smtp.tls_required'            => 'TLSが必要ですが確立できませんでした: {host} はSTARTTLSを提供していません',
    'smtp.auth_insecure'           => '安全でない(暗号化されていない)SMTPチャネルでの認証は拒否されます',
    'smtp.empty_response'          => 'サーバーが空の応答を送信しました',
    'smtp.send_cancelled'          => 'SMTP送信を開始する前にキャンセルされました',
    'smtp.write_error'             => 'SMTPサーバーへの書き込みに失敗しました',
    'smtp.incomplete_write'        => 'SMTPサーバーへの書き込みが不完全です',
    'email.crlf_in_address'        => 'メールアドレスにCRLFインジェクションが検出されました',
    'email.invalid_address'        => '無効なメールアドレス: {addr}',
    'email.crlf_in_header'         => '{field}ヘッダーにCRLFインジェクションが検出されました',
    'resend.send_cancelled'        => 'Resend送信を開始する前にキャンセルされました',
    'resend.curl_missing'          => 'Resendトランスポートにはext-curlが必要です (SMTPトランスポートに拡張機能は不要です)',
    'attachment.unreadable'        => '添付ファイルが読み取り可能ではありません: {path}',
    'attachment.no_path'           => 'ファイル名で添付する場合はファイルパスを指定してください',
    'attachment.invalid_cid'       => '無効なContent-ID形式: {cid} (RFC 2392ではmsg-id形式が必要)',
    'cli.unknown_option'           => '不明なオプション: {option}',
    'cli.invalid_port'             => '無効なSMTPポート: {port} (1〜65535の間である必要があります)',
];
