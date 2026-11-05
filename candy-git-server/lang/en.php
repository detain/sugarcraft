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
    'repo.visibility_marker_unwritable' => 'Failed to write visibility marker: {file}',
    'config.not_found'         => 'Config file not found: {path}',
    'config.read_failed'       => 'Failed to read config: {path}',
    'config.parse_failed'      => 'Invalid YAML config: {error}',
    'config.invalid_user_trust_mode' => 'Invalid http.user_trust_mode "{mode}": expected off, proxy, or token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path are set but no HTTPS listener is wired yet; setting them does NOT enable TLS. Remove them until TLS is supported.',
    'jobs.invalid_schedule'    => 'Invalid job schedule "{schedule}": {reason}',
    'ssh.user_cannot_create'   => 'User {viewer} cannot create repos',
    'ssh.invalid_repo_name'    => 'Invalid repo name: {name}',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Repositories:',
    'cli.repo_listing_plain'   => '  {name}',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Already initialized: {path}',
    'cli.initializing'         => 'Initializing CandyGitServer data directory: {path}',
    'cli.done'                 => 'Done.',
    'cli.next_steps'           => 'Next steps:',
    'cli.next_step_1'          => '  1. Create an account: candy-git-server user add admin {path}',

    'cli.next_step_2'          => '  2. Create a repository: candy-git-server repo create <name> {path} (add --public to publish it for anonymous read)',

    'cli.next_step_3'          => '  3. Serve over smart HTTP: candy-git-server serve --http --repo-root {path}/repositories',
    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Usage: candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'Usage: candy-git-server user add <username> [data-path] [--password <pw>|-]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] mint an HTTP proxy token signature',
    'cli.usage_user_key'       => 'Usage: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "User '{username}' created (admin: true)",
    'cli.user_password_stored' => 'Password stored as a hash.',
    'cli.user_key_hint'        => "Use 'candy-git-server user key {username} <key-file|->' to add an SSH public key.",
    'cli.user_key_read_failed' => 'Failed to read key from: {file}',
    'cli.user_key_added'       => "Key added for user '{username}'",
    'cli.user_keys_header'     => 'Authorized keys:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Users:\n  (No users registered. Use 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Users ({path}):",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Usage: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Usage: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Usage: candy-git-server repo info <name>',
    'cli.no_repos'             => 'No repositories yet.',
    'cli.repo_listing_none'    => '  (no repositories yet)',
    'cli.repo_invalid_name'    => 'Invalid repo name: {name} (use only alphanumeric, dot, underscore, hyphen; must not start with a dot or hyphen)',
    'cli.repo_created'         => "Repository '{name}' created at {path}",
    'cli.repo_not_found'       => 'Repository not found: {name}',
    'cli.repo_info'            => "Name:         {name}\nDescription:  {description}\nPublic:       {is_public}\nPrivate:      {is_private}\nAllow push:   {allow_push}\nPath:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'yes',
    'cli.bool_no'              => 'no',
    'cli.none_value'           => '(none)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Unknown command: {cmd}\n  Run 'candy-git-server help' for usage.",
    'cli.help'                 => "CandyGitServer — Self-hostable Git Server\n\nUsage:\n  candy-git-server <command> [options]\n\nCommands:\n  serve --http --repo-root DIR [--http-port N] [--http-host H] [--config F]   Serve repositories over smart HTTP\n  init [data-path]         Initialize a new data directory\n  user add <username>      Create a user\n  user key <username> <file>  Add SSH public key for user (use - for stdin)\n  user list                List users\n  user token <username> [ttl]  Mint an HTTP proxy token signature\n  repo list [data-path]    List repositories\n  repo create <name> [data-path]  Create a repository\n  repo info <name> [data-path]    Show repository info\n  help, --help, -h         Show this help\n  version, --version, -v   Show version\n\nEnvironment:\n  CANDY_GIT_SERVER_DATA_PATH    Data directory (default: /tmp/candy-git-server)\n  CANDY_GIT_SERVER_LANG           Translation catalogue (default: en)\n\nExamples:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --http --repo-root ~/candy-git-server-data/repositories\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'cli.option_needs_value' => 'Option {option} requires a value',
    'cli.serve_http_needs_repo_root' => 'serve --http requires --repo-root DIR (the directory whose subdirectories are served)',
    'cli.serve_repo_root_not_dir' => '--repo-root is not a directory: {path}',
    'cli.serve_http_port_range' => '--http-port out of range: {port}',
    'cli.serve_http_banner' => 'smart-HTTP listener on {addr} ({repos} repos, {published} published; {users} accounts; anonymous-read={anon}; push is served too — hook declines ride in-band)',
];
