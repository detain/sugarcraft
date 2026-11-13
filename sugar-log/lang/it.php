<?php

/**
 * Italian translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'log fatale: {message}',
    'logger.invalid_stream' => 'Il flusso deve essere una risorsa valida',
    'psrbridge.not_string' => 'Il livello di log deve essere una delle costanti PSR-3 LogLevel; ottenuto {got}',
    'psrbridge.unknown_level' => 'Livello di log sconosciuto \'{level}\'; attesa una delle costanti PSR-3 LogLevel',
    'parts.unknown' => 'parte/i di riga di log sconosciuta/e: {parts}',
    'parts.duplicate' => 'parte/i di riga di log duplicata/e: {parts}',
    'log.fatal_error' => 'Errore fatale',
    'log.fatal_parse' => 'Errore di analisi',
    'log.fatal_core' => 'Errore di core',
    'log.fatal_compile' => 'Errore di compilazione',
    'log.fatal_user' => 'Errore utente',
    'log.fatal_shutdown' => 'Errore di spegnimento',
    'logger.unopenable_stream' => 'impossibile aprire il file di log \'{path}\'',
];
