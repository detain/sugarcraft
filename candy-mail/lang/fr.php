<?php

/**
 * French translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient' => 'L’e-mail doit avoir au moins un destinataire (to, cc ou bcc)',
    'mailer.no_from' => 'L’e-mail doit avoir une adresse d’expéditeur',

    // SmtpTransport.php
    'smtp.send_failed' => 'Échec de l’envoi SMTP : {message}',
    'smtp.connect_failed' => 'Impossible de se connecter à {addr} : {errstr} ({errno})',
    'smtp.starttls_failed' => 'Échec de la négociation STARTTLS',
    'smtp.not_connected' => 'Non connecté',
    'smtp.no_response' => 'Le serveur n’a envoyé aucune réponse',
    'smtp.unexpected_response' => 'Réponse SMTP inattendue : {response}',

    // ResendTransport.php
    'resend.network_error' => 'Erreur réseau Resend : {error}',
    'resend.api_error' => 'Erreur API Resend ({status}) : {body}',

    // bin/candy-mail
    'cli.error' => 'Erreur : {message}',
    'cli.transport_error' => 'Erreur de transport : {message}',
    'cli.send_failed' => 'Échec de l’envoi : {message}',
    'cli.email_sent' => '✓ E-mail envoyé via {transport}.',
    'cli.no_to_recipient' => 'Aucun destinataire --to spécifié',
    'cli.attachment_not_found' => 'Fichier de pièce jointe introuvable : {file}',
    'cli.no_transport' => 'Aucun transport configuré. Définissez la variable d’environnement RESEND_API_KEY ou CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Envoi annulé avant le démarrage',
    'smtp.tls_required' => 'TLS est requis mais n’a pas pu être établi : {host} n’a pas proposé STARTTLS',
    'smtp.auth_insecure' => 'Authentification refusée sur un canal SMTP non sécurisé (non chiffré)',
    'smtp.empty_response' => 'Le serveur a envoyé une réponse vide',
    'smtp.send_cancelled' => 'Envoi SMTP annulé avant le démarrage',
    'smtp.write_error' => 'Échec de l’écriture vers le serveur SMTP',
    'smtp.incomplete_write' => 'Écriture incomplète vers le serveur SMTP',
    'smtp.invalid_helo_host' => 'L’hôte HELO {helo} n’est pas un nom d’hôte valide (lettres, chiffres, points et tirets uniquement)',
    'smtp.auth_unavailable' => 'Le serveur SMTP {host} n’a pas annoncé AUTH ; refus de relayer sans authentification alors que des identifiants sont configurés',
    'smtp.response_too_long' => 'La réponse du serveur SMTP a dépassé la limite de taille',
    'smtp.response_timeout' => 'La réponse du serveur SMTP a dépassé le budget de temps',
    'transport.not_serializable' => 'Un transport contenant des identifiants ne peut être sérialisé ni réimporté ; construisez-le là où il est utilisé',
    'email.crlf_in_address' => 'Injection CRLF détectée dans l’adresse e-mail',
    'email.invalid_address' => 'Adresse e-mail invalide : {addr}',
    'email.crlf_in_header' => 'Injection CRLF détectée dans l’en-tête {field}',
    'email.invalid_mime_type' => 'Type MIME de pièce jointe invalide (type/sous-type attendu) : {mime}',
    'email.invalid_header_name' => "Nom d’en-tête invalide : {name} (lettres, chiffres et caractères !#$%&'*+-.^_`|~ uniquement)",
    'email.reserved_header' => 'L’en-tête {field} appartient au jeu fixe et ne peut pas être défini via withHeader',
    'email.invalid_header_char' => 'L’en-tête {field} contient des caractères de contrôle non autorisés (texte imprimable uniquement)',
    'resend.send_cancelled' => 'Envoi Resend annulé avant le démarrage',
    'resend.curl_missing' => 'ext-curl est requis pour le transport Resend (le transport SMTP ne nécessite aucune extension pour SMTP simple ; ext-openssl est requis pour TLS)',
    'resend.no_api_key' => 'Aucune clé API pour le transport Resend : passez-la au constructeur ou définissez RESEND_API_KEY',
    'attachment.unreadable' => 'Le fichier joint n’est pas lisible : {path}',
    'attachment.no_path' => 'Un chemin de fichier doit être fourni lors de l’ajout par nom de fichier',
    'attachment.invalid_cid' => 'Format de Content-ID invalide : {cid} (autorisés : msg-id RFC 2392 user@host, préfixe cid: facultatif ou identifiant simple)',
    'attachment.not_a_file' => "Le chemin de la pièce jointe {path} n’est pas un fichier régulier",
    'attachment.too_large' => 'La pièce jointe dépasse la limite de {limit} octets',
    'cli.unknown_option' => 'Option inconnue : {option}',
    'cli.did_you_mean' => 'Vouliez-vous dire {suggestion} ?',
    'cli.invalid_port' => 'Port SMTP invalide : {port} (doit être compris entre 1 et 65535)',
    'cli.missing_value' => "L’option {option} requiert une valeur (pour les valeurs commençant par un tiret, écrivez --{option}=VALEUR)",
    'cli.no_sender' => "Adresse d’expéditeur manquante : passez --from=ADRESSE ou définissez CANDY_MAIL_FROM",
    'cli.invalid_env_flag' => "La variable d’environnement {name} a une valeur d’indicateur invalide {value} ; utilisez 1/true/yes/on ou 0/false/no/off",
    'cli.ca_file_unreadable' => "Le fichier CA {file} n’est pas un fichier régulier lisible",
    'cli.invalid_timeout' => "Le délai {timeout} n’est pas un nombre de secondes entre 1 et 3600",
    'cli.render_resend_divergence' => "Remarque : --render prévisualise l’enveloppe SMTP MIME ; le transport configuré est Resend, qui publie une charge JSON. Les octets ci-dessous ne sont pas ce qui sera envoyé.",
    'cli.empty_message' => 'Rien à envoyer : le message ne contient ni corps, ni partie HTML, ni pièce jointe. Utilisez --body, transmettez l’entrée par tube ou employez --html.',
    'cli.deprecated_env' => '{old} est déprécié ; définissez {new} à la place.',
    'cli.resend_ignored_inputs' => 'Remarque : le transport Resend a été sélectionné, les entrées propres à SMTP {inputs} sont donc ignorées (Resend conserve ses budgets fixes de 10 s de connexion / 30 s de transfert).',
    'cli.invalid_transport' => 'Valeur --transport inconnue : {transport} (autorisées : smtp, resend)',
    'cli.resend_key_required' => '--transport=resend nécessite une clé API : définissez RESEND_API_KEY (l’hôte SMTP est ignoré par cette option)',
    'cli.stdin_too_large' => 'L’entrée STDIN a dépassé la limite de {limit} octets ; réduisez l’entrée ou joignez-la comme fichier.',
];
