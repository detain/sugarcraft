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
    'config.not_found'         => 'Fichier de configuration introuvable : {path}',
    'config.read_failed'       => 'Échec de la lecture de la configuration : {path}',
    'ssh.user_cannot_create'   => 'L\'utilisateur {viewer} ne peut pas créer de dépôts',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Démarrage des serveurs...',
    'cli.note_ssh2_required'   => '(Le mode daemon complet nécessite l\'extension ssh2 et un démon SSH en cours.)',
    'cli.note_run_init'        => "(Utilisez 'candy-git-server init' pour initialiser d'abord votre répertoire de données.)",
    'cli.repos_header'         => 'Dépôts :',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Pas encore en mode daemon (le mode daemon nécessite un gestionnaire de processus.)',
    'cli.note_http_help'       => 'Pour servir Git sur HTTP, pointez votre serveur web vers ce script.',
    'cli.note_ssh_help'        => 'Pour l\'accès SSH, utilisez un tunnel inverse ou configurez sshd avec ForceCommand.',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Déjà initialisé : {path}',
    'cli.initializing'         => 'Initialisation du répertoire de données CandyGitServer : {path}',
    'cli.done'                 => 'Terminé.',
    'cli.next_steps'           => 'Étapes suivantes :',
    'cli.next_step_1'          => '  1. Modifiez {path}/config.yaml',
    'cli.next_step_2'          => '  2. Générez une clé d\'hôte SSH : ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Définissez CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=votre-clé-publique-ssh',
    'cli.next_step_4'          => '  4. Exécutez : candy-git-server serve --config {path}/config.yaml',

    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Usage : candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Usage : candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Usage : candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Utilisateur '{username}' créé (admin : true)",
    'cli.user_key_hint'        => "Utilisez 'candy-git-server user key {username} < key.pub' pour ajouter une clé SSH publique.",
    'cli.user_key_read_failed' => 'Échec de la lecture de la clé depuis : {file}',
    'cli.user_key_added'       => "Clé ajoutée pour l'utilisateur '{username}'",
    'cli.user_keys_header'     => 'Clés autorisées :',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Utilisateurs :\n  (Aucun utilisateur enregistré. Utilisez 'candy-git-server user add <username>')",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Usage : candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Usage : candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Usage : candy-git-server repo info <name>',
    'cli.no_repos'             => 'Aucun dépôt pour le moment.',
    'cli.repo_listing_none'    => '  (aucun dépôt pour le moment)',
    'cli.repo_invalid_name'    => 'Nom de dépôt invalide : {name} (utilisez uniquement alphanumérique, point, tiret bas, tiret)',
    'cli.repo_created'         => "Dépôt '{name}' créé à {path}",
    'cli.repo_clone_url'       => 'URL de clone : ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Dépôt introuvable : {name}',
    'cli.repo_info'            => "Nom :         {name}\nDescription :  {description}\nPublic :       {is_public}\nPrivé :      {is_private}\nAutoriser push : {allow_push}\nChemin :         {path}\nBranches :     {branches}\nTags :         {tags}",
    'cli.bool_yes'             => 'oui',
    'cli.bool_no'              => 'non',
    'cli.none_value'           => '(aucune)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Commande inconnue : {cmd}\n  Exécutez 'candy-git-server help' pour l'aide.",
    'cli.help'                 => "CandyGitServer — Serveur Git auto-hébergeable\n\nUsage :\n  candy-git-server <commande> [options]\n\nCommandes :\n  serve [--config path]    Démarrer le serveur Git\n  init [data-path]         Initialiser un nouveau répertoire de données\n  user add <username>      Créer un utilisateur\n  user key <username> <file>  Ajouter une clé SSH publique pour l'utilisateur (utilisez - pour stdin)\n  user list                Lister les utilisateurs\n  repo list [data-path]    Lister les dépôts\n  repo create <name> [data-path]  Créer un dépôt\n  repo info <name> [data-path]    Afficher les informations d'un dépôt\n  help, --help, -h         Afficher cette aide\n  version, --version, -v   Afficher la version\n\nEnvironnement :\n  CANDY_GIT_SERVER_DATA_PATH    Répertoire de données (défaut : /tmp/candy-git-server)\n\nExemples :\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mon-projet\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
