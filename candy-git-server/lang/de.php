<?php

/**
 * German translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Ungültiges Format für öffentlichen SSH-Schlüssel',
    'repo.create_dir_failed'   => 'Repository-Verzeichnis konnte nicht erstellt werden: {path}',
    'repo.git_init_failed'     => 'git init fehlgeschlagen: {output}',
    'repo.visibility_marker_unwritable' => 'Schreiben des Sichtbarkeits-Markers fehlgeschlagen: {file}',
    'config.not_found'         => 'Konfigurationsdatei nicht gefunden: {path}',
    'config.read_failed'       => 'Konfiguration konnte nicht gelesen werden: {path}',
    'ssh.user_cannot_create'   => 'Benutzer {viewer} kann keine Repositories erstellen',
    'ssh.invalid_repo_name'    => 'Ungültiger Repository-Name: {name}',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Repositories:',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (öffentlich)',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Bereits initialisiert: {path}',
    'cli.initializing'         => 'CandyGitServer-Datenverzeichnis wird initialisiert: {path}',
    'cli.done'                 => 'Fertig.',
    'cli.next_steps'           => 'Nächste Schritte:',
    'cli.next_step_1'          => '  1. Konto anlegen: candy-git-server user add admin {path}',

    'cli.next_step_2'          => '  2. Repository anlegen: candy-git-server repo create <name> {path} (--public veröffentlicht es zum anonymen Lesen)',

    'cli.next_step_3'          => '  3. Über smart HTTP ausliefern: candy-git-server serve --http --repo-root {path}/repositories',
    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Nutzung: candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'Nutzung: candy-git-server user add <username> [data-path] [--password <pw>|-]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] HTTP-Proxy-Token-Signatur erzeugen',
    'cli.usage_user_key'       => 'Nutzung: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Benutzer '{username}' erstellt (admin: true)",
    'cli.user_password_stored' => 'Passwort als Hash gespeichert.',
    'cli.user_key_hint'        => "Verwenden Sie 'candy-git-server user key {username} <key-file|->', um einen SSH-Öffentlichen Schlüssel hinzuzufügen.",
    'cli.user_key_read_failed' => 'Schlüssel konnte nicht gelesen werden von: {file}',
    'cli.user_key_added'       => "Schlüssel für Benutzer '{username}' hinzugefügt",
    'cli.user_keys_header'     => 'Autorisierte Schlüssel:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Benutzer:\n  (Keine Benutzer registriert. Verwenden Sie 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Benutzer ({path}):",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Nutzung: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Nutzung: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Nutzung: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Noch keine Repositories.',
    'cli.repo_listing_none'    => '  (noch keine Repositories)',
    'cli.repo_invalid_name'    => 'Ungültiger Repository-Name: {name} (nur alphanumerisch, Punkt, Unterstrich, Bindestrich, darf nicht mit Punkt oder Bindestrich beginnen)',
    'cli.repo_created'         => "Repository '{name}' erstellt unter {path}",
    'cli.repo_not_found'       => 'Repository nicht gefunden: {name}',
    'cli.repo_info'            => "Name:         {name}\nBeschreibung:  {description}\nÖffentlich:       {is_public}\nPrivat:      {is_private}\nPush erlauben:   {allow_push}\nPfad:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'ja',
    'cli.bool_no'              => 'nein',
    'cli.none_value'           => '(keine)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Unbekannter Befehl: {cmd}\n  Führen Sie 'candy-git-server help' für die Hilfe aus.",
    'cli.help'                 => "CandyGitServer — Selbst-hostbarer Git-Server\n\nNutzung:\n  candy-git-server <befehl> [optionen]\n\nBefehle:\n  serve [--config path]    Git-Server starten\n  init [data-path]         Neues Datenverzeichnis initialisieren\n  user add <username>      Benutzer erstellen\n  user key <username> <file>  SSH-Öffentlicher Schlüssel für Benutzer hinzufügen (- für stdin)\n  user list                Benutzer auflisten\n  repo list [data-path]    Repositories auflisten\n  repo create <name> [data-path]  Repository erstellen\n  repo info <name> [data-path]    Repository-Informationen anzeigen\n  help, --help, -h         Diese Hilfe anzeigen\n  version, --version, -v   Version anzeigen\n\nUmgebung:\n  CANDY_GIT_SERVER_DATA_PATH    Datenverzeichnis (Standard: /tmp/candy-git-server)\n\nBeispiele:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mein-projekt\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'SSH-Public-Key-Blob zu kurz für {type}: {len} < {min} Base64-Zeichen',
    'config.parse_failed'   => 'Ungültige YAML-Konfiguration: {error}',
    'config.invalid_user_trust_mode' => 'Ungültiger http.user_trust_mode "{mode}": erwartet off, proxy oder token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path sind gesetzt, aber es ist noch kein HTTPS-Listener eingebunden; das setzt NICHT TLS in Kraft. Entfernen Sie die Einträge, bis TLS unterstützt wird.',
    'jobs.invalid_schedule' => 'Ungültiger Job-Plan "{schedule}": {reason}',
    'cli.option_needs_value' => 'Option {option} benötigt einen Wert',
    'cli.serve_http_needs_repo_root' => 'serve --http benötigt --repo-root VERZEICHNIS (das Verzeichnis, dessen Unterordner ausgeliefert werden)',
    'cli.serve_repo_root_not_dir' => '--repo-root ist kein Verzeichnis: {path}',
    'cli.serve_http_port_range' => '--http-port außerhalb des Bereichs: {port}',
    'cli.serve_http_banner' => 'smart-HTTP-Listener auf {addr} ({repos} Repos, {published} veröffentlicht, {users} Konten; anonymes Lesen={anon}; Push wird ausgeliefert — Hook-Ablehnungen laufen im Band)',
];
