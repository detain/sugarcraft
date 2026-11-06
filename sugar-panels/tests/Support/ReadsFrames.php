<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests\Support;

/**
 * Peels the frame protocol off a rendered string so layout tests can read cells.
 *
 * Every frame returned by SugarPanels::render() with at least one column is
 * self-positioning: it opens
 * with an erase-in-display (only after a viewport resize) and then a cursor
 * home. Those tokens are protocol, not content, and the pins that use this
 * helper are about which glyph lands in which cell — so they compare the body.
 * The protocol itself is pinned on the UNPEELED string in
 * {@see \SugarCraft\Panels\Tests\FrameProtocolTest}.
 */
trait ReadsFrames
{
    protected static function body(string $frame): string
    {
        foreach (["\x1b[2J", "\x1b[H"] as $token) {
            if (\str_starts_with($frame, $token)) {
                $frame = \substr($frame, \strlen($token));
            }
        }

        return $frame;
    }
}
