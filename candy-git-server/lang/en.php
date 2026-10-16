<?php

/**
 * English (default) translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Invalid SSH public key format',
    'user.ssh_key_too_short'   => 'SSH public key blob too short for {type}: {len} < {min} base64 chars',
    'repo.create_dir_failed'   => 'Failed to create repo directory: {path}',
    'repo.git_init_failed'     => 'git init failed: {output}',
    'config.not_found'         => 'Config file not found: {path}',
    'config.read_failed'       => 'Failed to read config: {path}',
    'config.parse_failed'      => 'Invalid YAML config: {error}',
    'config.invalid_user_trust_mode' => 'Invalid http.user_trust_mode "{mode}": expected off, proxy, or token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path are set but no HTTPS listener is wired yet; setting them does NOT enable TLS. Remove them until TLS is supported.',
    'jobs.invalid_schedule'    => 'Invalid job schedule "{schedule}": {reason}',
    'ssh.user_cannot_create'   => 'User {viewer} cannot create repos',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Starting servers...',
    'cli.note_ssh2_required'   => '(Full daemon mode requires the ssh2 extension and a running SSH daemon.)',
    'cli.note_run_init'        => "(Use 'candy-git-server init' to initialize your data directory first.)",
    'cli.repos_header'         => 'Repositories:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Not yet running as daemon (daemon mode needs a process manager.)',
    'cli.note_http_help'       => 'To serve Git over HTTP, point your web server to this script.',
    'cli.note_ssh_help'        => 'For SSH access, use a reverse tunnel or configure sshd with ForceCommand.',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Already initialized: {path}',
    'cli.initializing'         => 'Initializing CandyGitServer data directory: {path}',
    'cli.done'                 => 'Done.',
    'cli.next_steps'           => 'Next steps:',
    'cli.next_step_1'          => '  1. Edit {path}/config.yaml',
    'cli.next_step_2'          => '  2. Generate SSH host key: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Set CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=your-ssh-public-key',
    'cli.next_step_4'          => '  4. Run: candy-git-server serve --config {path}/config.yaml',

    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Usage: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Usage: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Usage: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "User '{username}' created (admin: true)",
    'cli.user_key_hint'        => "Use 'candy-git-server user key {username} < key.pub' to add an SSH public key.",
    'cli.user_key_read_failed' => 'Failed to read key from: {file}',
    'cli.user_key_added'       => "Key added for user '{username}'",
    'cli.user_keys_header'     => 'Authorized keys:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Users:\n  (No users registered. Use 'candy-git-server user add <username>')",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Usage: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Usage: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Usage: candy-git-server repo info <name>',
    'cli.no_repos'             => 'No repositories yet.',
    'cli.repo_listing_none'    => '  (no repositories yet)',
    'cli.repo_invalid_name'    => 'Invalid repo name: {name} (use only alphanumeric, dot, underscore, hyphen)',
    'cli.repo_created'         => "Repository '{name}' created at {path}",
    'cli.repo_clone_url'       => 'Clone URL: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Repository not found: {name}',
    'cli.repo_info'            => "Name:         {name}\nDescription:  {description}\nPublic:       {is_public}\nPrivate:      {is_private}\nAllow push:   {allow_push}\nPath:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'yes',
    'cli.bool_no'              => 'no',
    'cli.none_value'           => '(none)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Unknown command: {cmd}\n  Run 'candy-git-server help' for usage.",
    'cli.help'                 => "CandyGitServer — Self-hostable Git Server\n\nUsage:\n  candy-git-server <command> [options]\n\nCommands:\n  serve [--config path]    Start the Git server\n  init [data-path]         Initialize a new data directory\n  user add <username>      Create a user\n  user key <username> <file>  Add SSH public key for user (use - for stdin)\n  user list                List users\n  repo list [data-path]    List repositories\n  repo create <name> [data-path]  Create a repository\n  repo info <name> [data-path]    Show repository info\n  help, --help, -h         Show this help\n  version, --version, -v   Show version\n\nEnvironment:\n  CANDY_GIT_SERVER_DATA_PATH    Data directory (default: /tmp/candy-git-server)\n\nExamples:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
