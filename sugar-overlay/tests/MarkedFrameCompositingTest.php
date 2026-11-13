<?php

declare(strict_types=1);

/*
 * End-to-end pins for the C1 defect family: marked frames through the
 * FULL pipeline (mark() → composite() → scan() → hit()). The pre-C1
 * measure path charged the U+E000 id U+E001 envelope triples (and link
 * bodies) as cells, so anchoring and clipping desynced from what
 * paintRow places: the README click-recipe scanned the marked CONTENT
 * directly to dodge the broken round-trip, and real composited output
 * put zones on marker bytes, spilled envelope ids as visible garbage at
 * truncation, punched backdrop holes, and clipped rows unpredictably.
 *
 * These pins run the actual compositing path. Neutering the
 * zero-width-envelope accounting in MarkedFrame::segments()/measure()
 * reddens them (mutation-checked when landed).
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Overlay\MarkedFrame;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

final class MarkedFrameCompositingTest extends TestCase
{
    private const OPEN = "\xEE\x80\x80";
    private const CLOSE = "\xEE\x80\x81";

    /** 30x3 backdrop of dots. */
    private const BG = "..............................\n..............................\n..............................";

    private function leftVeil(): Veil
    {
        return Veil::new()->withPosition(Position::TOP, Position::LEFT);
    }

    public function testMarkedButtonRoundTripsThroughCompositeToHit(): void
    {
        // The README recipe, now actually true: mark a button, composite,
        // scan the FRAME (not the content), click the button's cells.
        $veil = $this->leftVeil();
        $content = $veil->mark('ok', 'OK') . ' to continue';
        $frame = $veil->composite($content, self::BG, Position::TOP, Position::LEFT);

        $scanned = $veil->scan($frame);
        $zone = $scanned->hit(1, 1);
        $this->assertNotNull($zone, 'The button zone must be live at its painted cells after compositing');
        $this->assertSame('ok', $zone->id);
        $this->assertNotNull($scanned->hit(2, 1));
        $this->assertNull($scanned->hit(3, 1), 'The space after OK is outside the zone');
    }

    public function testClickOutsideDismissRecipeWorksOnCompositedFrames(): void
    {
        $veil = $this->leftVeil()->withClickOutsideDismiss();
        $content = $veil->mark('ok', 'OK');
        $frame = $veil->composite($content, self::BG, Position::TOP, Position::LEFT);

        $scanned = $veil->scan($frame);
        $this->assertFalse($scanned->isClickOutside(new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press)));
        $this->assertTrue($scanned->isClickOutside(new MouseMsg(20, 3, MouseButton::Left, MouseAction::Press)));
    }

    public function testEveryCompositedRowMeasuresToTheCanvasWidth(): void
    {
        // Backdrop-hole family: pre-C1, marked rows clipped short of the
        // canvas (envelope bytes "used" budget), leaving un-painted holes
        // that the diff then froze into ghosts.
        $veil = $this->leftVeil()->withBackdrop(60);
        $content = $veil->mark('btn', 'Submit') . " and " . $veil->mark('x', 'cancel');
        $frame = $veil->composite($content, self::BG, Position::TOP, Position::LEFT);

        foreach (explode("\n", $frame) as $row) {
            $this->assertSame(30, MarkedFrame::measure($row), 'Row must fill exactly the 30-column canvas');
        }
    }

    public function testZoneSurvivesTruncationAtTheCanvasEdge(): void
    {
        // A button ending exactly at the cut keeps its zone balanced:
        // the real close envelope rides the clipped row, no garbage.
        $veil = $this->leftVeil();
        $content = str_repeat('y', 28) . $veil->mark('go', 'GO');
        $frame = $veil->composite($content, self::BG, Position::TOP, Position::LEFT);

        $first = explode("\n", $frame)[0];
        $this->assertSame(30, MarkedFrame::measure($first));

        $scanned = $veil->scan($frame);
        $zone = $scanned->hit(29, 1);
        $this->assertNotNull($zone, 'The button at columns 29-30 must remain hittable');
        $this->assertSame('go', $zone->id);
    }

    public function testEnvelopeIdsNeverSpillAsVisibleGarbage(): void
    {
        // Pre-C1, truncation could cut an envelope in half and print the
        // id bytes; the composed frame must carry only balanced envelopes
        // and their own id text between sentinels.
        $veil = $this->leftVeil();
        $content = $veil->mark('save', 'SAVE') . str_repeat('z', 40); // cut at 30
        $frame = $veil->composite($content, self::BG, Position::TOP, Position::LEFT);

        $first = explode("\n", $frame)[0];
        $stripped = str_replace([self::OPEN, self::CLOSE], "\x00", $first);
        // Remove each complete envelope (sentinels + id) and what remains
        // must be EXACTLY the visible row: the canvas-width clip of the
        // label and filler, with no id bytes painted and no backdrop tail.
        $visible = preg_replace('/\x00[^\x00]*\x00/', '', $stripped);
        $this->assertSame('SAVE' . str_repeat('z', 26), $visible);

        // Balance: the save zone opened and closed before the cut, so the
        // open and close envelopes each appear once.
        $this->assertSame(
            substr_count($stripped, "\x00save\x00"),
            substr_count($stripped, "\x00/save\x00"),
            'Open and close envelopes must balance in the composited row'
        );
    }

    public function testLinkRoundTripsThroughCompositeWithoutLeak(): void
    {
        // An OSC-8 link inside the foreground must survive compositing
        // with its opener and closer intact and zero columns charged.
        $veil = $this->leftVeil();
        $content = "\e]8;;https://example.com\x07LINK\e]8;;\x07 done";
        $frame = $veil->composite($content, self::BG, Position::TOP, Position::LEFT);

        $this->assertStringContainsString("\e]8;;https://example.com\x07", $frame);
        $this->assertStringContainsString('LINK', $frame);
        $first = explode("\n", $frame)[0];
        $this->assertSame(30, MarkedFrame::measure($first));
    }

    public function testFarOversizedForegroundRespectsTheCanvasAtEveryAnchor(): void
    {
        // m23: fg ≫ bg at all nine anchors — no anchor may emit a row
        // longer than the canvas (pre-C1, envelope-charged widths made
        // clip math desync and rows spilled or holed).
        foreach ([Position::TOP, Position::BOTTOM, Position::CENTER] as $v) {
            foreach ([Position::LEFT, Position::RIGHT, Position::CENTER] as $h) {
                $veil = Veil::new()->withBackdrop(60)->withPosition($v, $h);
                $frame = $veil->composite($veil->mark('m', 'M') . str_repeat('W', 69), self::BG, $v, $h);

                foreach (explode("\n", $frame) as $row) {
                    $this->assertSame(
                        30,
                        MarkedFrame::measure($row),
                        sprintf('anchor %s/%s must not emit a row wider than the 30-cell canvas', $v->name, $h->name)
                    );
                }
            }
        }
    }
}
