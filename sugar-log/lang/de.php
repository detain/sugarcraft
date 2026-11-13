<?php

/**
 * German translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'Fatales Protokoll: {message}',
    'logger.invalid_stream' => 'Der Stream muss eine gültige Ressource sein',
    'psrbridge.not_string' => 'Die Protokollstufe muss eine der PSR-3-LogLevel-Konstanten sein; erhalten {got}',
    'psrbridge.unknown_level' => 'Unbekannte Protokollstufe „{level}“; erwartet wurde eine der PSR-3-LogLevel-Konstanten',
    'parts.unknown' => 'unbekannte Protokollzeilen-Teile: {parts}',
    'parts.duplicate' => 'doppelte Protokollzeilen-Teile: {parts}',
    'log.fatal_error' => 'Schwerwiegender Fehler',
    'log.fatal_parse' => 'Parse-Fehler',
    'log.fatal_core' => 'Core-Fehler',
    'log.fatal_compile' => 'Compile-Fehler',
    'log.fatal_user' => 'Benutzerfehler',
    'log.fatal_shutdown' => 'Shutdown-Fehler',
    'logger.unopenable_stream' => 'Protokolldatei \'{path}\' kann nicht geöffnet werden',
];
