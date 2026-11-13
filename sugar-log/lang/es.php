<?php

/**
 * Spanish translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'log fatal: {message}',
    'logger.invalid_stream' => 'El flujo debe ser un recurso válido',
    'psrbridge.not_string' => 'El nivel de registro debe ser una de las constantes PSR-3 LogLevel; se obtuvo {got}',
    'psrbridge.unknown_level' => 'Nivel de registro desconocido \'{level}\'; se esperaba una de las constantes PSR-3 LogLevel',
    'parts.unknown' => 'parte(s) de línea de registro desconocida(s): {parts}',
    'parts.duplicate' => 'parte(s) de línea de registro duplicada(s): {parts}',
    'log.fatal_error' => 'Error fatal',
    'log.fatal_parse' => 'Error de análisis',
    'log.fatal_core' => 'Error de núcleo',
    'log.fatal_compile' => 'Error de compilación',
    'log.fatal_user' => 'Error de usuario',
    'log.fatal_shutdown' => 'Error de apagado',
    'logger.unopenable_stream' => 'no se puede abrir el archivo de registro \'{path}\'',
];
