<?php

/**
 * German translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Ungültiges SSH-Öffentlicher-Schlüssel-Format',
    'repo.create_dir_failed'   => 'Repository-Verzeichnis konnte nicht erstellt werden: {path}',
    'repo.git_init_failed'     => 'git init fehlgeschlagen: {output}',
    'config.not_found'         => 'Konfigurationsdatei nicht gefunden: {path}',
    'config.read_failed'       => 'Konfiguration konnte nicht gelesen werden: {path}',
    'ssh.user_cannot_create'   => 'Benutzer {viewer} kann keine Repositories erstellen',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Server werden gestartet...',
    'cli.note_ssh2_required'   => '(Voller Daemon-Modus erfordert die ssh2-Erweiterung und einen laufenden SSH-Daemon.)',
    'cli.note_run_init'        => "(Verwenden Sie 'candy-git-server init', um zuerst Ihr Datenverzeichnis zu initialisieren.)",
    'cli.repos_header'         => 'Repositories:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Noch nicht als Daemon aktiv (Daemon-Modus benötigt einen Prozessmanager.)',
    'cli.note_http_help'       => 'Um Git über HTTP bereitzustellen, zeigen Sie Ihren Webserver auf dieses Skript.',
    'cli.note_ssh_help'        => 'Für SSH-Zugriff verwenden Sie einen Reverse-Tunnel oder konfigurieren Sie sshd mit ForceCommand.',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Bereits initialisiert: {path}',
    'cli.initializing'         => 'CandyGitServer-Datenverzeichnis wird initialisiert: {path}',
    'cli.done'                 => 'Fertig.',
    'cli.next_steps'           => 'Nächste Schritte:',
    'cli.next_step_1'          => '  1. Bearbeiten Sie {path}/config.yaml',
    'cli.next_step_2'          => '  2. SSH-Hostschlüssel generieren: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Setzen Sie CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=Ihr-ssh-öffentlicherschlüssel',
    'cli.next_step_4'          => '  4. Ausführen: candy-git-server serve --config {path}/config.yaml',

    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Nutzung: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Nutzung: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Nutzung: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Benutzer '{username}' erstellt (admin: true)",
    'cli.user_key_hint'        => "Verwenden Sie 'candy-git-server user key {username} < key.pub', um einen SSH-Öffentlichen Schlüssel hinzuzufügen.",
    'cli.user_key_read_failed' => 'Schlüssel konnte nicht gelesen werden von: {file}',
    'cli.user_key_added'       => "Schlüssel für Benutzer '{username}' hinzugefügt",
    'cli.user_keys_header'     => 'Autorisierte Schlüssel:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Benutzer:\n  (Keine Benutzer registriert. Verwenden Sie 'candy-git-server user add <username>')",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Nutzung: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Nutzung: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Nutzung: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Noch keine Repositories.',
    'cli.repo_listing_none'    => '  (noch keine Repositories)',
    'cli.repo_invalid_name'    => 'Ungültiger Repository-Name: {name} (nur alphanumerisch, Punkt, Unterstrich, Bindestrich)',
    'cli.repo_created'         => "Repository '{name}' erstellt unter {path}",
    'cli.repo_clone_url'       => 'Clone-URL: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Repository nicht gefunden: {name}',
    'cli.repo_info'            => "Name:         {name}\nBeschreibung:  {description}\nÖffentlich:       {is_public}\nPrivat:      {is_private}\nPush erlauben:   {allow_push}\nPfad:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'ja',
    'cli.bool_no'              => 'nein',
    'cli.none_value'           => '(keine)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Unbekannter Befehl: {cmd}\n  Führen Sie 'candy-git-server help' für die Hilfe aus.",
    'cli.help'                 => "CandyGitServer — Selbst-hostbarer Git-Server\n\nNutzung:\n  candy-git-server <befehl> [optionen]\n\nBefehle:\n  serve [--config path]    Git-Server starten\n  init [data-path]         Neues Datenverzeichnis initialisieren\n  user add <username>      Benutzer erstellen\n  user key <username> <file>  SSH-Öffentlicher Schlüssel für Benutzer hinzufügen (- für stdin)\n  user list                Benutzer auflisten\n  repo list [data-path]    Repositories auflisten\n  repo create <name> [data-path]  Repository erstellen\n  repo info <name> [data-path]    Repository-Informationen anzeigen\n  help, --help, -h         Diese Hilfe anzeigen\n  version, --version, -v   Version anzeigen\n\nUmgebung:\n  CANDY_GIT_SERVER_DATA_PATH    Datenverzeichnis (Standard: /tmp/candy-git-server)\n\nBeispiele:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mein-projekt\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
