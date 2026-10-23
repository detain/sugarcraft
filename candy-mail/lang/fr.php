<?php

/**
 * French translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient'        => 'L\'email doit avoir au moins un destinataire (to, cc ou bcc)',
    'mailer.no_from'             => 'L\'email doit avoir une adresse d\'expéditeur',

    // SmtpTransport.php
    'smtp.send_failed'           => 'Échec de l\'envoi SMTP : {message}',
    'smtp.connect_failed'        => 'Impossible de se connecter à {addr} : {errstr} ({errno})',
    'smtp.starttls_failed'       => 'Échec de la négociation STARTTLS',
    'smtp.not_connected'         => 'Non connecté',
    'smtp.no_response'           => 'Le serveur n\'a envoyé aucune réponse',
    'smtp.unexpected_response'   => 'Réponse SMTP inattendue : {response}',

    // ResendTransport.php
    'resend.network_error'       => 'Erreur réseau Resend : {error}',
    'resend.api_error'           => 'Erreur API Resend ({status}) : {body}',

    // bin/candy-mail
    'cli.error'                  => 'Erreur : {message}',
    'cli.transport_error'        => 'Erreur de transport : {message}',
    'cli.send_failed'            => 'Échec de l\'envoi : {message}',
    'cli.email_sent'             => '✓ Email envoyé via {transport}.',
    'cli.no_to_recipient'        => 'Aucun destinataire --to spécifié',
    'cli.attachment_not_found'   => 'Fichier de pièce jointe introuvable : {file}',
    'cli.no_transport'           => 'Aucun transport configuré. Définissez la variable d\'environnement RESEND_API_KEY ou CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Envoi annulé avant le démarrage',
    'smtp.tls_required'            => 'TLS est requis mais n’a pas pu être établi : {host} n’a pas proposé STARTTLS',
    'smtp.auth_insecure'           => 'Authentification refusée sur un canal SMTP non sécurisé (non chiffré)',
    'smtp.empty_response'          => 'Le serveur a envoyé une réponse vide',
    'smtp.send_cancelled'          => 'Envoi SMTP annulé avant le démarrage',
    'smtp.write_error'             => 'Échec de l’écriture vers le serveur SMTP',
    'smtp.incomplete_write'        => 'Écriture incomplète vers le serveur SMTP',
    'email.crlf_in_address'        => 'Injection CRLF détectée dans l’adresse e-mail',
    'email.invalid_address'        => 'Adresse e-mail invalide : {addr}',
    'email.crlf_in_header'         => 'Injection CRLF détectée dans l’en-tête {field}',
    'resend.send_cancelled'        => 'Envoi Resend annulé avant le démarrage',
    'resend.curl_missing'          => 'ext-curl est requis pour le transport Resend (le transport SMTP ne nécessite aucune extension)',
    'attachment.unreadable'        => 'Le fichier joint n’est pas lisible : {path}',
    'attachment.no_path'           => 'Un chemin de fichier doit être fourni lors de l’ajout par nom de fichier',
    'attachment.invalid_cid'       => 'Format de Content-ID invalide : {cid} (la RFC 2392 exige le format msg-id)',
    'cli.unknown_option'           => 'Option inconnue : {option}',
    'cli.invalid_port'             => 'Port SMTP invalide : {port} (doit être compris entre 1 et 65535)',
];
