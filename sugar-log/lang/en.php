<?php

declare(strict_types=1);

/**
 * English (default) translations for sugar-log.
 *
 * @return array<string, string>
 */
return [
    'logger.fatal' => 'fatal log: {message}',
    'logger.invalid_stream' => 'stream must be a valid resource',
    'psrbridge.not_string' => 'Log level must be one of the PSR-3 LogLevel constants; got {got}',
    'psrbridge.unknown_level' => 'Unknown log level \'{level}\'; expected one of the PSR-3 LogLevel constants',
    'parts.unknown' => 'unknown log line part(s): {parts}',
    'parts.duplicate' => 'duplicate log line part(s): {parts}',
    'log.fatal_error' => 'Fatal error',
    'log.fatal_parse' => 'Parse error',
    'log.fatal_core' => 'Core error',
    'log.fatal_compile' => 'Compile error',
    'log.fatal_user' => 'User error',
    'log.fatal_shutdown' => 'Shutdown error',
];
