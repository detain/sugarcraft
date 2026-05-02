<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Reply to a {@see \CandyCore\Core\Cmd::requestForegroundColor()}. The
 * terminal answers `OSC 10 ; rgb:RRRR/GGGG/BBBB ST|BEL`; each channel
 * is reported as 4 hex digits and we squash to 8-bit per channel
 * (Bubble Tea v2 does the same).
 */
final class ForegroundColorMsg implements Msg
{
    public function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {}

    /**
     * Standard relative-luminance check. True iff the reported colour
     * is "dark enough" that white text would contrast — i.e. the user
     * is on a light theme.
     */
    public function isDark(): bool
    {
        $lum = 0.2126 * ($this->r / 255) + 0.7152 * ($this->g / 255) + 0.0722 * ($this->b / 255);
        return $lum < 0.5;
    }

    public function hex(): string
    {
        return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }
}
