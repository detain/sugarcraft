<?php

/**
 * Czech translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'E-mail musí mít alespoň jednoho příjemce (to, cc nebo bcc)',
    'mailer.no_from'             => 'E-mail musí mít adresu odesilatele',
    'smtp.send_failed'           => 'Odeslání SMTP selhalo: {message}',
    'smtp.connect_failed'        => 'Nelze se připojit k {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS vyjednávání selhalo',
    'smtp.not_connected'         => 'Nepřipojeno',
    'smtp.no_response'           => 'Server nezaslal odpověď',
    'smtp.unexpected_response'   => 'Neočekávaná odpověď SMTP: {response}',
    'resend.network_error'       => 'Síťová chyba Resend: {error}',
    'resend.api_error'           => 'Chyba API Resend ({status}): {body}',
    'cli.error'                  => 'Chyba: {message}',
    'cli.transport_error'        => 'Chyba transportu: {message}',
    'cli.send_failed'            => 'Odeslání selhalo: {message}',
    'cli.email_sent'             => '✓ E-mail odeslán přes {transport}.',
    'cli.no_to_recipient'        => 'Příjemce --to nebyl specifikován',
    'cli.attachment_not_found'   => 'Příloha nenalezena: {file}',
    'cli.no_transport'           => 'Transport není nakonfigurován. Nastavte proměnnou prostředí RESEND_API_KEY nebo CANDY_MAIL_SMTP_HOST.',
];
