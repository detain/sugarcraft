<?php

declare(strict_types=1);

/**
 * CB-30 façade: SgrEmitter moved to src/Support (it is a shared pen-emitter,
 * not a diff primitive — sugar-image's OutputOptimizer and future paint
 * paths compose from it). This old-path file keeps the pre-move FQN alive:
 * PSR-4 autoload lands here for `SugarCraft\CellBuffer\Diff\SgrEmitter` and
 * the alias resolves it to the canonical Support class, so consumer import
 * bytes (sugar-image/src/Support/OutputOptimizer.php) never change.
 *
 * @deprecated use SugarCraft\CellBuffer\Support\SgrEmitter
 */

class_alias(
    \SugarCraft\CellBuffer\Support\SgrEmitter::class,
    'SugarCraft\CellBuffer\Diff\SgrEmitter',
);
