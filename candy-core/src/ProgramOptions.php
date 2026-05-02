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
        public readonly bool $bracketedPaste = false,
        /** Enable DEC mode 2027 (grapheme cluster mode) — Bubble Tea v2 default. */
        public readonly bool $unicodeMode = true,
        /**
         * Inline mode — render only the program's own rows instead of
         * taking over the viewport. Pairs with `useAltScreen=false`
         * for CandyShell-style prompts that should leave scrollback
         * intact.
         */
        public readonly bool $inlineMode = false,
        public readonly mixed $input = null,
        public readonly mixed $output = null,
        public readonly ?LoopInterface $loop = null,
    ) {}
}
