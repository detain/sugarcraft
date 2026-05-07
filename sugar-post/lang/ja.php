<?php

/**
 * Japanese translations for sugar-post.
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
    'cli.no_transport'           => 'トランスポートが設定されていません。RESEND_API_KEY または POP_SMTP_HOST 環境変数を設定してください。',
];
