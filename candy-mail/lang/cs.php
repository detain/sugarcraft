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

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Odeslání zrušeno před zahájením',
    'smtp.tls_required'            => 'Je vyžadován TLS, ale nelze jej ustanovit: {host} nenabízel STARTTLS',
    'smtp.auth_insecure'           => 'Odmítám ověřování přes nezabezpečený (nešifrovaný) kanál SMTP',
    'smtp.empty_response'          => 'Server odeslal prázdnou odpověď',
    'smtp.send_cancelled'          => 'Odeslání SMTP zrušeno před zahájením',
    'smtp.write_error'             => 'Zápis na server SMTP se nezdařil',
    'smtp.incomplete_write'        => 'Neúplný zápis na server SMTP',
    'email.crlf_in_address'        => 'V e-mailové adrese zjištěno vložení CRLF',
    'email.invalid_address'        => 'Neplatná e-mailová adresa: {addr}',
    'email.crlf_in_header'         => 'V hlavičce {field} zjištěno vložení CRLF',
    'resend.send_cancelled'        => 'Odeslání Resend zrušeno před zahájením',
    'resend.curl_missing'          => 'Pro transport Resend je vyžadován ext-curl (SMTP transport žádné extenze nevyžaduje)',
    'attachment.unreadable'        => 'Soubor přílohy není čitelný: {path}',
    'attachment.no_path'           => 'Při přikládání podle názvu souboru musí být zadána cesta k souboru',
    'attachment.invalid_cid'       => 'Neplatný formát Content-ID: {cid} (RFC 2392 vyžaduje formát msg-id)',
    'cli.unknown_option'           => 'Neznámá volba: {option}',
    'cli.invalid_port'             => 'Neplatný port SMTP: {port} (musí být mezi 1 a 65535)',
];
