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
    'cli.next_step_1'          => '  1. Konto anlegen: candy-git-server user add --admin admin {path}',

    'cli.next_step_2'          => '  2. Repository anlegen: candy-git-server repo create <name> {path} (--public veröffentlicht es zum anonymen Lesen)',

    'cli.next_step_3'          => '  3. Über smart HTTP ausliefern: candy-git-server serve --http --repo-root {path}/repositories',
    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Nutzung: candy-git-server user add|key|list|token|remove|deactivate',
    'cli.usage_user_add'       => 'Nutzung: candy-git-server user add <username> [data-path] [--password <pw>|-] [--admin|--read]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] HTTP-Proxy-Token-Signatur erzeugen',
'cli.usage_user_key'       => 'Nutzung: candy-git-server user key <username> [key-file]',    'cli.usage_user_remove' => 'Verwendung: candy-git-server user remove <username> [data-path]',
    'cli.usage_user_deactivate' => 'Verwendung: candy-git-server user deactivate <username> [data-path]',
    'cli.user_removed' => "Benutzer '{username}' aus {path} entfernt",
    'cli.user_deactivated' => "Benutzer '{username}' deaktiviert (active: {active})",
    'cli.user_already_deactivated_note' => "Benutzer '{username}' war bereits inaktiv; außer dem erneuten Veröffentlichen der Zeile hat sich nichts geändert.",
    'cli.user_created'         => "Benutzer '{username}' erstellt (admin: {admin})",
    // S21: least-privilege `user add` flags (finding 036).
    'cli.user_add_flags_conflict' => '--admin und --read schließen sich gegenseitig aus.',
    'cli.user_password_stored' => 'Passwort als Hash gespeichert.',
    'cli.user_add_no_password' => 'warnung: \'{username}\' hat noch kein Passwort und keinen SSH-Schlüssel; HTTP-Push und SSH lehnen es ab, bis \'user key\' oder ein --password gesetzt wird',
    'cli.user_key_hint'        => "Verwenden Sie 'candy-git-server user key {username} <key-file|->', um einen SSH-Öffentlichen Schlüssel hinzuzufügen.",
    'cli.user_key_read_failed' => 'Schlüssel konnte nicht gelesen werden von: {file}',
    'cli.user_key_added'       => "Schlüssel für Benutzer '{username}' hinzugefügt",
    'cli.user_keys_header'     => 'Autorisierte Schlüssel:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Benutzer:\n  (Keine Benutzer registriert. Verwenden Sie 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Benutzer ({path}):",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Nutzung: candy-git-server repo list|create|info|collaborator|mirror|push-access',
    'cli.usage_repo_create'    => 'Nutzung: candy-git-server repo create <name>',
'cli.usage_repo_info'      => 'Nutzung: candy-git-server repo info <name>',
    'cli.usage_repo_collaborator' => 'Verwendung: candy-git-server repo collaborator <name> add|remove <user> [data-path]',
    'cli.usage_repo_mirror' => 'Verwendung: candy-git-server repo mirror <name> <url>|--clear [data-path]',
    'cli.usage_repo_push_access' => 'Verwendung: candy-git-server repo push-access <name> allow|deny [data-path]',
    'cli.repo_info_plane' => 'Mitwirkende:{collaborators}
Spiegel:      {mirror}
Hervorhebung:   {highlight}
Letzter Spiegel-Versuch: {mirror_last_try}',
    'cli.repo_collaborator_added' => "Repo '{name}': Mitwirkender '{user}' hinzugefügt (Stand: {plane})",
    'cli.repo_collaborator_removed' => "Repo '{name}': Mitwirkender '{user}' entfernt (Stand: {plane})",
    'cli.repo_mirror_set' => "Repo '{name}': spiegelt Upstream {url} (fällig beim nächsten Takt)",
    'cli.repo_mirror_cleared' => "Repo '{name}': Spiegel-Upstream gelöscht",
    'cli.repo_mirror_refused' => 'Spiegel-Upstream abgelehnt: {why}',
    'cli.repo_push_access_set' => "Repo '{name}': Push ohne Schreibrecht ist jetzt {value}",
    'cli.no_repos'             => 'Noch keine Repositories.',
    'cli.repo_listing_none'    => '  (noch keine Repositories)',
    'cli.repo_invalid_name'    => 'Ungültiger Repository-Name: {name} (nur alphanumerisch, Punkt, Unterstrich, Bindestrich, darf nicht mit Punkt oder Bindestrich beginnen)',
    'cli.repo_created'         => "Repository '{name}' erstellt unter {path}",
    'cli.repo_exists' => "Repository '{name}' existiert bereits in {path}",
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
    'cli.serve_http_port_not_a_number' => 'serve --http: --http-port muss eine Zahl sein, erhalten: {port}',
    'cli.serve_http_flag_unsupported' => 'Flag {flag} wird von serve --http nicht unterstützt (der Listener läuft im Vordergrund; Daemonisierung per Supervisor).',
    'cli.serve_http_unknown_flag' => 'Unbekanntes Flag für serve --http: {flag}',
    'cli.port_deprecated'      => 'Hinweis: --port ist veraltet; bitte --http-port verwenden (Alias zur Kompatibilität behalten).',
    'cli.serve_http_banner' => 'smart-HTTP-Listener auf {addr} ({repos} Repos, {published} veröffentlicht, {users} Konten; anonymes Lesen={anon}; Push wird ausgeliefert — Hook-Ablehnungen laufen im Band)',

// bin/candy-git-server — Step 17: remaining CLI refusals
    'cli.init_temp_open_failed' => 'init: temporäre Konfiguration konnte nicht geöffnet werden: {tmp}',
    'cli.init_publish_failed' => 'init: Konfiguration konnte nicht veröffentlicht werden: {data}/config.yaml',
    'cli.warn_tmp_data_path' => 'Warnung: Datenpfad {path} liegt im System-Temp-Verzeichnis; lokale Nutzer können ihn einsehen, Temp-Cleaner können ihn löschen — für echte Deployments CANDY_GIT_SERVER_DATA_PATH setzen',
    'cli.init_foreign_owner' => 'init: Übernahme von {path} abgelehnt — Eigentümer ist UID {uid}; als diesen Nutzer ausführen oder einen eigenen Datenpfad wählen',
    'cli.repo_root_unreadable' => 'serve: Repository-Wurzel {path} ist nicht lesbar',
    'cli.token_no_config' => 'token: keine lesbare Konfiguration unter {data}/config.yaml — führen Sie init aus oder übergeben Sie einen Datenpfad',
    'cli.token_no_secret' => 'token: http.auth_secret ist für diesen Datenpfad nicht konfiguriert; setzen Sie ihn im Abschnitt http: von config.yaml, bevor Sie Tokens ausstellen',
    'cli.token_ttl_capped' => 'token: die angeforderte Laufzeit von {ttl}s ueberschreitet die unter {key} konfigurierte Obergrenze von {max}s; verringern Sie die Laufzeit oder erhohen Sie diesen Schluessel in config.yaml',
    'cli.password_flag_empty' => 'candy-git-server: --password benötigt einen nicht-leeren Wert',
    'cli.password_argv_exposed' => 'warnung: das auf der kommandozeile übergebene Passwort ist während bcrypt in Prozesslisten (ps /proc/pid/cmdline) sichtbar; --password ohne Wert liest es von STDIN',
    'cli.username_invalid' => 'Ungültiger Benutzername: \'{username}\' (Buchstaben, Ziffern, Punkt, Unterstrich, Bindestrich; nicht nur Punkte)',
    'cli.user_exists' => 'Benutzer \'{username}\' existiert bereits in {path}',
    'cli.user_not_found' => 'Kein solcher Benutzer \'{username}\' in {path} — führen Sie zuerst \'candy-git-server user add {username}\' aus.',
];
