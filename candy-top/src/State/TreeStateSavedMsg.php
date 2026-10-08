<?php

declare(strict_types=1);

namespace SugarCraft\Top\State;

use SugarCraft\Core\Msg;

/**
 * Result of a tree-state write: which change revision reached disk, or why
 * it did not. A failure is not shown in-session (the state is a
 * convenience); `bin/candy-top` reports a failed exit-time save on STDERR.
 */
final class TreeStateSavedMsg implements Msg
{
    public function __construct(
        public readonly int $revision,
        public readonly bool $ok,
        public readonly string $error = '',
    ) {
    }
}
