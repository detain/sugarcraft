<?php

/**
 * Dutch translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => 'E-mail moet minstens één ontvanger hebben (naar, cc of bcc)',
    'mailer.no_from' => 'E-mail moet een afzenderadres hebben',
    'smtp.send_failed' => 'SMTP verzenden mislukt: {message}',
    'smtp.connect_failed' => 'Kan geen verbinding maken met {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'STARTTLS-onderhandeling mislukt',
    'smtp.not_connected' => 'Niet verbonden',
    'smtp.no_response' => 'Server gaf geen antwoord',
    'smtp.unexpected_response' => 'Onverwacht SMTP-antwoord: {response}',
    'resend.network_error' => 'Resend netwerkfout: {error}',
    'resend.api_error' => 'Resend API-fout ({status}): {body}',
    'cli.error' => 'Fout: {message}',
    'cli.transport_error' => 'Transportfout: {message}',
    'cli.send_failed' => 'Verzenden mislukt: {message}',
    'cli.email_sent' => '✓ E-mail verzonden via {transport}.',
    'cli.no_to_recipient' => 'Geen --to ontvanger opgegeven',
    'cli.attachment_not_found' => 'Bijlage niet gevonden: {file}',
    'cli.no_transport' => 'Geen transport geconfigureerd. Stel de omgevingsvariabele RESEND_API_KEY of CANDY_MAIL_SMTP_HOST in.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Verzending geannuleerd voordat deze begon',
    'smtp.tls_required' => 'TLS is vereist maar kon niet tot stand worden gebracht: {host} bood geen STARTTLS aan',
    'smtp.auth_insecure' => 'Verificatie geweigerd via een onbeveiligd (niet-versleuteld) SMTP-kanaal',
    'smtp.empty_response' => 'Server stuurde een leeg antwoord',
    'smtp.send_cancelled' => 'SMTP-verzending geannuleerd voordat deze begon',
    'smtp.write_error' => 'Schrijven naar SMTP-server mislukt',
    'smtp.incomplete_write' => 'Onvolledig schrijven naar SMTP-server',
    'smtp.invalid_helo_host' => 'HELO-host {helo} is geen geldige hostnaam (alleen letters, cijfers, punten en koppeltekens)',
    'smtp.auth_unavailable' => 'De SMTP-server {host} bood AUTH niet aan; doorgeven zonder authenticatie wordt geweigerd terwijl er referenties zijn geconfigureerd',
    'smtp.response_too_long' => 'Het SMTP-serverantwoord overschreed de groottelimiet',
    'smtp.response_timeout' => 'Het SMTP-serverantwoord overschreed de tijdslimiet',
    'transport.not_serializable' => 'Een transport met inloggegevens kan niet worden geserialiseerd of opnieuw geimporteeerd; bouw het waar het wordt gebruikt',
    'email.crlf_in_address' => 'CRLF-injectie gedetecteerd in e-mailadres',
    'email.invalid_address' => 'Ongeldig e-mailadres: {addr}',
    'email.crlf_in_header' => 'CRLF-injectie gedetecteerd in {field}-header',
    'email.invalid_mime_type' => 'Ongeldig MIME-type voor bijlage (verwacht type/subtype): {mime}',
    'email.invalid_header_char' => 'De {field}-header bevat verboden stuurcodes (alleen printbare tekst)',
    'resend.send_cancelled' => 'Resend-verzending geannuleerd voordat deze begon',
    'resend.curl_missing' => 'ext-curl is vereist voor de Resend-transport (SMTP-transport heeft geen extensies nodig voor onbeveiligd SMTP; ext-openssl voor TLS)',
    'resend.no_api_key' => 'Resend-transport heeft geen API-sleutel: geef er een mee in de constructor of stel RESEND_API_KEY in',
    'attachment.unreadable' => 'Bijlagebestand is niet leesbaar: {path}',
    'attachment.no_path' => 'Er moet een bestandspad worden opgegeven bij bijvoegen via bestandsnaam',
    'attachment.invalid_cid' => 'Ongeldige Content-ID-indeling: {cid} (toegestaan: RFC 2392-msg-id user@host, optioneel cid:-voorvoegsel of eenvoudige identifier)',
    'attachment.not_a_file' => 'Pad van bijlage {path} is geen regulier bestand',
    'attachment.too_large' => 'Bijlage overschrijdt de limiet van {limit} bytes',
    'cli.unknown_option' => 'Onbekende optie: {option}',
    'cli.did_you_mean' => 'Bedoelde u {suggestion}?',
    'cli.invalid_port' => 'Ongeldige SMTP-poort: {port} (moet tussen 1 en 65535 liggen)',
    'cli.missing_value' => 'Optie {option} heeft een waarde nodig (waarden met een koppelteken: --{option}=WAARDE)',
    'cli.no_sender' => 'Geen afzenderadres: geef --from=ADRES op of stel CANDY_MAIL_FROM in',
    'cli.invalid_env_flag' => 'Omgevingsvariabele {name} heeft een ongeldige vlagwaarde {value}; gebruik 1/true/yes/on of 0/false/no/off',
    'cli.ca_file_unreadable' => 'CA-bestand {file} is geen leesbaar regulier bestand',
    'cli.invalid_timeout' => 'Timeout {timeout} is geen aantal seconden tussen 1 en 3600',
    'cli.render_resend_divergence' => 'Let op: --render toont een voorbeeld van de SMTP MIME-envelop; de geconfigureerde transport is Resend, die een JSON-payload plaatst. De onderstaande bytes zijn niet wat verzonden wordt.',
    'cli.empty_message' => 'Er is niets te verzenden: het bericht bevat geen tekstbody, geen HTML-onderdeel en geen bijlage. Gebruik --body, voer de invoer via een pipe in, of gebruik --html.',
    'cli.deprecated_env' => '{old} is verouderd; stel in plaats daarvan {new} in.',
    'cli.stdin_too_large' => 'De STDIN-invoer overschreed de limiet van {limit} bytes; verkort de invoer of voeg deze toe als bestand.',
];
