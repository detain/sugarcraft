<?php

/**
 * German translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient'        => 'E-Mail muss mindestens einen Empfänger haben (to, cc oder bcc)',
    'mailer.no_from'             => 'E-Mail muss eine Absenderadresse haben',

    // SmtpTransport.php
    'smtp.send_failed'           => 'SMTP-Senden fehlgeschlagen: {message}',
    'smtp.connect_failed'        => 'Verbindung zu {addr} nicht möglich: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS-Aushandlung fehlgeschlagen',
    'smtp.not_connected'         => 'Nicht verbunden',
    'smtp.no_response'           => 'Server hat keine Antwort gesendet',
    'smtp.unexpected_response'   => 'Unerwartete SMTP-Antwort: {response}',

    // ResendTransport.php
    'resend.network_error'       => 'Resend-Netzwerkfehler: {error}',
    'resend.api_error'           => 'Resend-API-Fehler ({status}): {body}',

    // bin/candy-mail
    'cli.error'                  => 'Fehler: {message}',
    'cli.transport_error'        => 'Transportfehler: {message}',
    'cli.send_failed'            => 'Senden fehlgeschlagen: {message}',
    'cli.email_sent'             => '✓ E-Mail gesendet über {transport}.',
    'cli.no_to_recipient'        => 'Kein --to-Empfänger angegeben',
    'cli.attachment_not_found'   => 'Anhang nicht gefunden: {file}',
    'cli.no_transport'           => 'Kein Transport konfiguriert. Setzen Sie die Umgebungsvariable RESEND_API_KEY oder CANDY_MAIL_SMTP_HOST.',
];
