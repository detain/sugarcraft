<?php

/**
 * English (default) translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'failed to exec {bin}',
    'config.not_found'         => 'wishlist config not found: {path}',
    'config.json_top_level'    => 'wishlist json: top-level value must be an array',
    'config.json_entry_object' => 'wishlist json: each entry must be an object',
    'config.yaml_continuation' => "wishlist yaml: continuation before any '- name:' block",
    'config.yaml_unparseable'  => 'wishlist yaml: unparseable line: {line}',
    'config.yaml_block_mapping' => 'wishlist yaml: block mapping under `{field}:` on line {line} — this field must be a list of `- value` items',
    'config.entry_missing_field' => 'wishlist entry missing required field: name or host',
    'config.field_not_scalar'  => 'wishlist entry: field [{field}] must be a scalar, got {type}',
    'config.unreadable'       => 'wishlist config could not be read: {path}',
    'config.home_unset'       => 'cannot expand ~ without $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: inline flow collection not supported: {value} (use a nested list)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist endpoint: field [{field}] has unsafe leading-dash value [{value}]',
    'endpoint.host_empty' => 'endpoint `{name}` has an empty host — a selectable row needs a hostname',
    'endpoint.option_empty' => 'endpoint `{name}` has an empty option entry — a blank -o value is refused',
    'endpoint.port_invalid'     => 'wishlist endpoint [{host}]: invalid port [{port}] — must be an integer from 1 to 65535',

    // bin/candy-ssh-launcher
    'cli.usage'         => 'Usage: candy-ssh-launcher [--config <path>] [--ssh <ssh-binary>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: unknown arg: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: no config found. Pass --config <path>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: cancelled.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh binary not found or not executable: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} requires a value',
    'cli.no_endpoints'   => 'candy-ssh-launcher: no endpoints in {path}',
    'cli.not_a_terminal' => 'candy-ssh-launcher: the picker needs an interactive terminal — stdin and stdout must be TTYs. Use --list for machine-readable output.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filter: ',
    'picker.no_matches'  => '  (no matches)',
    'picker.help'        => '  ↑/↓ or j/k select · Enter connect · Esc quit · type to filter',
    'picker.count'       => '  ({shown} of {total})',
];
