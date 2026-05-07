<?php

/**
 * Italian translations for sugar-post.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'L\'email deve avere almeno un destinatario (to, cc o bcc)',
    'mailer.no_from'             => 'L\'email deve avere un indirizzo mittente',
    'smtp.send_failed'           => 'Invio SMTP fallito: {message}',
    'smtp.connect_failed'        => 'Impossibile connettersi a {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'Negoziazione STARTTLS fallita',
    'smtp.not_connected'         => 'Non connesso',
    'smtp.no_response'           => 'Il server non ha inviato risposta',
    'smtp.unexpected_response'   => 'Risposta SMTP inattesa: {response}',
    'resend.network_error'       => 'Errore di rete Resend: {error}',
    'resend.api_error'           => 'Errore API Resend ({status}): {body}',
    'cli.error'                  => 'Errore: {message}',
    'cli.transport_error'        => 'Errore di trasporto: {message}',
    'cli.send_failed'            => 'Invio fallito: {message}',
    'cli.email_sent'             => '✓ Email inviata tramite {transport}.',
    'cli.no_to_recipient'        => 'Nessun destinatario --to specificato',
    'cli.attachment_not_found'   => 'File allegato non trovato: {file}',
    'cli.no_transport'           => 'Nessun trasporto configurato. Impostare la variabile d\'ambiente RESEND_API_KEY o POP_SMTP_HOST.',
];
