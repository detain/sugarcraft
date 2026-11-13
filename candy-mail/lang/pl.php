<?php

/**
 * Polish translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => 'E-mail musi mieć co najmniej jednego odbiorcę (to, cc lub bcc)',
    'mailer.no_from' => 'E-mail musi mieć adres nadawcy',
    'smtp.send_failed' => 'Wysyłanie SMTP nie powiodło się: {message}',
    'smtp.connect_failed' => 'Nie można połączyć się z {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'Negocjacja STARTTLS nie powiodła się',
    'smtp.not_connected' => 'Nie połączono',
    'smtp.no_response' => 'Serwer nie wysłał odpowiedzi',
    'smtp.unexpected_response' => 'Nieoczekiwana odpowiedź SMTP: {response}',
    'resend.network_error' => 'Błąd sieci Resend: {error}',
    'resend.api_error' => 'Błąd API Resend ({status}): {body}',
    'cli.error' => 'Błąd: {message}',
    'cli.transport_error' => 'Błąd transportu: {message}',
    'cli.send_failed' => 'Wysyłanie nie powiodło się: {message}',
    'cli.email_sent' => '✓ E-mail wysłany przez {transport}.',
    'cli.no_to_recipient' => 'Nie określono odbiorcy --to',
    'cli.attachment_not_found' => 'Nie znaleziono załącznika: {file}',
    'cli.no_transport' => 'Transport nieskonfigurowany. Ustaw zmienną środowiskową RESEND_API_KEY lub CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Wysyłanie anulowane przed rozpoczęciem',
    'smtp.tls_required' => 'Wymagane jest TLS, ale nie można go ustanowić: {host} nie zaproponował STARTTLS',
    'smtp.auth_insecure' => 'Odmowa uwierzytelniania przez niezabezpieczony (nieszyfrowany) kanał SMTP',
    'smtp.empty_response' => 'Serwer wysłał pustą odpowiedź',
    'smtp.send_cancelled' => 'Wysyłanie SMTP anulowane przed rozpoczęciem',
    'smtp.write_error' => 'Zapis na serwer SMTP nie powiódł się',
    'smtp.incomplete_write' => 'Niepełny zapis na serwer SMTP',
    'smtp.invalid_helo_host' => 'Host HELO {helo} nie jest prawidłową nazwą hosta (tylko litery, cyfry, kropki i myślniki)',
    'smtp.auth_unavailable' => 'Serwer SMTP {host} nie ogłosił AUTH; odmowa przekazania bez uwierzytelnienia przy skonfigurowanych poświadczeniach',
    'smtp.response_too_long' => 'Odpowiedź serwera SMTP przekroczyła limit rozmiaru',
    'smtp.response_timeout' => 'Odpowiedź serwera SMTP przekroczyła limit czasu',
    'transport.not_serializable' => 'Transport zawierający dane uwierzytelniające nie może być serializowany ani ponownie importowany; twórz go tam, gdzie jest używany',
    'email.crlf_in_address' => 'Wykryto wstrzyknięcie CRLF w adresie e-mail',
    'email.invalid_address' => 'Nieprawidłowy adres e-mail: {addr}',
    'email.crlf_in_header' => 'Wykryto wstrzyknięcie CRLF w nagłówku {field}',
    'email.invalid_mime_type' => 'Nieprawidłowy typ MIME załącznika (oczekiwano typu/podtypu): {mime}',
    'email.invalid_header_char' => 'Nagłówek {field} zawiera niedozwolone znaki sterujące (tylko tekst drukowalny)',
    'resend.send_cancelled' => 'Wysyłanie Resend anulowane przed rozpoczęciem',
    'resend.curl_missing' => 'ext-curl jest wymagany dla transportu Resend (transport SMTP nie potrzebuje rozszerzeń dla zwykłego SMTP; ext-openssl dla TLS)',
    'resend.no_api_key' => 'Transport Resend nie ma klucza API: przekaż go konstruktorowi lub ustaw RESEND_API_KEY',
    'attachment.unreadable' => 'Plik załącznika nie jest do odczytu: {path}',
    'attachment.no_path' => 'Podczas dołączania według nazwy pliku należy podać ścieżkę pliku',
    'attachment.invalid_cid' => 'Nieprawidłowy format Content-ID: {cid} (RFC 2392 wymaga formatu msg-id)',
    'attachment.not_a_file' => 'Ścieżka załącznika {path} nie jest zwykłym plikiem',
    'attachment.too_large' => 'Załącznik przekracza limit {limit} bajtów',
    'cli.unknown_option' => 'Nieznana opcja: {option}',
    'cli.did_you_mean' => 'Czy chodziło o {suggestion}?',
    'cli.invalid_port' => 'Nieprawidłowy port SMTP: {port} (musi być między 1 a 65535)',
    'cli.missing_value' => 'Opcja {option} wymaga wartości (dla wartości zaczynających się od myślnika użyj --{option}=WARTOŚĆ)',
    'cli.no_sender' => 'Brak adresu nadawcy: podaj --from=ADRES albo ustaw CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => 'Zmienna środowiskowa {name} ma nieprawidłową wartość flagi {value}; użyj 1/true/yes/on albo 0/false/no/off',
    'cli.ca_file_unreadable' => 'Plik CA {file} nie jest zwykłym plikiem do odczytu',
    'cli.invalid_timeout' => 'Limit czasu {timeout} nie jest liczbą sekund od 1 do 3600',
    'cli.render_resend_divergence' => 'Uwaga: --render podgląda kopertę SMTP MIME; skonfigurowany transport to Resend, który wysyła ładunek JSON. Poniższe bajty nie są tym, co zostanie wysłane.',
];
