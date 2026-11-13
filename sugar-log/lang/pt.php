<?php

/**
 * Portuguese translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'registo fatal: {message}',
    'logger.invalid_stream' => 'O fluxo deve ser um recurso válido',
    'psrbridge.not_string' => 'O nível de log deve ser uma das constantes PSR-3 LogLevel; obtido {got}',
    'psrbridge.unknown_level' => 'Nível de log desconhecido \'{level}\'; esperada uma das constantes PSR-3 LogLevel',
    'parts.unknown' => 'parte(s) da linha de log desconhecida(s): {parts}',
    'parts.duplicate' => 'parte(s) da linha de log duplicada(s): {parts}',
    'log.fatal_error' => 'Erro fatal',
    'log.fatal_parse' => 'Erro de análise',
    'log.fatal_core' => 'Erro de núcleo',
    'log.fatal_compile' => 'Erro de compilação',
    'log.fatal_user' => 'Erro de usuário',
    'log.fatal_shutdown' => 'Erro de encerramento',
];
