<?php

/**
 * Dutch translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Ongeldige SSH-vingerafdruk',
    'repo.create_dir_failed'   => 'Kon opslagplaatsmap niet maken: {path}',
    'repo.git_init_failed'     => 'git init mislukt: {output}',
    'config.not_found'         => 'Configuratiebestand niet gevonden: {path}',
    'config.read_failed'       => 'Kon configuratie niet lezen: {path}',
    'ssh.user_cannot_create'   => 'Gebruiker {viewer} kan geen opslagplaatsen maken',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Servers starten...',
    'cli.note_ssh2_required'   => '(Volledige daemon-modus vereist ssh2-extensie en draaiende SSH-daemon.)',
    'cli.note_run_init'        => "(Voer eerst 'candy-git-server init' uit om de gegevensmap te initialiseren.)",
    'cli.repos_header'         => 'Opslagplaatsen:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Nog niet als daemon gestart (daemon-modus vereist een procesmanager.)',
    'cli.note_http_help'       => 'Om Git via HTTP te serveren, wijs de webserver naar dit script.',
    'cli.note_ssh_help'        => 'Gebruik voor SSH-toegang een reverse tunnel of configureer sshd met ForceCommand.',
    'cli.already_initialized'  => 'Reeds geïnitialiseerd: {path}',
    'cli.initializing'         => 'CandyGitServer-gegevensmap initialiseren: {path}',
    'cli.done'                 => 'Klaar.',
    'cli.next_steps'           => 'Volgende stappen:',
    'cli.next_step_1'          => '  1. Bewerk {path}/config.yaml',
    'cli.next_step_2'          => '  2. Genereer SSH-hostkey: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Stel CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=uw-ssh-pubkey in',
    'cli.next_step_4'          => '  4. Start: candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => 'Gebruik: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Gebruik: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Gebruik: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Gebruiker '{username}' aangemaakt (admin: true)",
    'cli.user_key_hint'        => "Gebruik 'candy-git-server user key {username} < key.pub' om een SSH-sleutel toe te voegen.",
    'cli.user_key_read_failed' => 'Kon sleutel niet lezen uit: {file}',
    'cli.user_key_added'       => "Sleutel toegevoegd voor gebruiker '{username}'",
    'cli.user_keys_header'     => 'Geautoriseerde sleutels:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Gebruikers:\n  (Geen geregistreerde gebruikers. Gebruik 'candy-git-server user add <username>')",
    'cli.usage_repo_root'      => 'Gebruik: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Gebruik: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Gebruik: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Nog geen opslagplaatsen.',
    'cli.repo_listing_none'    => '  (nog geen opslagplaatsen)',
    'cli.repo_invalid_name'    => 'Ongeldige opslagplaatsnaam: {name} (gebruik alleen alfanumeriek, punt, underscore, koppelteken)',
    'cli.repo_created'         => "Opslagplaats '{name}' aangemaakt in {path}",
    'cli.repo_clone_url'       => 'Kloon-URL: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Opslagplaats niet gevonden: {name}',
    'cli.repo_info'            => "Naam:         {name}\nBeschrijving:  {description}\nOpenbaar:       {is_public}\nPrivé:      {is_private}\nPush toestaan:   {allow_push}\nPad:         {path}\nTakken:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'ja',
    'cli.bool_no'              => 'nee',
    'cli.none_value'           => '(geen)',
    'cli.unknown_command'      => "Onbekend commando: {cmd}\n  Voer 'candy-git-server help' uit voor hulp.",
    'cli.help'                 => "CandyGitServer — Zelfgehoste Git-server\n\nGebruik:\n  candy-git-server <commando> [opties]\n\nCommando's:\n  serve [--config path]    Start de Git-server\n  init [data-path]         Initialiseer een nieuwe gegevensmap\n  user add <username>      Maak een gebruiker aan\n  user key <username> <file>  Voeg SSH-sleutel toe voor gebruiker (gebruik - voor stdin)\n  user list                Lijst gebruikers\n  repo list [data-path]    Lijst opslagplaatsen\n  repo create <name> [data-path]  Maak een opslagplaats aan\n  repo info <name> [data-path]    Toon opslagplaatsinfo\n  help, --help, -h         Toon deze hulp\n  version, --version, -v   Toon versie\n\nOmgevingsvariabelen:\n  CANDY_GIT_SERVER_DATA_PATH    Gegevensmap (standaard: /tmp/candy-git-server)\n\nVoorbeelden:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mijn-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
