<?php

/**
 * English (default) translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'Empty color.',
    'style.unrecognised_color' => 'Unrecognized color: {value} — Allowed: {allowed}.',
    'style.hex_digit_count'   => "Hex color must have 3 or 6 hex digits (e.g. '#f80' or '#ff8800'); got '{value}'.",
    'style.padding_token_int' => "Padding/margin token must be an integer; got: '{token}'.",
    'style.padding_count'     => 'Padding/margin needs 1, 2, or 4 integers; got: {count}.',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style entries must be 'key=value' or 'element.prop=value'; got '{raw}'.",
    'style.unknown_prop'      => "Unknown style prop: '{prop}'.",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'Failed to spawn child process',

    // Command/TableCommand.php
    'border.unknown'          => 'Unknown border style: {name}.',

    // Log/LogLevel.php
    'log.unknown_level'       => 'Unknown log level: {name}.',
    'log.encode_failed'       => 'Failed to encode log entry to json.',
    'log.bad_format'          => 'Invalid --format string: {format}.',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'Unknown spinner style: {name}.',
    'spinner.windows_unsupported' => 'Spin cannot run commands on native Windows: the process backend is unsupported there — use WSL instead.',
    'spinner.output_truncated' => '…[truncated]',

    // Command/FormatCommand.php
    'format.unknown_theme'    => 'Unknown theme: {name}.',

    // IO errors
    'io.read_failed'         => 'Could not read file: {path}.',
    'io.write_failed' => 'Could not write to file: {path}.',
    'runtime.no_terminal' => '{command} needs an interactive terminal; no controlling terminal is available.',
    'runtime.bad_timeout' => "Invalid --timeout value '{value}' — expected a non-negative number of seconds or a duration like 5s/250ms/1m30s (0 disables the deadline).",
    'runtime.bad_number' => "Invalid {flag} value '{value}' — expected a non-negative whole number.",
    'runtime.pcntl_missing' => 'ext-pcntl is not loaded: SIGINT/SIGTERM will kill this program without the 130/143 exit mapping.',
    'runtime.unknown_choice' => "Invalid value for {option}: '{value}'. Allowed: {allowed}.",
    'hints.choose' => '↑/↓ move · space toggle (multi) · enter select · esc cancel',
    'hints.filter' => 'type to filter · ↑/↓ move · tab toggle (multi) · enter select · esc cancel',
    'hints.pager' => 'space/PgDn down · PgUp/b up · g top · G end · q quit',
    'format.template_unresolved' => 'Template: {count} placeholder(s) expanded to empty: {names} — a variable must be set and permitted via --allow-env.',
    'runtime.empty_input' => 'Input produced no lines; nothing to do.',
    'runtime.empty_input_filter' => 'Nothing to filter: standard input produced no lines.',
    'runtime.empty_input_pager' => 'Nothing to page: standard input produced no lines.',
    'runtime.conflicting_flags' => 'Flags {first} and {second} cannot be combined with different values.',
    'runtime.empty_selection' => 'No item selected — press Space to tick at least one item before Enter.',
];
