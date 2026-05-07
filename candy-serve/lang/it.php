<?php

/**
 * Italian translations for candy-serve.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Formato chiave SSH pubblica non valido',
    'repo.create_dir_failed'   => 'Creazione della directory del repository fallita: {path}',
    'repo.git_init_failed'     => 'git init fallito: {output}',
    'config.not_found'         => 'File di configurazione non trovato: {path}',
    'config.read_failed'       => 'Lettura della configurazione fallita: {path}',
    'ssh.user_cannot_create'   => 'L\'utente {viewer} non può creare repository',
    'cli.banner'               => 'CandyServe v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Avvio dei server...',
    'cli.note_ssh2_required'   => '(La modalità daemon completa richiede l\'estensione ssh2 e un demone SSH in esecuzione.)',
    'cli.note_run_init'        => "(Usare 'soft-serve init' per inizializzare prima la directory dei dati.)",
    'cli.repos_header'         => 'Repository:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Non ancora in esecuzione come daemon (la modalità daemon richiede un gestore di processi.)',
    'cli.note_http_help'       => 'Per servire Git su HTTP, puntare il server web a questo script.',
    'cli.note_ssh_help'        => 'Per l\'accesso SSH, usare un tunnel inverso o configurare sshd con ForceCommand.',
    'cli.already_initialized'  => 'Già inizializzato: {path}',
    'cli.initializing'         => 'Inizializzazione della directory dati CandyServe: {path}',
    'cli.done'                 => 'Fatto.',
    'cli.next_steps'           => 'Passi successivi:',
    'cli.next_step_1'          => '  1. Modificare {path}/config.yaml',
    'cli.next_step_2'          => '  2. Generare la chiave host SSH: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Impostare CANDY_SERVE_INITIAL_ADMIN_KEYS=chiave-pubblica-ssh',
    'cli.next_step_4'          => '  4. Eseguire: soft-serve serve --config {path}/config.yaml',
    'cli.usage_user_root'      => 'Uso: soft-serve user add|key|list',
    'cli.usage_user_add'       => 'Uso: soft-serve user add <username>',
    'cli.usage_user_key'       => 'Uso: soft-serve user key <username> [key-file]',
    'cli.user_created'         => "Utente '{username}' creato (admin: true)",
    'cli.user_key_hint'        => "Usare 'soft-serve user key {username} < key.pub' per aggiungere una chiave SSH pubblica.",
    'cli.user_key_read_failed' => 'Lettura della chiave fallita da: {file}',
    'cli.user_key_added'       => "Chiave aggiunta per l'utente '{username}'",
    'cli.user_keys_header'     => 'Chiavi autorizzate:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Utenti:\n  (Nessun utente registrato. Usare 'soft-serve user add <username>')",
    'cli.usage_repo_root'      => 'Uso: soft-serve repo list|create|info',
    'cli.usage_repo_create'    => 'Uso: soft-serve repo create <name>',
    'cli.usage_repo_info'      => 'Uso: soft-serve repo info <name>',
    'cli.no_repos'             => 'Nessun repository per ora.',
    'cli.repo_listing_none'    => '  (nessun repository ancora)',
    'cli.repo_invalid_name'    => 'Nome repository non valido: {name} (usare solo alfanumerico, punto, underscore, trattino)',
    'cli.repo_created'         => "Repository '{name}' creato in {path}",
    'cli.repo_clone_url'       => 'URL clone: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Repository non trovato: {name}',
    'cli.repo_info'            => "Nome:         {name}\nDescrizione:  {description}\nPubblico:       {is_public}\nPrivato:      {is_private}\nPermetti push:   {allow_push}\nPercorso:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'sì',
    'cli.bool_no'              => 'no',
    'cli.none_value'           => '(nessuna)',
    'cli.unknown_command'      => "Comando sconosciuto: {cmd}\n  Eseguire 'soft-serve help' per l'aiuto.",
    'cli.help'                 => "CandyServe — Server Git auto-ospitabile\n\nUso:\n  soft-serve <comando> [opzioni]\n\nComandi:\n  serve [--config path]    Avvia il server Git\n  init [data-path]         Inizializza una nuova directory dati\n  user add <username>      Crea un utente\n  user key <username> <file>  Aggiungi chiave SSH pubblica per l'utente (usare - per stdin)\n  user list                Elenca utenti\n  repo list [data-path]    Elenca repository\n  repo create <name> [data-path]  Crea un repository\n  repo info <name> [data-path]    Mostra info repository\n  help, --help, -h         Mostra questa guida\n  version, --version, -v   Mostra versione\n\nAmbiente:\n  CANDY_SERVE_DATA_PATH    Directory dati (predefinita: /tmp/candy-serve)\n\nEsempi:\n  soft-serve init ~/candy-serve-data\n  soft-serve serve --config ~/candy-serve-data/config.yaml\n  soft-serve repo create mio-progetto\n  soft-serve user key alice ~/.ssh/id_ed25519.pub",
];
