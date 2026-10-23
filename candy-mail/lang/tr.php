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

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Gönderleme başlamadan önce iptal edildi',
    'smtp.tls_required'            => 'TLS gerekiyor ancak kurulamadı: {host} STARTTLS sunmadı',
    'smtp.auth_insecure'           => 'Güvensiz (şifrelenmemiş) SMTP kanalı üzerinden kimlik doğrulama reddediliyor',
    'smtp.empty_response'          => 'Sunucu boş yanıt gönderdi',
    'smtp.send_cancelled'          => 'SMTP gönderimi başlamadan önce iptal edildi',
    'smtp.write_error'             => 'SMTP sunucusuna yazma başarısız',
    'smtp.incomplete_write'        => 'SMTP sunucusuna eksik yazma',
    'email.crlf_in_address'        => 'E-posta adresinde CRLF enjeksiyonu algılandı',
    'email.invalid_address'        => 'Geçersiz e-posta adresi: {addr}',
    'email.crlf_in_header'         => '{field} başlığında CRLF enjeksiyonu algılandı',
    'resend.send_cancelled'        => 'Resend gönderimi başlamadan önce iptal edildi',
    'resend.curl_missing'          => 'Resend taşıyıcısı için ext-curl gerekiyor (SMTP taşıyıcısı eklenti gerektirmez)',
    'attachment.unreadable'        => 'Ek dosya okunamıyor: {path}',
    'attachment.no_path'           => 'Dosya adıyla ek yapılırken bir dosya yolu sağlanmalıdır',
    'attachment.invalid_cid'       => 'Geçersiz Content-ID biçimi: {cid} (RFC 2392 msg-id biçimini gerektirir)',
    'cli.unknown_option'           => 'Bilinmeyen seçenek: {option}',
    'cli.invalid_port'             => 'Geçersiz SMTP bağlantı noktası: {port} (1 ile 65535 arasında olmalı)',
];
