<?php

/**
 * Polish translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'E-mail musi mieć co najmniej jednego odbiorcę (to, cc lub bcc)',
    'mailer.no_from'             => 'E-mail musi mieć adres nadawcy',
    'smtp.send_failed'           => 'Wysyłanie SMTP nie powiodło się: {message}',
    'smtp.connect_failed'        => 'Nie można połączyć się z {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'Negocjacja STARTTLS nie powiodła się',
    'smtp.not_connected'         => 'Nie połączono',
    'smtp.no_response'           => 'Serwer nie wysłał odpowiedzi',
    'smtp.unexpected_response'   => 'Nieoczekiwana odpowiedź SMTP: {response}',
    'resend.network_error'       => 'Błąd sieci Resend: {error}',
    'resend.api_error'           => 'Błąd API Resend ({status}): {body}',
    'cli.error'                  => 'Błąd: {message}',
    'cli.transport_error'        => 'Błąd transportu: {message}',
    'cli.send_failed'            => 'Wysyłanie nie powiodło się: {message}',
    'cli.email_sent'             => '✓ E-mail wysłany przez {transport}.',
    'cli.no_to_recipient'        => 'Nie określono odbiorcy --to',
    'cli.attachment_not_found'   => 'Nie znaleziono załącznika: {file}',
    'cli.no_transport'           => 'Transport nieskonfigurowany. Ustaw zmienną środowiskową RESEND_API_KEY lub CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Wysyłanie anulowane przed rozpoczęciem',
    'smtp.tls_required'            => 'Wymagane jest TLS, ale nie można go ustanowić: {host} nie zaproponował STARTTLS',
    'smtp.auth_insecure'           => 'Odmowa uwierzytelniania przez niezabezpieczony (nieszyfrowany) kanał SMTP',
    'smtp.empty_response'          => 'Serwer wysłał pustą odpowiedź',
    'smtp.send_cancelled'          => 'Wysyłanie SMTP anulowane przed rozpoczęciem',
    'smtp.write_error'             => 'Zapis na serwer SMTP nie powiódł się',
    'smtp.incomplete_write'        => 'Niepełny zapis na serwer SMTP',
    'email.crlf_in_address'        => 'Wykryto wstrzyknięcie CRLF w adresie e-mail',
    'email.invalid_address'        => 'Nieprawidłowy adres e-mail: {addr}',
    'email.crlf_in_header'         => 'Wykryto wstrzyknięcie CRLF w nagłówku {field}',
    'resend.send_cancelled'        => 'Wysyłanie Resend anulowane przed rozpoczęciem',
    'resend.curl_missing'          => 'ext-curl jest wymagany dla transportu Resend (transport SMTP nie potrzebuje rozszerzeń)',
    'attachment.unreadable'        => 'Plik załącznika nie jest do odczytu: {path}',
    'attachment.no_path'           => 'Podczas dołączania według nazwy pliku należy podać ścieżkę pliku',
    'attachment.invalid_cid'       => 'Nieprawidłowy format Content-ID: {cid} (RFC 2392 wymaga formatu msg-id)',
    'cli.unknown_option'           => 'Nieznana opcja: {option}',
    'cli.invalid_port'             => 'Nieprawidłowy port SMTP: {port} (musi być między 1 a 65535)',
];
