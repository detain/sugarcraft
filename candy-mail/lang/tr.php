<?php

/**
 * Turkish translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'E-posta en az bir alıcıya sahip olmalıdır (to, cc veya bcc)',
    'mailer.no_from'             => 'E-posta bir gönderen adresine sahip olmalıdır',
    'smtp.send_failed'           => 'SMTP gönderme başarısız: {message}',
    'smtp.connect_failed'        => '{addr} bağlantısı kurulamadı: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS müzakeresi başarısız',
    'smtp.not_connected'         => 'Bağlı değil',
    'smtp.no_response'           => 'Sunucu yanıt vermedi',
    'smtp.unexpected_response'   => 'Beklenmeyen SMTP yanıtı: {response}',
    'resend.network_error'       => 'Resend ağ hatası: {error}',
    'resend.api_error'           => 'Resend API hatası ({status}): {body}',
    'cli.error'                  => 'Hata: {message}',
    'cli.transport_error'        => 'Taşıma hatası: {message}',
    'cli.send_failed'            => 'Gönderme başarısız: {message}',
    'cli.email_sent'             => '✓ E-posta {transport} ile gönderildi.',
    'cli.no_to_recipient'        => '--to alıcısı belirtilmedi',
    'cli.attachment_not_found'   => 'Ek dosya bulunamadı: {file}',
    'cli.no_transport'           => 'Taşıma yapılandırılmamış. RESEND_API_KEY veya CANDY_MAIL_SMTP_HOST ortam değişkenini ayarlayın.',
];
