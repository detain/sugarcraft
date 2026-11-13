<?php

/**
 * French translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'journal fatal : {message}',
    'logger.invalid_stream' => 'Le flux doit être une ressource valide',
    'psrbridge.not_string' => 'Le niveau de journalisation doit être l\'une des constantes PSR-3 LogLevel ; obtenu {got}',
    'psrbridge.unknown_level' => 'Niveau de journalisation inconnu \'{level}\' ; attendu : une des constantes PSR-3 LogLevel',
    'parts.unknown' => 'partie(s) de ligne de journal inconnue(s) : {parts}',
    'parts.duplicate' => 'partie(s) de ligne de journal dupliquée(s) : {parts}',
    'log.fatal_error' => 'Erreur fatale',
    'log.fatal_parse' => 'Erreur d\'analyse',
    'log.fatal_core' => 'Erreur de cœur',
    'log.fatal_compile' => 'Erreur de compilation',
    'log.fatal_user' => 'Erreur utilisateur',
    'log.fatal_shutdown' => 'Erreur d\'arrêt',
    'logger.unopenable_stream' => 'impossible d\'ouvrir le fichier de journal \'{path}\'',
];
