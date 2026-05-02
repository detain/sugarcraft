<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Reply to a {@see \CandyCore\Core\Cmd::requestCursorColor()}. The
 * terminal answers `OSC 12 ; rgb:RRRR/GGGG/BBBB ST|BEL` and the input
 * reader scales each channel down to 8-bit per channel.
 */
final class CursorColorMsg implements Msg
{
    public function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {}

    public function hex(): string
    {
        return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }
}
