<?php

/**
 * English (default) translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'failed to exec {bin}',
    'launcher.transport_not_executable' => 'transport binary not found or not executable: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: endpoint {endpoint}: the custom transport ignores {legs}',
    'config.not_found'         => 'wishlist config not found: {path}',
    'config.json_top_level'    => 'wishlist json: top-level value must be an array',
    'config.json_entry_object' => 'wishlist json: each entry must be an object',
    'config.yaml_continuation' => "wishlist yaml: continuation before any '- name:' block",
    'config.yaml_unparseable'  => 'wishlist yaml: unparseable line {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: block mapping under `{field}:` on line {line}: {found} — this field must be a list of `- value` items',
    'config.yaml_unterminated_quote' => 'wishlist yaml: unterminated quote in value: {found}',
    'config.entry_missing_field' => 'wishlist entry #{index} is missing a required field: {field}',
    'config.unknown_key' => 'wishlist entry: unknown field `{key}` ignored — not a documented key',
    'config.unknown_key_nearmiss' => 'wishlist entry: unknown field `{key}` ignored — did you mean `{field}`?',
    'config.duplicate_name' => 'wishlist: duplicate endpoint name `{name}` — all rows load; names are identifiers',
    'config.field_not_scalar'  => 'wishlist entry: field [{field}] must be a scalar, got {type}',
    'config.too_large'          => 'wishlist config file is larger than the {limit}-byte limit: {path}',
    'config.list_too_long'      => 'wishlist entry: field [{field}] carries more than the {limit} list items the loader allows',
    'config.field_not_list'       => 'wishlist entry: field [{field}] must be a list of values, not a key-value map',
    'config.encoding_utf16'       => '{path} is UTF-16 encoded — re-save the config as UTF-8',
    'config.unreadable'       => 'wishlist config could not be read: {path}',
    'config.home_unset'       => 'cannot expand ~ without $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: inline flow collection not supported: {value} (use a nested list)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist endpoint: field [{field}] has unsafe leading-dash value [{value}]',
    'endpoint.host_empty' => 'endpoint `{name}` has an empty host — a selectable row needs a hostname',
    'endpoint.name_empty' => 'endpoint has an empty name — a selectable row needs a name',
    'endpoint.option_empty' => 'endpoint `{name}` has an empty option entry — a blank -o value is refused',
    'endpoint.port_invalid'     => 'wishlist endpoint [{host}]: invalid port [{port}] — must be an integer from 1 to 65535',

    // bin/candy-ssh-launcher
    'cli.usage'         => 'Usage: candy-ssh-launcher [--config <path>] [--ssh <ssh-binary>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: unknown arg: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: no config found. Create ~/.config/wishlist.yml (see the README Quick start) or pass --config <path>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: cancelled.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh binary not found or not executable: {bin} — pass --ssh <path> to name your ssh binary',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} requires a value',
    'cli.no_endpoints'   => 'candy-ssh-launcher: no endpoints in {path}',
    'cli.not_a_terminal' => 'candy-ssh-launcher: the picker needs an interactive terminal — stdin and stdout must be TTYs. Use --list for machine-readable output.',
    'cli.config_unowned'   => 'candy-ssh-launcher: refusing a config owned by another user: {path} — configs are trusted input; see Security in the README.',
    'cli.config_writable'  => 'candy-ssh-launcher: refusing a config writable by the group or the world: {path} — tighten it to 0644 or 0600, or pass it explicitly via --config.',
    'cli.project_local'    => 'candy-ssh-launcher: project-local wishlist in use: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: endpoint requests -o {option} — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'host-key checking downgraded — the server key may go unverified',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'the per-user known-hosts trust database is redirected',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'the system-wide trust database is redirected',
    'cli.unsafe_option_effect_log_level' => 'ssh warnings and notices are hidden',
    'cli.unsafe_option_effect_identity_agent' => 'authentication is delegated to another agent socket',
    'cli.unsafe_option_effect_certificate_file' => 'an extra authentication certificate is offered',
    'cli.ssh_config_source'  => 'candy-ssh-launcher: importing endpoints from ssh config: {path}',
    'cli.ssh_config_missing' => 'candy-ssh-launcher: no ssh config at {path} — pass --config <path> with --from-ssh-config to name another file',
    'cli.config_is_ssh_config' => 'candy-ssh-launcher: {path} looks like an OpenSSH config, not a wishlist file — import it with --from-ssh-config (optionally with --config <path> to name the file)',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filter: ',
    'picker.no_matches'  => '  (no matches — backspace or Esc)',
    'picker.help'        => '↑/↓ select · Enter connect · Esc quit · type to filter',
    'picker.help_keys'   => '⌫ delete · Ctrl+U/W clear · PgUp/PgDn page · Home/End jump',
    'picker.count'       => '  ({shown} of {total})',
];
