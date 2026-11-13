<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests\Support;

use SugarCraft\Core\Util\Ansi;

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

    /**
     * Split a rendered frame into per-row strings for layout pins.
     *
     * This is the single row-reading helper for the whole suite; per-file
     * copies drifted in exactly the way the house duplication guards police,
     * so the two semantic axes callers actually differ on are explicit
     * parameters instead of bespoke bodies:
     *  - $strip  removes every SGR/escape so rows compare as plain glyphs
     *    (framing and margin pins assert cell text, not styling);
     *  - $rtrim  trims right so trailing viewport padding never participates
     *    in a comparison (rows keep empty lines — row position is content).
     *
     * @return list<string> one entry per viewport row
     */
    protected static function rowsOf(string $frame, bool $strip = false, bool $rtrim = false): array
    {
        $body = self::body($frame);
        if ($strip === true) {
            $body = Ansi::strip($body);
        }

        $rows = \explode("\n", $body);

        return $rtrim === true ? \array_map('rtrim', $rows) : $rows;
    }
}
