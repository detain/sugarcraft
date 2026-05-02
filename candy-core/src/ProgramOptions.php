<?php

declare(strict_types=1);

namespace CandyCore\Core;

use React\EventLoop\LoopInterface;

/**
 * Tunables for {@see Program}. All fields are optional with sensible defaults.
 *
 * @phpstan-type Stream resource
 */
final class ProgramOptions
{
    /**
     * @param resource|null $input  stdin replacement; null = STDIN
     * @param resource|null $output stdout replacement; null = STDOUT
     */
    public function __construct(
        public readonly bool $useAltScreen = false,
        public readonly bool $catchInterrupts = true,
        public readonly bool $hideCursor = true,
        public readonly float $framerate = 60.0,
        public readonly MouseMode $mouseMode = MouseMode::Off,
        public readonly bool $reportFocus = false,
        public readonly mixed $input = null,
        public readonly mixed $output = null,
        public readonly ?LoopInterface $loop = null,
    ) {}
}
