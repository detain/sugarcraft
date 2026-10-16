<?php

/**
 * Dutch translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'E-mail moet minstens één ontvanger hebben (naar, cc of bcc)',
    'mailer.no_from'             => 'E-mail moet een afzenderadres hebben',
    'smtp.send_failed'           => 'SMTP verzenden mislukt: {message}',
    'smtp.connect_failed'        => 'Kan geen verbinding maken met {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS-onderhandeling mislukt',
    'smtp.not_connected'         => 'Niet verbonden',
    'smtp.no_response'           => 'Server gaf geen antwoord',
    'smtp.unexpected_response'   => 'Onverwacht SMTP-antwoord: {response}',
    'resend.network_error'       => 'Resend netwerkfout: {error}',
    'resend.api_error'           => 'Resend API-fout ({status}): {body}',
    'cli.error'                  => 'Fout: {message}',
    'cli.transport_error'        => 'Transportfout: {message}',
    'cli.send_failed'            => 'Verzenden mislukt: {message}',
    'cli.email_sent'             => '✓ E-mail verzonden via {transport}.',
    'cli.no_to_recipient'        => 'Geen --to ontvanger opgegeven',
    'cli.attachment_not_found'   => 'Bijlage niet gevonden: {file}',
    'cli.no_transport'           => 'Geen transport geconfigureerd. Stel de omgevingsvariabele RESEND_API_KEY of CANDY_MAIL_SMTP_HOST in.',
];
