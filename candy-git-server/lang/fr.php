<?php

/**
 * French translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Format de clé SSH publique invalide',
    'repo.create_dir_failed'   => 'Échec de la création du répertoire du dépôt : {path}',
    'repo.git_init_failed'     => 'Échec de git init : {output}',
    'repo.visibility_marker_unwritable' => 'Écriture du marqueur de visibilité impossible : {file}',
    'config.not_found'         => 'Fichier de configuration introuvable : {path}',
    'config.read_failed'       => 'Échec de la lecture de la configuration : {path}',
    'ssh.user_cannot_create'   => 'L\'utilisateur {viewer} ne peut pas créer de dépôts',
    'ssh.invalid_repo_name'    => 'Nom de dépôt invalide : {name}',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Dépôts :',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (public)',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Déjà initialisé : {path}',
    'cli.initializing'         => 'Initialisation du répertoire de données CandyGitServer : {path}',
    'cli.done'                 => 'Terminé.',
    'cli.next_steps'           => 'Étapes suivantes :',
    'cli.next_step_1'          => '  1. Créer un compte : candy-git-server user add --admin admin {path}',

    'cli.next_step_2'          => '  2. Créer un dépôt : candy-git-server repo create <nom> {path} (ajouter --public pour l’exposer en lecture anonyme)',

    'cli.next_step_3'          => '  3. Servir via smart HTTP : candy-git-server serve --http --repo-root {path}/repositories',
    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Usage : candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'Usage : candy-git-server user add <username> [data-path] [--password <pw>|-] [--admin|--read]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] générer une signature de jeton de proxy HTTP',
    'cli.usage_user_key'       => 'Usage : candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Utilisateur '{username}' créé (admin : {admin})",
    // S21: least-privilege `user add` flags (finding 036).
    'cli.user_add_flags_conflict' => '--admin et --read sont mutuellement exclusifs.',
    'cli.user_password_stored' => 'Mot de passe stocké sous forme de hachage.',
    'cli.user_key_hint'        => "Utilisez 'candy-git-server user key {username} <key-file|->' pour ajouter une clé SSH publique.",
    'cli.user_key_read_failed' => 'Échec de la lecture de la clé depuis : {file}',
    'cli.user_key_added'       => "Clé ajoutée pour l'utilisateur '{username}'",
    'cli.user_keys_header'     => 'Clés autorisées :',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Utilisateurs :\n  (Aucun utilisateur enregistré. Utilisez 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Utilisateurs ({path}) :",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Usage : candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Usage : candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Usage : candy-git-server repo info <name>',
    'cli.no_repos'             => 'Aucun dépôt pour le moment.',
    'cli.repo_listing_none'    => '  (aucun dépôt pour le moment)',
    'cli.repo_invalid_name'    => 'Nom de dépôt invalide : {name} (utilisez uniquement alphanumérique, point, tiret bas, tiret, ne doit pas commencer par un point ou un tiret)',
    'cli.repo_created'         => "Dépôt '{name}' créé à {path}",
    'cli.repo_exists' => "Le dépôt '{name}' existe déjà dans {path}",
    'cli.repo_not_found'       => 'Dépôt introuvable : {name}',
    'cli.repo_info'            => "Nom :         {name}\nDescription :  {description}\nPublic :       {is_public}\nPrivé :      {is_private}\nAutoriser push : {allow_push}\nChemin :         {path}\nBranches :     {branches}\nTags :         {tags}",
    'cli.bool_yes'             => 'oui',
    'cli.bool_no'              => 'non',
    'cli.none_value'           => '(aucune)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Commande inconnue : {cmd}\n  Exécutez 'candy-git-server help' pour l'aide.",
    'cli.help'                 => "CandyGitServer — Serveur Git auto-hébergeable\n\nUsage :\n  candy-git-server <commande> [options]\n\nCommandes :\n  serve [--config path]    Démarrer le serveur Git\n  init [data-path]         Initialiser un nouveau répertoire de données\n  user add <username>      Créer un utilisateur\n  user key <username> <file>  Ajouter une clé SSH publique pour l'utilisateur (utilisez - pour stdin)\n  user list                Lister les utilisateurs\n  repo list [data-path]    Lister les dépôts\n  repo create <name> [data-path]  Créer un dépôt\n  repo info <name> [data-path]    Afficher les informations d'un dépôt\n  help, --help, -h         Afficher cette aide\n  version, --version, -v   Afficher la version\n\nEnvironnement :\n  CANDY_GIT_SERVER_DATA_PATH    Répertoire de données (défaut : /tmp/candy-git-server)\n\nExemples :\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mon-projet\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'blob de clé publique SSH trop court pour {type} : {len} < {min} caractères base64',
    'config.parse_failed'   => 'configuration YAML invalide : {error}',
    'config.invalid_user_trust_mode' => 'http.user_trust_mode « {mode} » invalide : attendu off, proxy ou token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path sont définis mais aucun listener HTTPS n’est câblé ; les définir n’active PAS TLS. Retirez-les tant que TLS n’est pas pris en charge.',
    'jobs.invalid_schedule' => 'planification de job invalide « {schedule} » : {reason}',
    'cli.option_needs_value' => 'l’option {option} exige une valeur',
    'cli.serve_http_needs_repo_root' => 'serve --http exige --repo-root REP (le répertoire dont les sous-répertoires sont servis)',
    'cli.serve_repo_root_not_dir' => '--repo-root n’est pas un répertoire : {path}',
    'cli.serve_http_port_range' => '--http-port hors plage : {port}',
    'cli.serve_http_port_not_a_number' => 'serve --http : --http-port doit être un nombre, reçu : {port}',
    'cli.serve_http_flag_unsupported' => 'L’option {flag} n’est pas prise en charge par serve --http (l’écouteur tourne au premier plan ; daemonifiez via un superviseur).',
    'cli.serve_http_unknown_flag' => 'Option inconnue pour serve --http : {flag}',
    'cli.port_deprecated'      => 'Note : --port est déprécié ; utilisez --http-port (alias conservé pour compatibilité).',
    'cli.serve_http_banner' => 'listener smart-HTTP sur {addr} ({repos} dépôts, {published} publiés, {users} comptes ; lecture anonyme={anon} ; le push est servi — les refus d’accroches passent en bande)',

// bin/candy-git-server — Step 17: remaining CLI refusals
    'cli.init_temp_open_failed' => 'init : impossible d\'ouvrir la configuration temporaire dans {tmp}',
    'cli.init_publish_failed' => 'init : impossible de publier la configuration dans {data}/config.yaml',
    'cli.warn_tmp_data_path' => 'avertissement : le chemin de données {path} se trouve dans le répertoire temporaire du système ; les utilisateurs locaux peuvent le lister et les nettoyeurs temporaires peuvent le supprimer — définir CANDY_GIT_SERVER_DATA_PATH pour les déploiements réels',
    'cli.init_foreign_owner' => 'init : adoption de {path} refusée — propriétaire : UID {uid} ; exécuter en tant que cet utilisateur ou choisir un chemin de données possédé',
    'cli.repo_root_unreadable' => 'serve : impossible de lire la racine de dépôt {path}',
    'cli.token_no_config' => 'token : aucune configuration lisible dans {data}/config.yaml — exécutez init ou passez un chemin de données',
    'cli.token_no_secret' => 'token : http.auth_secret n\'est pas configuré pour ce chemin de données ; définissez-le dans la section http: de config.yaml avant de créer des jetons',
    'cli.token_ttl_capped' => 'token : le TTL demande de {ttl}s depasse le plafond de {max}s configure sous {key} ; reduisez le TTL ou relevez cette cle dans config.yaml',
    'cli.password_flag_needs_value' => 'candy-git-server : --password nécessite une valeur (\'-\' lit depuis stdin)',
    'cli.password_flag_empty' => 'candy-git-server : --password nécessite une valeur non vide',
    'cli.username_invalid' => 'Nom d\'utilisateur invalide : \'{username}\' (lettres, chiffres, point, souligné, trait d\'union ; pas uniquement des points)',
    'cli.user_exists' => 'L\'utilisateur \'{username}\' existe déjà dans {path}',
    'cli.user_not_found' => 'Utilisateur \'{username}\' introuvable dans {path} — exécutez d\'abord \'candy-git-server user add {username}\'.',
];
