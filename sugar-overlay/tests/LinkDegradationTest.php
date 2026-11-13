<?php

declare(strict_types=1);

/*
 * m15 (lib-fixes-1103): an OSC-8 hyperlink whose URL carries a control
 * byte makes sugar-cellbuffer's Hyperlink constructor throw
 * InvalidArgumentException. The old linkFromOsc() let that escape — so a
 * hostile line in the DIFF pass (which re-parses every OSC to compare
 * link state) crashed the whole composite() call instead of painting the
 * row without a link.
 *
 * linkFromOsc now degrades fail-closed: the malformed link becomes
 * null, the row survives, and the text still paints. These pins drive
 * the crash path through both the full frame and the delta frame.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\MarkedFrame;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

final class LinkDegradationTest extends TestCase
{
    private const BG = "..............................\n..............................";

    private const MALFORMED = "\x1b]8;;http://a\x01b\x07X";

    public function testControlByteInALinkUrlDegradesToPlainTextInsteadOfThrowing(): void
    {
        $veil = Veil::new()->withPosition(Position::TOP, Position::LEFT);

        // Full-frame pass: must not throw and must paint the character.
        $full = $veil->composite(self::MALFORMED, self::BG, Position::TOP, Position::LEFT);
        $this->assertStringContainsString('X', $full);

        // Delta pass (second composite at the same size): paintRow re-walks
        // the OSC here — this is where the exception used to escape.
        $delta = $veil->composite(self::MALFORMED . '!', self::BG, Position::TOP, Position::LEFT);
        $this->assertIsString($delta);
    }

    public function testFullPassWidthInvariantSurvivesTheMalformedLink(): void
    {
        $veil = Veil::new()->withPosition(Position::TOP, Position::LEFT)->withBackdrop(60);
        $frame = $veil->composite(self::MALFORMED, self::BG, Position::TOP, Position::LEFT);

        foreach (explode("\n", $frame) as $row) {
            $this->assertSame(30, MarkedFrame::measure($row), 'a degraded link must not corrupt row geometry');
        }
    }

    public function testWellFormedLinksAreUntouchedByTheDegrade(): void
    {
        $veil = Veil::new()->withPosition(Position::TOP, Position::LEFT);
        $frame = $veil->composite("\x1b]8;;http://example.com\x07LINK\x1b]8;;\x07", self::BG, Position::TOP, Position::LEFT);

        $this->assertStringContainsString("\x1b]8;;http://example.com\x07LINK", $frame, 'valid links must round-trip verbatim');
    }

    public function testColourIsNeutralizedAtTheSpliceSeamButBoldDeliberatelyRidesAcross(): void
    {
        // sugar-overlay-063 pinned at the real seam: a background row whose
        // prefix ends mid-style gets the COLOUR neutralizer (39/49) at the
        // splice boundary — and ONLY that. The bold stays armed across the
        // seam into the overlay: resetting attributes there would clobber
        // link pens and chrome alike, so the documented rule is that attrs
        // ride. The geometry must survive the splice untouched.
        $bg = "\e[1;31mAAAAABBBBB\n......................";
        $frame = Veil::new()->composite('FG', $bg, Position::TOP, Position::LEFT, 5, 0);

        $this->assertStringContainsString(
            "\e[1;31mAAAAA\e[39m\e[49mFG",
            $frame,
            'the seam emits 39/49 — no 22/23/24/27 and no SGR 0: bold rides across'
        );
        $this->assertStringNotContainsString("\e[0m", $frame);
        $this->assertStringNotContainsString("\e[22m", $frame);
        $rows = \explode("\n", $frame);
        $this->assertSame(10, MarkedFrame::measure($rows[0]), 'the neutralizer costs zero cells');
    }
}
