<?php

declare(strict_types=1);

namespace SugarCraft\Top\State;

use SugarCraft\Core\Msg;

/**
 * Result of a tree-state write: which change revision reached disk, or why
 * it did not. A failure is not shown in-session (the state is a
 * convenience); `bin/candy-top` reports a failed exit-time save on STDERR.
 * `$note` carries a save that succeeded with a cause worth naming — entries
 * pruned to keep the file under the read-side budget — and is reported the
 * same way.
 */
final class TreeStateSavedMsg implements Msg
{
    public function __construct(
        public readonly int $revision,
        public readonly bool $ok,
        public readonly string $error = '',
        public readonly string $note = '',
    ) {
    }
}
