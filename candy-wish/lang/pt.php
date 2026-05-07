<?php

/**
 * Portuguese translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'não é possível abrir php://stderr',
    'middleware.stderr_not_resource' => 'stderr tem de ser um recurso',
    'logger.cannot_open_target'      => 'não é possível abrir o destino do registo: {target}',
    'logger.invalid_target'          => 'O destino do logger tem de ser um caminho, recurso ou null',
    'bubbletea.bad_factory'          => 'A fábrica de BubbleTea tem de devolver um objeto com um método run(); obtido: {got}',
];
