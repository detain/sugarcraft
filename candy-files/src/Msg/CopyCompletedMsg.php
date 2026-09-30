<?php

declare(strict_types=1);

namespace SugarCraft\Files\Msg;

use SugarCraft\Core\Msg;

/**
 * Message broadcast when an async copy operation completes.
 *
 * Implements the {@see Msg} marker because candy-core's Program dispatches
 * promise resolutions through a `?Msg`-typed callback
 * ({@see \SugarCraft\Core\Program}): a class that is not a Msg turns the
 * resolution into an "Unhandled promise rejection" TypeError, so the Model
 * never learns the copy finished.
 *
 * Mirrors yorukot/superfile.asyncOps.CopyCompleted.
 */
final readonly class CopyCompletedMsg implements Msg
{
    /**
     * @param array<string, string> $copiedItems Map of source → destination
     * @param int $errors Number of items that failed to copy
     * @param list<string|null> $names Names of items that were copied
     * @param string|null $dst Destination directory
     */
    public function __construct(
        public array $copiedItems,
        public int $errors,
        public array $names,
        public ?string $dst,
    ) {}
}
