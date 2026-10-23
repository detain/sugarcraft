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

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Versand vor dem Start abgebrochen',
    'smtp.tls_required'            => 'TLS ist erforderlich, konnte aber nicht eingerichtet werden: {host} bot kein STARTTLS an',
    'smtp.auth_insecure'           => 'Authentifizierung über einen unsicheren (unverschlüsselten) SMTP-Kanal wird verweigert',
    'smtp.empty_response'          => 'Server hat eine leere Antwort gesendet',
    'smtp.send_cancelled'          => 'SMTP-Versand vor dem Start abgebrochen',
    'smtp.write_error'             => 'Schreiben auf den SMTP-Server fehlgeschlagen',
    'smtp.incomplete_write'        => 'Unvollständiger Schreibvorgang auf den SMTP-Server',
    'email.crlf_in_address'        => 'CRLF-Injection in E-Mail-Adresse erkannt',
    'email.invalid_address'        => 'Ungültige E-Mail-Adresse: {addr}',
    'email.crlf_in_header'         => 'CRLF-Injection im {field}-Header erkannt',
    'resend.send_cancelled'        => 'Resend-Versand vor dem Start abgebrochen',
    'resend.curl_missing'          => 'ext-curl wird für den Resend-Transport benötigt (der SMTP-Transport benötigt keine Erweiterungen)',
    'attachment.unreadable'        => 'Anhangsdatei ist nicht lesbar: {path}',
    'attachment.no_path'           => 'Beim Anhängen über einen Dateinamen muss ein Dateipfad angegeben werden',
    'attachment.invalid_cid'       => 'Ungültiges Content-ID-Format: {cid} (RFC 2392 verlangt das msg-id-Format)',
    'cli.unknown_option'           => 'Unbekannte Option: {option}',
    'cli.invalid_port'             => 'Ungültiger SMTP-Port: {port} (muss zwischen 1 und 65535 liegen)',
];
