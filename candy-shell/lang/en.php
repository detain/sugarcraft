<?php

/**
 * English (default) translations for candy-shell.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'empty color',
    'style.unrecognised_color' => 'unrecognised color: {value}',
    'style.padding_token_int' => "padding/margin token must be an integer; got: '{token}'",
    'style.padding_count'     => 'padding/margin needs 1, 2, or 4 integers; got: {count}',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style entries must be 'key=value' or 'element.prop=value'; got '{raw}'",
    'style.unknown_prop'      => "unknown style prop: '{prop}'",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'failed to spawn child process',

    // Command/TableCommand.php
    'border.unknown'          => 'unknown border style: {name}',

    // Log/LogLevel.php
    'log.unknown_level'       => 'unknown log level: {name}',
    'log.encode_failed'       => 'failed to encode log entry to json',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'unknown spinner style: {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => 'unknown --type: {type}',
    'format.unknown_theme'    => 'unknown theme: {name}',

    // IO errors
    'io.read_failed'         => 'could not read file: {path}',
];
