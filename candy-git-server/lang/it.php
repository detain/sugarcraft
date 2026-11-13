<?php

/**
 * Italian translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Formato chiave SSH pubblica non valido',
    'repo.create_dir_failed'   => 'Creazione della directory del repository fallita: {path}',
    'repo.git_init_failed'     => 'git init fallito: {output}',
    'repo.visibility_marker_unwritable' => 'Impossibile scrivere il marcatore di visibilità: {file}',
    'config.not_found'         => 'File di configurazione non trovato: {path}',
    'config.read_failed'       => 'Lettura della configurazione fallita: {path}',
    'ssh.user_cannot_create'   => 'L\'utente {viewer} non può creare repository',
    'ssh.invalid_repo_name'    => 'Nome repository non valido: {name}',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Repository:',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (pubblico)',
    'cli.already_initialized'  => 'Già inizializzato: {path}',
    'cli.initializing'         => 'Inizializzazione della directory dati CandyGitServer: {path}',
    'cli.done'                 => 'Fatto.',
    'cli.next_steps'           => 'Passi successivi:',
    'cli.next_step_1'          => '  1. Crea un account: candy-git-server user add --admin admin {path}',

    'cli.next_step_2'          => '  2. Crea un repository: candy-git-server repo create <nome> {path} (aggiungi --public per pubblicarlo in lettura anonima)',

    'cli.next_step_3'          => '  3. Servi via smart HTTP: candy-git-server serve --http --repo-root {path}/repositories',
    'cli.usage_user_root'      => 'Uso: candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'Uso: candy-git-server user add <username> [data-path] [--password <pw>|-] [--admin|--read]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] genera una firma del token proxy HTTP',
    'cli.usage_user_key'       => 'Uso: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Utente '{username}' creato (admin: {admin})",
    // S21: least-privilege `user add` flags (finding 036).
    'cli.user_add_flags_conflict' => '--admin e --read sono mutuamente esclusivi.',
    'cli.user_password_stored' => 'Password salvata come hash.',
    'cli.user_key_hint'        => "Usare 'candy-git-server user key {username} <key-file|->' per aggiungere una chiave SSH pubblica.",
    'cli.user_key_read_failed' => 'Lettura della chiave fallita da: {file}',
    'cli.user_key_added'       => "Chiave aggiunta per l'utente '{username}'",
    'cli.user_keys_header'     => 'Chiavi autorizzate:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Utenti:\n  (Nessun utente registrato. Usare 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Utenti ({path}):",
    'cli.usage_repo_root'      => 'Uso: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Uso: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Uso: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Nessun repository per ora.',
    'cli.repo_listing_none'    => '  (nessun repository ancora)',
    'cli.repo_invalid_name'    => 'Nome repository non valido: {name} (usare solo alfanumerico, punto, underscore, trattino, non deve iniziare con punto o trattino)',
    'cli.repo_created'         => "Repository '{name}' creato in {path}",
    'cli.repo_not_found'       => 'Repository non trovato: {name}',
    'cli.repo_info'            => "Nome:         {name}\nDescrizione:  {description}\nPubblico:       {is_public}\nPrivato:      {is_private}\nPermetti push:   {allow_push}\nPercorso:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'sì',
    'cli.bool_no'              => 'no',
    'cli.none_value'           => '(nessuna)',
    'cli.unknown_command'      => "Comando sconosciuto: {cmd}\n  Eseguire 'candy-git-server help' per l'aiuto.",
    'cli.help'                 => "CandyGitServer — Server Git auto-ospitabile\n\nUso:\n  candy-git-server <comando> [opzioni]\n\nComandi:\n  serve [--config path]    Avvia il server Git\n  init [data-path]         Inizializza una nuova directory dati\n  user add <username>      Crea un utente\n  user key <username> <file>  Aggiungi chiave SSH pubblica per l'utente (usare - per stdin)\n  user list                Elenca utenti\n  repo list [data-path]    Elenca repository\n  repo create <name> [data-path]  Crea un repository\n  repo info <name> [data-path]    Mostra info repository\n  help, --help, -h         Mostra questa guida\n  version, --version, -v   Mostra versione\n\nAmbiente:\n  CANDY_GIT_SERVER_DATA_PATH    Directory dati (predefinita: /tmp/candy-git-server)\n\nEsempi:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mio-progetto\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'blob di chiave pubblica SSH troppo corto per {type}: {len} < {min} caratteri base64',
    'config.parse_failed'   => 'configurazione YAML non valida: {error}',
    'config.invalid_user_trust_mode' => 'http.user_trust_mode "{mode}" non valido: attesi off, proxy o token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path sono impostati ma nessun listener HTTPS è collegato; impostarli NON abilita TLS. Rimuoverli finché TLS non è supportato.',
    'jobs.invalid_schedule' => 'pianificazione job non valida "{schedule}": {reason}',
    'cli.option_needs_value' => 'l’opzione {option} richiede un valore',
    'cli.serve_http_needs_repo_root' => 'serve --http richiede --repo-root DIR (la directory le cui sottocartelle sono servite)',
    'cli.serve_repo_root_not_dir' => '--repo-root non è una directory: {path}',
    'cli.serve_http_port_range' => '--http-port fuori intervallo: {port}',
    'cli.serve_http_port_not_a_number' => 'serve --http: --http-port deve essere un numero, ricevuto: {port}',
    'cli.serve_http_flag_unsupported' => 'L’opzione {flag} non è supportata da serve --http (il listener gira in foreground; demonizzare con un supervisor).',
    'cli.serve_http_unknown_flag' => 'Opzione sconosciuta per serve --http: {flag}',
    'cli.port_deprecated'      => 'Nota: --port è deprecato; usare --http-port (alias mantenuto per compatibilità).',
    'cli.serve_http_banner' => 'listener smart-HTTP su {addr} ({repos} repo, {published} pubblici, {users} account; lettura anonima={anon}; anche il push è servito — i rifiuti degli hook viaggiano in-band)',

// bin/candy-git-server — Step 17: remaining CLI refusals
    'cli.init_temp_open_failed' => 'init: impossibile aprire la configurazione temporanea in {tmp}',
    'cli.init_publish_failed' => 'init: impossibile pubblicare la configurazione in {data}/config.yaml',
    'cli.token_no_config' => 'token: nessuna configurazione leggibile in {data}/config.yaml — eseguire init o passare un percorso dati',
    'cli.token_no_secret' => 'token: http.auth_secret non e configurato per questo percorso dati; impostarlo nella sezione http: di config.yaml prima di coniare token',
    'cli.password_flag_needs_value' => 'candy-git-server: --password richiede un valore (\'-\' legge da stdin)',
    'cli.password_flag_empty' => 'candy-git-server: --password richiede un valore non vuoto',
    'cli.username_invalid' => 'Nome utente non valido: \'{username}\' (lettere, cifre, punto, trattino basso, trattino; non solo punti)',
    'cli.user_exists' => 'L\'utente \'{username}\' esiste gia in {path}',
    'cli.user_not_found' => 'Utente \'{username}\' inesistente in {path} — eseguire prima \'candy-git-server user add {username}\'.',
];
