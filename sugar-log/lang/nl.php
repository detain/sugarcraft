<?php

/**
 * Dutch translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'fataal log: {message}',
    'logger.invalid_stream' => 'De stream moet een geldige bron zijn',
    'psrbridge.not_string' => 'Het logniveau moet een van de PSR-3 LogLevel-constanten zijn; gekregen {got}',
    'psrbridge.unknown_level' => 'Onbekend logniveau \'{level}\'; verwacht een van de PSR-3 LogLevel-constanten',
    'parts.unknown' => 'onbekende logregelonderdelen: {parts}',
    'parts.duplicate' => 'dubbele logregelonderdelen: {parts}',
    'log.fatal_error' => 'Fatale fout',
    'log.fatal_parse' => 'Parseerfout',
    'log.fatal_core' => 'Corefout',
    'log.fatal_compile' => 'Compileerfout',
    'log.fatal_user' => 'Gebruikersfout',
    'log.fatal_shutdown' => 'Stopfout',
    'logger.unopenable_stream' => 'logbestand \'{path}\' kan niet geopend worden',
];
