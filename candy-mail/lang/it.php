<?php

/**
 * Italian translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => 'L\'email deve avere almeno un destinatario (to, cc o bcc)',
    'mailer.no_from' => 'L\'email deve avere un indirizzo mittente',
    'smtp.send_failed' => 'Invio SMTP fallito: {message}',
    'smtp.connect_failed' => 'Impossibile connettersi a {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'Negoziazione STARTTLS fallita',
    'smtp.not_connected' => 'Non connesso',
    'smtp.no_response' => 'Il server non ha inviato risposta',
    'smtp.unexpected_response' => 'Risposta SMTP inattesa: {response}',
    'resend.network_error' => 'Errore di rete Resend: {error}',
    'resend.api_error' => 'Errore API Resend ({status}): {body}',
    'cli.error' => 'Errore: {message}',
    'cli.transport_error' => 'Errore di trasporto: {message}',
    'cli.send_failed' => 'Invio fallito: {message}',
    'cli.email_sent' => '✓ Email inviata tramite {transport}.',
    'cli.no_to_recipient' => 'Nessun destinatario --to specificato',
    'cli.attachment_not_found' => 'File allegato non trovato: {file}',
    'cli.no_transport' => 'Nessun trasporto configurato. Impostare la variabile d\'ambiente RESEND_API_KEY o CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Invio annullato prima dell’inizio',
    'smtp.tls_required' => 'TLS è obbligatorio ma non ha potuto essere stabilito: {host} non offriva STARTTLS',
    'smtp.auth_insecure' => 'Autenticazione rifiutata su un canale SMTP non sicuro (non cifrato)',
    'smtp.empty_response' => 'Il server ha inviato una risposta vuota',
    'smtp.send_cancelled' => 'Invio SMTP annullato prima dell’inizio',
    'smtp.write_error' => 'Scrittura sul server SMTP non riuscita',
    'smtp.incomplete_write' => 'Scrittura incompleta sul server SMTP',
    'smtp.invalid_helo_host' => 'L\'host HELO {helo} non è un hostname valido (solo lettere, cifre, punti e trattini)',
    'smtp.auth_unavailable' => 'Il server SMTP {host} non ha pubblicizzato AUTH; rifiuto di inoltrare senza autenticazione quando le credenziali sono configurate',
    'smtp.response_too_long' => 'La risposta del server SMTP ha superato il limite di dimensione',
    'smtp.response_timeout' => 'La risposta del server SMTP ha superato il budget di tempo',
    'transport.not_serializable' => 'Un transport che contiene credenziali non può essere serializzato o reimportato; costruiscilo dove viene utilizzato',
    'email.crlf_in_address' => 'Rilevata iniezione CRLF nell’indirizzo e-mail',
    'email.invalid_address' => 'Indirizzo e-mail non valido: {addr}',
    'email.crlf_in_header' => 'Rilevata iniezione CRLF nell’intestazione {field}',
    'resend.send_cancelled' => 'Invio Resend annullato prima dell’inizio',
    'resend.curl_missing' => 'ext-curl è obbligatorio per il trasporto Resend (il trasporto SMTP non richiede estensioni)',
    'attachment.unreadable' => 'Il file allegato non è leggibile: {path}',
    'attachment.no_path' => 'Il percorso del file deve essere indicato quando si allega per nome file',
    'attachment.invalid_cid' => 'Formato Content-ID non valido: {cid} (RFC 2392 richiede il formato msg-id)',
    'attachment.not_a_file' => "Il percorso dell'allegato {path} non è un file regolare",
    'attachment.too_large' => "L'allegato supera il limite di {limit} byte",
    'cli.unknown_option' => 'Opzione sconosciuta: {option}',
    'cli.did_you_mean' => 'Forse intendevi {suggestion}?',
    'cli.invalid_port' => 'Porta SMTP non valida: {port} (deve essere compresa tra 1 e 65535)',
    'cli.missing_value' => "L'opzione {option} richiede un valore (per valori che iniziano con un trattino scrivere --{option}=VALORE)",
    'cli.no_sender' => 'Nessun indirizzo mittente: passare --from=INDIRIZZO o impostare CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => "La variabile d'ambiente {name} ha un valore di opzione non valido {value}; usare 1/true/yes/on oppure 0/false/no/off",
    'cli.ca_file_unreadable' => 'Il file CA {file} non è un file regolare leggibile',
    'cli.invalid_timeout' => 'Il timeout {timeout} non è un numero di secondi tra 1 e 3600',
    'cli.render_resend_divergence' => "Nota: --render mostra l'anteprima della busta SMTP MIME; il trasporto configurato è Resend, che invia un payload JSON. I byte seguenti non sono ciò che verrà spedito.",
];
