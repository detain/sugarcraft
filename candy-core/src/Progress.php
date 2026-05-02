<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Per-frame taskbar-progress description carried on a {@see View}.
 * The runtime emits OSC 9;4 only when this value changes between
 * frames so the terminal isn't spammed with redundant escapes.
 */
final class Progress
{
    public function __construct(
        public readonly ProgressBarState $state,
        public readonly int $percent = 0,
    ) {}
}
