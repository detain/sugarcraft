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

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Verzending geannuleerd voordat deze begon',
    'smtp.tls_required'            => 'TLS is vereist maar kon niet tot stand worden gebracht: {host} bood geen STARTTLS aan',
    'smtp.auth_insecure'           => 'Verificatie geweigerd via een onbeveiligd (niet-versleuteld) SMTP-kanaal',
    'smtp.empty_response'          => 'Server stuurde een leeg antwoord',
    'smtp.send_cancelled'          => 'SMTP-verzending geannuleerd voordat deze begon',
    'smtp.write_error'             => 'Schrijven naar SMTP-server mislukt',
    'smtp.incomplete_write'        => 'Onvolledig schrijven naar SMTP-server',
    'email.crlf_in_address'        => 'CRLF-injectie gedetecteerd in e-mailadres',
    'email.invalid_address'        => 'Ongeldig e-mailadres: {addr}',
    'email.crlf_in_header'         => 'CRLF-injectie gedetecteerd in {field}-header',
    'resend.send_cancelled'        => 'Resend-verzending geannuleerd voordat deze begon',
    'resend.curl_missing'          => 'ext-curl is vereist voor de Resend-transport (SMTP-transport heeft geen extensies nodig)',
    'attachment.unreadable'        => 'Bijlagebestand is niet leesbaar: {path}',
    'attachment.no_path'           => 'Er moet een bestandspad worden opgegeven bij bijvoegen via bestandsnaam',
    'attachment.invalid_cid'       => 'Ongeldige Content-ID-indeling: {cid} (RFC 2392 vereist de msg-id-indeling)',
    'cli.unknown_option'           => 'Onbekende optie: {option}',
    'cli.invalid_port'             => 'Ongeldige SMTP-poort: {port} (moet tussen 1 en 65535 liggen)',
];
