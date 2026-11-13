<?php

/**
 * Russian translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'фатальный лог: {message}',
    'logger.invalid_stream' => 'Поток должен быть допустимым ресурсом',
    'psrbridge.not_string' => 'Уровень журнала должен быть одной из констант PSR-3 LogLevel; получено {got}',
    'psrbridge.unknown_level' => 'Неизвестный уровень журнала \'{level}\'; ожидалась одна из констант PSR-3 LogLevel',
    'parts.unknown' => 'неизвестные части строки журнала: {parts}',
    'parts.duplicate' => 'повторяющиеся части строки журнала: {parts}',
    'log.fatal_error' => 'Фатальная ошибка',
    'log.fatal_parse' => 'Ошибка разбора',
    'log.fatal_core' => 'Ошибка ядра',
    'log.fatal_compile' => 'Ошибка компиляции',
    'log.fatal_user' => 'Ошибка пользователя',
    'log.fatal_shutdown' => 'Ошибка завершения',
    'logger.unopenable_stream' => 'не удалось открыть файл журнала \'{path}\'',
];
