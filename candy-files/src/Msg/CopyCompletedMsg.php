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
 * No upstream — house pattern. Finding 106 relabel: an earlier line
 * claimed "Mirrors yorukot/superfile.asyncOps.CopyCompleted"; no such
 * symbol exists upstream — this resolution message is the library's own.
 */
final readonly class CopyCompletedMsg implements Msg
{
    /**
     * CF-40 (audit 064): the payload used to be the full absolute
     * source => destination map of the ok-set — retained on the undo
     * stack for the life of the session. The legs are homogeneous
     * (one source dir, one destination dir), so the message now carries
     * the compact spelling: the two directories plus the ok-set's leg
     * names in schedule order.
     *
     * @param string $srcDir Source directory of the copied legs (staging root for extract)
     * @param string $dstDir Destination directory
     * @param list<string> $okNames Legs that ACTUALLY copied, relative to $srcDir (undo records these only)
     * @param ?list<string> $okDstNames Destination basenames when they differ from the
     *        $okNames spelling (the archive-extract circuit lands staged
     *        members under their flat name); null means flat legs
     * @param int $errors Number of items that failed to copy
     * @param int $skipped Number of names filtered before scheduling (null/'..'/'' entries)
     * @param int $overwrote Number of successful legs that replaced a destination already existing at schedule time
     * @param ?string $errorReason CF-21: first exception diagnostic (class: message)
     *        from the batch, if any leg threw instead of being disk-refused
     */
    public function __construct(
        public string $srcDir,
        public string $dstDir,
        public array $okNames,
        public ?array $okDstNames,
        public int $errors,
        public int $skipped,
        public int $overwrote,
        public ?string $errorReason = null,
    ) {}
}
