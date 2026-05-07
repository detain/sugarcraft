<?php

/**
 * Italian translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'impossibile aprire php://stderr',
    'middleware.stderr_not_resource' => 'stderr deve essere una risorsa',
    'logger.cannot_open_target'      => 'impossibile aprire la destinazione del log: {target}',
    'logger.invalid_target'          => 'La destinazione del logger deve essere un percorso, una risorsa o null',
    'bubbletea.bad_factory'          => 'La fabbrica BubbleTea deve restituire un oggetto con un metodo run(); ottenuto: {got}',
];
