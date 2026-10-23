<?php

declare(strict_types=1);

namespace SugarCraft\Files\Msg;

use SugarCraft\Core\Msg;

/**
 * Message broadcast when an async copy operation completes.
 *
 * Implements the {@see Msg} marker because sugar-core's Program dispatches
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
     * @param array<string, string> $copiedItems Map of source → destination for the legs that ACTUALLY copied (undo records these only)
     * @param int $errors Number of items that failed to copy
     * @param int $skipped Number of names filtered before scheduling (null/'..'/'' entries)
     * @param int $overwrote Number of successful legs that replaced a destination already existing at schedule time
     */
    public function __construct(
        public array $copiedItems,
        public int $errors,
        public int $skipped,
        public int $overwrote,
    ) {}
}
