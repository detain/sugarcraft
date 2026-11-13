<?php

/**
 * Polish translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'log śmiertelny: {message}',
    'logger.invalid_stream' => 'Strumień musi być prawidłowym zasobem',
    'psrbridge.not_string' => 'Poziom logowania musi być jedną ze stałych PSR-3 LogLevel; otrzymano {got}',
    'psrbridge.unknown_level' => 'Nieznany poziom logowania \'{level}\'; oczekiwano jednej ze stałych PSR-3 LogLevel',
    'parts.unknown' => 'nieznane części linii logowania: {parts}',
    'parts.duplicate' => 'zduplikowane części linii logowania: {parts}',
    'log.fatal_error' => 'Błąd krytyczny',
    'log.fatal_parse' => 'Błąd parsowania',
    'log.fatal_core' => 'Błąd rdzenia',
    'log.fatal_compile' => 'Błąd kompilacji',
    'log.fatal_user' => 'Błąd użytkownika',
    'log.fatal_shutdown' => 'Błąd zamykania',
];
