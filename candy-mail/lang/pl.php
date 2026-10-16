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
];
