<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\Ansi;

/**
 * Minimal terminal renderer.
 *
 * Round 1: writes the full frame each render — clears the screen and
 * homes the cursor before emitting the model's view(). A diff-based
 * renderer that only repaints changed lines lands in a follow-up.
 *
 * @internal
 */
final class Renderer
{
    private string $lastFrame = '';

    /** @param resource $out */
    public function __construct(private $out) {}

    public function render(string $frame): void
    {
        if ($frame === $this->lastFrame) {
            return;
        }
        // Move cursor home + clear-to-end. Keeps scrollback intact (vs full ED2).
        fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseToEnd() . $frame);
        $this->lastFrame = $frame;
    }

    public function reset(): void
    {
        $this->lastFrame = '';
    }
}
