<?php

/**
 * Polish translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Nieprawidłowy format klucza publicznego SSH',
    'repo.create_dir_failed'   => 'Nie udało się utworzyć katalogu repozytorium: {path}',
    'repo.git_init_failed'     => 'git init nie powiodło się: {output}',
    'config.not_found'         => 'Nie znaleziono pliku konfiguracyjnego: {path}',
    'config.read_failed'       => 'Nie udało się odczytać konfiguracji: {path}',
    'ssh.user_cannot_create'   => 'Użytkownik {viewer} nie może tworzyć repozytoriów',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Uruchamianie serwerów...',
    'cli.note_ssh2_required'   => '(Pełny tryb demona wymaga rozszerzenia ssh2 i uruchomionego demona SSH.)',
    'cli.note_run_init'        => "(Najpierw uruchom 'candy-git-server init', aby zainicjować katalog danych.)",
    'cli.repos_header'         => 'Repozytoria:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Nie jest jeszcze uruchomiony jako demon (tryb demona wymaga menedżera procesów.)',
    'cli.note_http_help'       => 'Aby udostępniać Git przez HTTP, skieruj serwer WWW na ten skrypt.',
    'cli.note_ssh_help'        => 'Aby uzyskać dostęp SSH, użyj odwrotnego tunelu lub skonfiguruj sshd z ForceCommand.',
    'cli.already_initialized'  => 'Już zainicjowane: {path}',
    'cli.initializing'         => 'Inicjowanie katalogu danych CandyGitServer: {path}',
    'cli.done'                 => 'Gotowe.',
    'cli.next_steps'           => 'Następne kroki:',
    'cli.next_step_1'          => '  1. Edytuj {path}/config.yaml',
    'cli.next_step_2'          => '  2. Wygeneruj klucz hosta SSH: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Ustaw CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=twój-ssh-klucz-publiczny',
    'cli.next_step_4'          => '  4. Uruchom: candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => 'Użycie: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Użycie: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Użycie: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Użytkownik '{username}' utworzony (admin: true)",
    'cli.user_key_hint'        => "Użyj 'candy-git-server user key {username} < key.pub', aby dodać klucz publiczny SSH.",
    'cli.user_key_read_failed' => 'Nie udało się odczytać klucza z: {file}',
    'cli.user_key_added'       => "Klucz dodany dla użytkownika '{username}'",
    'cli.user_keys_header'     => 'Autoryzowane klucze:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Użytkownicy:\n  (Brak zarejestrowanych użytkowników. Użyj 'candy-git-server user add <username>')",
    'cli.usage_repo_root'      => 'Użycie: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Użycie: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Użycie: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Jeszcze brak repozytoriów.',
    'cli.repo_listing_none'    => '  (jeszcze brak repozytoriów)',
    'cli.repo_invalid_name'    => 'Nieprawidłowa nazwa repozytorium: {name} (używaj tylko alfanumerycznych, kropki, podkreślenia, myślnika)',
    'cli.repo_created'         => "Repozytorium '{name}' utworzone w {path}",
    'cli.repo_clone_url'       => 'URL klonowania: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Nie znaleziono repozytorium: {name}',
    'cli.repo_info'            => "Nazwa:         {name}\nOpis:  {description}\nPubliczne:       {is_public}\nPrywatne:      {is_private}\nZezwól na push:   {allow_push}\nŚcieżka:         {path}\nGałęzie:     {branches}\nTagi:         {tags}",
    'cli.bool_yes'             => 'tak',
    'cli.bool_no'              => 'nie',
    'cli.none_value'           => '(brak)',
    'cli.unknown_command'      => "Nieznane polecenie: {cmd}\n  Uruchom 'candy-git-server help', aby uzyskać pomoc.",
    'cli.help'                 => "CandyGitServer — Samodzielny serwer Git\n\nUżycie:\n  candy-git-server <polecenie> [opcje]\n\nPolecenia:\n  serve [--config path]    Uruchom serwer Git\n  init [data-path]         Zainicjuj nowy katalog danych\n  user add <username>      Utwórz użytkownika\n  user key <username> <file>  Dodaj klucz publiczny SSH dla użytkownika (użyj - dla stdin)\n  user list                Lista użytkowników\n  repo list [data-path]    Lista repozytoriów\n  repo create <name> [data-path]  Utwórz repozytorium\n  repo info <name> [data-path]    Pokaż informacje o repozytorium\n  help, --help, -h         Pokaż tę pomoc\n  version, --version, -v   Pokaż wersję\n\nZmienne środowiskowe:\n  CANDY_GIT_SERVER_DATA_PATH    Katalog danych (domyślnie: /tmp/candy-git-server)\n\nPrzykłady:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
