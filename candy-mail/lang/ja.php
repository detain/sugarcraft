<?php

/**
 * Japanese translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => 'メールには少なくとも 1 人の受信者が必要です（to、cc または bcc）',
    'mailer.no_from' => 'メールには送信者アドレスが必要です',
    'smtp.send_failed' => 'SMTP 送信失敗：{message}',
    'smtp.connect_failed' => '{addr} に接続できません：{errstr} ({errno})',
    'smtp.starttls_failed' => 'STARTTLS ネゴシエーション失敗',
    'smtp.not_connected' => '接続されていません',
    'smtp.no_response' => 'サーバーから応答がありません',
    'smtp.unexpected_response' => '予期しない SMTP 応答：{response}',
    'resend.network_error' => 'Resend ネットワークエラー：{error}',
    'resend.api_error' => 'Resend API エラー ({status})：{body}',
    'cli.error' => 'エラー：{message}',
    'cli.transport_error' => 'トランスポートエラー：{message}',
    'cli.send_failed' => '送信失敗：{message}',
    'cli.email_sent' => '✓ メールは {transport} で送信されました。',
    'cli.no_to_recipient' => '--to 受信者が指定されていません',
    'cli.attachment_not_found' => '添付ファイルが見つかりません：{file}',
    'cli.no_transport' => 'トランスポートが設定されていません。RESEND_API_KEY または CANDY_MAIL_SMTP_HOST 環境変数を設定してください。',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => '送信を開始する前にキャンセルされました',
    'smtp.tls_required' => 'TLSが必要ですが確立できませんでした: {host} はSTARTTLSを提供していません',
    'smtp.auth_insecure' => '安全でない(暗号化されていない)SMTPチャネルでの認証は拒否されます',
    'smtp.empty_response' => 'サーバーが空の応答を送信しました',
    'smtp.send_cancelled' => 'SMTP送信を開始する前にキャンセルされました',
    'smtp.write_error' => 'SMTPサーバーへの書き込みに失敗しました',
    'smtp.incomplete_write' => 'SMTPサーバーへの書き込みが不完全です',
    'smtp.invalid_helo_host' => 'HELO 丕スト {helo} は宍効な丕スト名ではありま亖ん(文字、数字、ドッLt、ハイフンのみ)',
    'smtp.auth_unavailable' => 'SMTPサーバー {host} は AUTH をアドバタイズしなかったため、認証情報が設定されているのに未認証での転送は拒否されました',
    'smtp.response_too_long' => 'SMTPサーバーの応答がサイズ上限を超過しました',
    'smtp.response_timeout' => 'SMTPサーバーの応答が時間予算を超過しました',
    'transport.not_serializable' => '认证情報を保持する卜ランスホー卜はシ里ライズや再取リ込ミができません。使する場所で作亪成してください',
    'email.crlf_in_address' => 'メールアドレスにCRLFインジェクションが検出されました',
    'email.invalid_address' => '無効なメールアドレス: {addr}',
    'email.crlf_in_header' => '{field}ヘッダーにCRLFインジェクションが検出されました',
    'resend.send_cancelled' => 'Resend送信を開始する前にキャンセルされました',
    'resend.curl_missing' => 'Resendトランスポートにはext-curlが必要です (SMTPトランスポートに拡張機能は不要です)',
    'attachment.unreadable' => '添付ファイルが読み取り可能ではありません: {path}',
    'attachment.no_path' => 'ファイル名で添付する場合はファイルパスを指定してください',
    'attachment.invalid_cid' => '無効なContent-ID形式: {cid} (RFC 2392ではmsg-id形式が必要)',
    'attachment.not_a_file' => '添付ファイルのパス {path} は通常のファイルではありません',
    'attachment.too_large' => '添付ファイルが {limit} バイトの上限を超えています',
    'cli.unknown_option' => '不明なオプション: {option}',
    'cli.did_you_mean' => '{suggestion} のことですか?',
    'cli.invalid_port' => '無効なSMTPポート: {port} (1〜65535の間である必要があります)',
    'cli.missing_value' => 'オプション {option} には値が必要です（値がダッシュで始まる場合は --{option}=値 の形を使ってください）',
    'cli.no_sender' => '送信者アドレスがありません: --from=アドレス を指定するか CANDY_MAIL_FROM を設定してください',
    'cli.invalid_env_flag' => '環境変数 {name} のフラグ値 {value} が不正です: 1/true/yes/on または 0/false/no/off を使ってください',
    'cli.ca_file_unreadable' => 'CA ファイル {file} は読み取り可能な通常のファイルではありません',
    'cli.invalid_timeout' => 'タイムアウト {timeout} は 1 から 3600 の秒数ではありません',
    'cli.render_resend_divergence' => '注意: --render は SMTP MIME エンベロープのプレビューを表示します。設定済みのトランスポートは Resend で、JSON ペイロードが送信されます。以下のバイトは実際の送信内容ではありません。',
];
