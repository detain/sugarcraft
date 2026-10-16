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
];
