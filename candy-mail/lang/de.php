<?php

/**
 * German translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient' => 'E-Mail muss mindestens einen Empfänger haben (to, cc oder bcc)',
    'mailer.no_from' => 'E-Mail muss eine Absenderadresse haben',

    // SmtpTransport.php
    'smtp.send_failed' => 'SMTP-Senden fehlgeschlagen: {message}',
    'smtp.connect_failed' => 'Verbindung zu {addr} nicht möglich: {errstr} ({errno})',
    'smtp.starttls_failed' => 'STARTTLS-Aushandlung fehlgeschlagen',
    'smtp.not_connected' => 'Nicht verbunden',
    'smtp.no_response' => 'Server hat keine Antwort gesendet',
    'smtp.unexpected_response' => 'Unerwartete SMTP-Antwort: {response}',

    // ResendTransport.php
    'resend.network_error' => 'Resend-Netzwerkfehler: {error}',
    'resend.api_error' => 'Resend-API-Fehler ({status}): {body}',

    // bin/candy-mail
    'cli.error' => 'Fehler: {message}',
    'cli.transport_error' => 'Transportfehler: {message}',
    'cli.send_failed' => 'Senden fehlgeschlagen: {message}',
    'cli.email_sent' => '✓ E-Mail gesendet über {transport}.',
    'cli.no_to_recipient' => 'Kein --to-Empfänger angegeben',
    'cli.attachment_not_found' => 'Anhang nicht gefunden: {file}',
    'cli.no_transport' => 'Kein Transport konfiguriert. Setzen Sie die Umgebungsvariable RESEND_API_KEY oder CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Versand vor dem Start abgebrochen',
    'smtp.tls_required' => 'TLS ist erforderlich, konnte aber nicht eingerichtet werden: {host} bot kein STARTTLS an',
    'smtp.auth_insecure' => 'Authentifizierung über einen unsicheren (unverschlüsselten) SMTP-Kanal wird verweigert',
    'smtp.empty_response' => 'Server hat eine leere Antwort gesendet',
    'smtp.send_cancelled' => 'SMTP-Versand vor dem Start abgebrochen',
    'smtp.write_error' => 'Schreiben auf den SMTP-Server fehlgeschlagen',
    'smtp.incomplete_write' => 'Unvollständiger Schreibvorgang auf den SMTP-Server',
    'smtp.invalid_helo_host' => 'HELO-Host {helo} ist kein gültiger Hostname (nur Buchstaben, Ziffern, Punkte und Bindestriche)',
    'smtp.auth_unavailable' => 'Der SMTP-Server {host} hat AUTH nicht beworben; Senden ohne Authentifizierung wird abgelehnt, obwohl Zugangsdaten konfiguriert sind',
    'smtp.response_too_long' => 'Die Antwort des SMTP-Servers überschritt das Größenlimit',
    'smtp.response_timeout' => 'Die Antwort des SMTP-Servers überschritt das Zeitbudget',
    'transport.not_serializable' => 'Ein Transport mit Anmeldedaten kann nicht serialisiert oder importiert werden; erstellen Sie ihn am Verwendungsort',
    'email.crlf_in_address' => 'CRLF-Injection in E-Mail-Adresse erkannt',
    'email.invalid_address' => 'Ungültige E-Mail-Adresse: {addr}',
    'email.crlf_in_header' => 'CRLF-Injection im {field}-Header erkannt',
    'email.invalid_mime_type' => 'Ungültiger Anhang-MIME-Typ (erwartet Typ/Subtyp): {mime}',
    'email.invalid_header_name' => "Ungültiger Header-Name: {name} (nur Buchstaben, Ziffern und die Zeichen !#$%&'*+-.^_`|~)",
    'email.reserved_header' => 'Der Header {field} gehört zur festen Menge und kann über withHeader nicht gesetzt werden',
    'email.invalid_header_char' => 'Der {field}-Header enthält unzulässige Steuerzeichen (nur druckbarer Text)',
    'resend.send_cancelled' => 'Resend-Versand vor dem Start abgebrochen',
    'resend.curl_missing' => 'ext-curl wird für den Resend-Transport benötigt (der SMTP-Transport benötigt für einfaches SMTP keine Erweiterungen; für TLS ext-openssl)',
    'resend.no_api_key' => 'Der Resend-Transport hat keinen API-Schlüssel: übergeben Sie einen im Konstruktor oder setzen Sie RESEND_API_KEY',
    'attachment.unreadable' => 'Anhangsdatei ist nicht lesbar: {path}',
    'attachment.no_path' => 'Beim Anhängen über einen Dateinamen muss ein Dateipfad angegeben werden',
    'attachment.invalid_cid' => 'Ungültiges Content-ID-Format: {cid} (zulässig: RFC-2392-msg-id user@host, optionales cid:-Präfix oder einfacher Bezeichner)',
    'attachment.not_a_file' => 'Anhangspfad {path} ist keine reguläre Datei',
    'attachment.too_large' => 'Der Anhang überschreitet das Limit von {limit} Byte',
    'cli.unknown_option' => 'Unbekannte Option: {option}',
    'cli.did_you_mean' => 'Meinten Sie {suggestion}?',
    'cli.invalid_port' => 'Ungültiger SMTP-Port: {port} (muss zwischen 1 und 65535 liegen)',
    'cli.missing_value' => 'Die Option {option} erwartet einen Wert (für Werte mit vorangestelltem Bindestrich --{option}=WERT schreiben)',
    'cli.no_sender' => 'Keine Absenderadresse: --from=ADRESSE übergeben oder CANDY_MAIL_FROM setzen',
    'cli.invalid_env_flag' => 'Die Umgebungsvariable {name} enthält einen ungültigen Schalterwert {value}; erlaubt sind 1/true/yes/on oder 0/false/no/off',
    'cli.ca_file_unreadable' => 'CA-Datei {file} ist keine lesbare reguläre Datei',
    'cli.invalid_timeout' => 'Timeout {timeout} ist keine Anzahl Sekunden zwischen 1 und 3600',
    'cli.render_resend_divergence' => 'Hinweis: --render zeigt den SMTP-MIME-Umschlag als Vorschau; der konfigurierte Versandweg ist Resend und sendet eine JSON-Nutzlast. Die folgenden Bytes werden nicht versendet.',
    'cli.empty_message' => 'Nichts zu senden: Die Nachricht enthält keinen Textkörper, keinen HTML-Teil und keine Anlage. Geben Sie --body an, leiten Sie die Eingabe weiter, oder verwenden Sie --html.',
    'cli.deprecated_env' => '{old} ist veraltet; setzen Sie stattdessen {new}.',
    'cli.resend_ignored_inputs' => 'Hinweis: Der Resend-Transport wurde ausgewählt, daher werden die SMTP-bezogenen Eingaben {inputs} ignoriert (Resend behält seine festen Budgets von 10 s Verbindung / 30 s Übertragung bei).',
    'cli.invalid_transport' => 'Unbekannter --transport-Wert {transport} (zulässig: smtp, resend)',
    'cli.resend_key_required' => '--transport=resend benötigt einen API-Schlüssel: RESEND_API_KEY setzen (der SMTP-Host wird von dieser Einstellung ignoriert)',
    'cli.stdin_too_large' => 'Die STDIN-Eingabe hat die Grenze von {limit} Byte überschritten; Eingabe kürzen oder als Datei anhängen.',
    'smtp.invalid_host' => 'SMTP-Host {host} ist kein gültiger Hostname und keine gültige Adresse (nur Buchstaben, Ziffern, Punkte und Bindestriche)',
    'smtp.invalid_port' => 'SMTP-Port {port} liegt außerhalb des Bereichs (muss zwischen 1 und 65535 liegen)',
    'smtp.invalid_timeout' => 'SMTP-Timeout {timeout} ist keine Anzahl Sekunden zwischen 1 und 3600',
    'resend.cid_unsupported' => 'Die Resend-API-Nutzlast hat kein Content-ID-Feld: Der Anhang {filename} ist inline — eingebettete Bilder laufen nur über SMTP.',
    'smtp.incomplete_credentials' => 'SMTP-Zugangsdaten unvollständig: Benutzername und Passwort zusammen angeben oder beide leer lassen für anonyme Übermittlung',
    'email.non_ascii_address' => 'E-Mail-Adresse {addr} enthält Nicht-ASCII-Zeichen: SMTPUTF8 wird nicht unterstützt — bitte ASCII-Kuvertadresse verwenden',
    'smtp.openssl_missing' => 'ext-openssl ist erforderlich, um die SMTP-Verbindung abzusichern (STARTTLS/SMTPS) - Erweiterung installieren; es wird nichts gesendet, bevor der Kanal verschlüsselt werden kann',
    'cli.render_8bit_divergence' => 'Hinweis: --render zeigt den 8-Bit-Textkörper so an, wie ihn ein 8BITMIME-fähiger Relay sähe; ein Relay ohne die Erweiterung erhält diese Teile auf dem Draht als quoted-printable neu kodiert (RFC 6152). Die folgenden Bytes können von dem abweichen, was tatsächlich gesendet wird.',
    'email.too_many_bytes' => 'Anhänge insgesamt {bytes} Bytes überschreiten die Dokumentgrenze von {limit} Bytes - bitte den Versand aufteilen oder weniger Bytes anhängen',
    'smtp.size_exceeded' => 'Die zusammengesetzte Nachricht ist {bytes} Bytes groß; der Relay bewirbt eine SIZE-Grenze von {limit} Bytes - vor DATA abgelehnt',
    'attachment.wrapper_refused' => 'Lesen von {path} verweigert: Stream-Wrapper-Protokolle (phar://, data://, ...) sind keine Anlagequellen - ein phar-Archiv wird beim ersten Antasten als Metadaten deserialisiert. Geben Sie einen nativen Dateipfad an, optional mit file://-Präfix.',
    'resend.invalid_api_key' => 'Der Resend-API-Schluessel hat eine Form, die keinen Authorization-Header passieren kann: druckbares ASCII ohne Leer- oder Steuerzeichen ist erforderlich.',
    'email.header_too_long' => 'Der Header {field} trägt ein Token ohne Leerzeichen, das länger als eine 998-Oktett-Zeile ist; dieser Client faltet nur an Leerzeichen - daher Ablehnung vor dem Senden statt einer beschädigten Zeile.',
    'attachment.filename_too_long' => 'Der Dateiname der Anlage überschreitet die Grenze von {limit} Oktetten (ein Content-Disposition-Parameter ist nicht teilbar, und Dateisysteme begrenzen Namen auf 255 Bytes).',
];
