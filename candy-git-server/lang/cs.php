<?php

/**
 * Czech translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Neplatný formát veřejného klíče SSH',
    'repo.create_dir_failed'   => 'Nelze vytvořit adresář repozitáře: {path}',
    'repo.git_init_failed'     => 'git init selhalo: {output}',
    'repo.visibility_marker_unwritable' => 'Nelze zapsat značku viditelnosti: {file}',
    'config.not_found'         => 'Konfigurační soubor nenalezen: {path}',
    'config.read_failed'       => 'Nelze číst konfiguraci: {path}',
    'ssh.user_cannot_create'   => 'Uživatel {viewer} nemůže vytvářet repozitáře',
    'ssh.invalid_repo_name'    => 'Neplatný název repozitáře: {name}',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Repozitáře:',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (veřejný)',
    'cli.already_initialized'  => 'Již inicializováno: {path}',
    'cli.initializing'         => 'Inicializace datového adresáře CandyGitServer: {path}',
    'cli.done'                 => 'Hotovo.',
    'cli.next_steps'           => 'Další kroky:',
    'cli.next_step_1'          => '  1. Vytvořte účet: candy-git-server user add --admin admin {path}',

    'cli.next_step_2'          => '  2. Vytvořte repozitář: candy-git-server repo create <název> {path} (pro anonymní čtení přidejte --public)',

    'cli.next_step_3'          => '  3. Obsluhujte přes smart HTTP: candy-git-server serve --http --repo-root {path}/repositories',
    'cli.usage_user_root'      => 'Použití: candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'Použití: candy-git-server user add <username> [data-path] [--password <pw>|-] [--admin|--read]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] mint an HTTP proxy token signature',
    'cli.usage_user_key'       => 'Použití: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Uživatel '{username}' vytvořen (admin: {admin})",
    // S21: least-privilege `user add` flags (finding 036).
    'cli.user_add_flags_conflict' => '--admin a --read se vzájemně vylučují.',
    'cli.user_password_stored' => 'Heslo uloženo jako hash.',
    'cli.user_key_hint'        => "Pro přidání veřejného klíče SSH použijte 'candy-git-server user key {username} <key-file|->'.",
    'cli.user_key_read_failed' => 'Nelze číst klíč z: {file}',
    'cli.user_key_added'       => "Klíč přidán pro uživatele '{username}'",
    'cli.user_keys_header'     => 'Autorizované klíče:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Uživatelé:\n  (Žádní registrovaní uživatelé. Použijte 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Uživatelé ({path}):",
    'cli.usage_repo_root'      => 'Použití: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Použití: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Použití: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Zatím žádné repozitáře.',
    'cli.repo_listing_none'    => '  (zatím žádné repozitáře)',
    'cli.repo_invalid_name'    => 'Neplatný název repozitáře: {name} (používejte pouze alfanumerické, tečku, podtržítko, pomlčku, nesmí začínat tečkou ani pomlčkou)',
    'cli.repo_created'         => "Repozitář '{name}' vytvořen v {path}",
    'cli.repo_exists' => "Repozitář '{name}' již existuje v {path}",
    'cli.repo_not_found'       => 'Repozitář nenalezen: {name}',
    'cli.repo_info'            => "Název:         {name}\nPopis:  {description}\nVeřejný:       {is_public}\nSoukromý:      {is_private}\nPovolit push:   {allow_push}\nCesta:         {path}\nVětve:     {branches}\nTagy:         {tags}",
    'cli.bool_yes'             => 'ano',
    'cli.bool_no'              => 'ne',
    'cli.none_value'           => '(žádné)',
    'cli.unknown_command'      => "Neznámý příkaz: {cmd}\n  Spusťte 'candy-git-server help' pro nápovědu.",
    'cli.help'                 => "CandyGitServer — Samo-hostovaný Git server\n\nPoužití:\n  candy-git-server <příkaz> [volby]\n\nPříkazy:\n  serve [--config path]    Spustit Git server\n  init [data-path]         Inicializovat nový datový adresář\n  user add <username>      Vytvořit uživatele\n  user key <username> <file>  Přidat veřejný klíč SSH pro uživatele (použijte - pro stdin)\n  user list                Seznam uživatelů\n  repo list [data-path]    Seznam repozitářů\n  repo create <name> [data-path]  Vytvořit repozitář\n  repo info <name> [data-path]    Zobrazit info repozitáře\n  help, --help, -h         Zobrazit tuto nápovědu\n  version, --version, -v   Zobrazit verzi\n\nProměnné prostředí:\n  CANDY_GIT_SERVER_DATA_PATH    Datový adresář (výchozí: /tmp/candy-git-server)\n\nPříklady:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create muj-projekt\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'blob veřejného SSH klíče je příliš krátký pro {type}: {len} < {min} znaků base64',
    'config.parse_failed'   => 'Neplatná YAML konfigurace: {error}',
    'config.invalid_user_trust_mode' => 'Neplatný http.user_trust_mode "{mode}": očekáváno off, proxy nebo token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path jsou nastaveny, ale HTTPS listener zatím není propojen; nastavení TLS NEzapíná. Odstraňte je, dokud TLS není podporováno.',
    'jobs.invalid_schedule' => 'Neplatný rozvrh úlohy "{schedule}": {reason}',
    'cli.option_needs_value' => 'volba {option} vyžaduje hodnotu',
    'cli.serve_http_needs_repo_root' => 'serve --http vyžaduje --repo-root ADŘ (adresář, jehož podadresáře se obsluhují)',
    'cli.serve_repo_root_not_dir' => '--repo-root není adresář: {path}',
    'cli.serve_http_port_range' => '--http-port mimo rozsah: {port}',
    'cli.serve_http_port_not_a_number' => 'serve --http: --http-port musí být číslo, dostalo se: {port}',
    'cli.serve_http_flag_unsupported' => 'Příznak {flag} není serve --http podporován (listener běí v popředí; daemonizujte přes supervisora).',
    'cli.serve_http_unknown_flag' => 'Neznámý příznak serve --http: {flag}',
    'cli.port_deprecated'      => 'Poznámka: --port je zastaralý; použijte --http-port (alias zachován pro kompatibilitu).',
    'cli.serve_http_banner' => 'smart-HTTP listener na {addr} ({repos} repozitářů, {published} zveřejněno, {users} účtů; anonymní čtení={anon}; push je také obsluhován — odmítnutí hooků jedou in-band)',

// bin/candy-git-server — Step 17: remaining CLI refusals
    'cli.init_temp_open_failed' => 'init: nelze otevřít dočasnou konfiguraci v {tmp}',
    'cli.init_publish_failed' => 'init: nelze zveřejnit konfiguraci v {data}/config.yaml',
    'cli.token_no_config' => 'token: není čitelná konfigurace v {data}/config.yaml — spusťte init nebo zadejte cestu k datům',
    'cli.token_no_secret' => 'token: http.auth_secret není pro tuto datovou cestu nastaven; nastavte ji v sekci http: souboru config.yaml před vydáváním tokenů',
    'cli.password_flag_needs_value' => 'candy-git-server: --password vyžaduje hodnotu (\'-\' čte ze stdin)',
    'cli.password_flag_empty' => 'candy-git-server: --password vyžaduje neprázdnou hodnotu',
    'cli.username_invalid' => 'Neplatné uživatelské jméno: \'{username}\' (písmena, číslice, tečka, podtržítko, pomlčka; ne pouze tečky)',
    'cli.user_exists' => 'Uživatel \'{username}\' již v {path} existuje',
    'cli.user_not_found' => 'Uživatel \'{username}\' v {path} neexistuje — nejprve spusťte \'candy-git-server user add {username}\'.',
];
