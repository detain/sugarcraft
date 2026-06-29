<?php

/**
 * English (default) translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec unavailable; ext-pcntl required',
    'launcher.exec_failed'     => 'failed to exec {bin}',
    'config.not_found'         => 'wishlist config not found: {path}',
    'config.json_top_level'    => 'wishlist json: top-level value must be an array',
    'config.json_entry_object' => 'wishlist json: each entry must be an object',
    'config.yaml_continuation' => "wishlist yaml: continuation before any '- name:' block",
    'config.yaml_unparseable'  => 'wishlist yaml: unparseable line: {line}',
    'config.entry_missing_field' => 'wishlist entry missing required field: name or host',

    // bin/wishlist
    'cli.usage'         => 'Usage: wishlist [--config <path>] [--ssh <ssh-binary>]',
    'cli.unknown_arg'   => 'wishlist: unknown arg: {arg}',
    'cli.no_config'     => 'wishlist: no config found. Pass --config <path>.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: cancelled.',

    // endpoint security
    'endpoint.option_injection' => 'wishlist endpoint: field [{field}] has unsafe leading-dash value [{value}]',
];
