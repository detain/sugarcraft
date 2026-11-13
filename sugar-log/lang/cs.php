<?php

/**
 * Czech translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'fatální log: {message}',
    'logger.invalid_stream' => 'stream musí být platný zdroj',
    'psrbridge.not_string' => 'Úroveň protokolu musí být jedna z konstant PSR-3 LogLevel; zadáno {got}',
    'psrbridge.unknown_level' => 'Neznámá úroveň protokolu \'{level}\'; očekávána jedna z konstant PSR-3 LogLevel',
    'parts.unknown' => 'neznámé části řádku protokolu: {parts}',
    'parts.duplicate' => 'duplicitní části řádku protokolu: {parts}',
    'log.fatal_error' => 'Závažná chyba',
    'log.fatal_parse' => 'Chyba analýzy',
    'log.fatal_core' => 'Chyba jádra',
    'log.fatal_compile' => 'Chyba kompilace',
    'log.fatal_user' => 'Uživatelská chyba',
    'log.fatal_shutdown' => 'Chyba ukončení',
    'logger.unopenable_stream' => 'nelze otevřít soubor protokolu \'{path}\'',
];
