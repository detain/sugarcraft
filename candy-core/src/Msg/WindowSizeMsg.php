<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Emitted on terminal resize (`SIGWINCH` on POSIX) and once at startup so
 * Models can lay themselves out for the available space.
 */
final class WindowSizeMsg implements Msg
{
    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {}
}
