<?php

declare(strict_types=1);

namespace SugarCraft\Top\State;

use SugarCraft\Core\Msg;

/**
 * The debounce tick after a tree collapse change: the proc panel saves the
 * tree state only when no newer change ({@see $revision}) arrived since.
 */
final class TreeStateFlushMsg implements Msg
{
    public function __construct(
        public readonly int $revision,
    ) {
    }
}
