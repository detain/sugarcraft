<?php

declare(strict_types=1);

/*
 * Pins for the animation contract (m10 clamp, M8 reduced motion, M9
 * scrim ramp, M5 lastFullFrame law), round lib-fixes-1103.
 *
 * - animate() treated progress as already-normalized: 2.5 or -1.0 rode
 *   straight into the easing tables (Scale at -1.0 emits the empty
 *   string, i.e. an invisible modal painted as "fully open" by any
 *   progress-polling host that overshoots).
 * - reduced motion was unrepresentable: hosts had to skip animate()
 *   themselves and reimplement the rest frame.
 * - the scrim snapped to full opacity while the modal was still
 *   sliding in — the first fade frame dimmed the whole backdrop.
 * - delta frames carry no zone markers, so hit-testing must read
 *   lastFullFrame(), not the returned delta.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\Animation\AnimationKind;
use SugarCraft\Overlay\MarkedFrame;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

final class AnimationProgressTest extends TestCase
{
    private const BG = "..............................\n..............................\n..............................";

    /** Fresh session per render — comparisons must be full-frame to full-frame. */
    private function veil(?AnimationKind $kind = null, int $backdrop = 0): Veil
    {
        $veil = Veil::new()->withPosition(Position::TOP, Position::LEFT);
        if ($kind !== null) {
            $veil = $veil->withAnimation($kind);
        }

        return $veil->withBackdrop($backdrop);
    }

    public function testProgressIsClampedToTheUnitInterval(): void
    {
        $overshoot = $this->veil(AnimationKind::FADE)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 2.5);
        $atEnd = $this->veil(AnimationKind::FADE)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 1.0);
        $this->assertSame($atEnd, $overshoot, 'progress past 1.0 must paint the settled frame, not extrapolate');

        $negative = $this->veil(AnimationKind::SLIDE)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, -1.0);
        $atStart = $this->veil(AnimationKind::SLIDE)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.0);
        $this->assertSame($atStart, $negative, 'progress below 0 must paint the opening frame, not vanish');
    }

    public function testNonFiniteProgressTakesTheDocumentedEnds(): void
    {
        $fade = AnimationKind::FADE;
        $atEnd = $this->veil($fade)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 1.0);
        $atStart = $this->veil($fade)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.0);
        $this->assertNotSame($atStart, $atEnd, 'the two ends must be distinguishable or the non-finite pins below are vacuous');

        $nan = $this->veil($fade)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, \NAN);
        $this->assertSame($atEnd, $nan, 'NAN progress must paint the settled end — the documented saturation for incomparable input');

        $this->assertSame($atEnd, $this->veil($fade)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, \INF), '+INF must saturate to the end');
        $this->assertSame($atStart, $this->veil($fade)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, -\INF), '-INF must saturate to the start');
    }

    public function testReducedMotionSnapsToTheSettledFrame(): void
    {
        $this->assertSame(
            $this->veil(AnimationKind::FADE)->composite('MODAL', self::BG, Position::TOP, Position::LEFT),
            $this->veil(AnimationKind::FADE)->withReducedMotion(true)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.3),
            'with reduced motion animate() must emit exactly the settled frame in one shot'
        );
        $this->assertNotSame(
            $this->veil(AnimationKind::FADE)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.3),
            $this->veil(AnimationKind::FADE)->withReducedMotion(true)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.3),
            'without the opt-in, mid-animation frames must still differ from the settled frame'
        );
        // The flag survives ordinary clones.
        $this->assertSame(
            $this->veil(AnimationKind::FADE)->withReducedMotion(true)->composite('MODAL', self::BG, Position::TOP, Position::LEFT),
            $this->veil(AnimationKind::FADE)->withReducedMotion(true)->withZIndex(4)->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.2),
            'withZIndex must not silently drop reducedMotion'
        );
    }

    public function testScrimRampsWithTheAnimation(): void
    {
        // dimLine emits 38;2;v;v;v with v = round(255 * (1 - opacity/100)).
        // Backdrop 80 at rest → 51. At progress 0.5 the scrim must sit at
        // round(80*0.5)=40 → 153 — partially dimmed, never full-strength.
        $half = $this->veil(AnimationKind::FADE, 80)
            ->animate('', self::BG, Position::TOP, Position::LEFT, 0.5);
        $this->assertStringContainsString("\e[38;2;153;153;153m", $half, 'mid-animation scrim must be half-ramped');
        $this->assertStringNotContainsString("\e[38;2;51;51;51m", $half, 'the first animated frame must not snap to full opacity');

        $settled = $this->veil(AnimationKind::FADE, 80)
            ->animate('', self::BG, Position::TOP, Position::LEFT, 1.0);
        $this->assertStringContainsString("\e[38;2;51;51;51m", $settled, 'settled scrim must reach the configured opacity');
    }

    public function testZeroBackdropIsUnaffectedByTheRamp(): void
    {
        $half = $this->veil(AnimationKind::FADE, 0)
            ->animate('MODAL', self::BG, Position::TOP, Position::LEFT, 0.5);
        // Row 2 is backdrop-only: with opacity 0, dimLine is the identity,
        // so it must ride through byte-for-byte (the fade pen on the modal
        // row is legitimate animation, not the scrim).
        $rows = explode("\n", $half);
        $this->assertSame('..............................', $rows[1], 'an undimmed backdrop stays byte-identical at every progress');
    }

    public function testLastFullFrameFeedsTheZoneScannerAcrossDeltaFrames(): void
    {
        // M5: the second same-size composite diffs against the first and
        // returns a delta with NO marker envelopes. Scanning the delta
        // finds nothing; scanning lastFullFrame() finds the moved button.
        $veil = $this->veil();
        $first = $veil->mark('one', 'ONE') . "\nsecond row";
        $frame1 = $veil->composite($first, self::BG, Position::TOP, Position::LEFT);
        $scanned = $veil->scan($frame1);
        $this->assertSame('one', $scanned->hit(1, 1)?->id);

        $moved = str_repeat('.', 10) . $scanned->mark('two', 'TWO');
        $frame2 = $scanned->composite($moved, self::BG, Position::TOP, Position::LEFT);
        $this->assertNotSame($this->veil()->composite($moved, self::BG, Position::TOP, Position::LEFT), $frame2, 'the second same-size composite is a delta, not the full frame');

        $full = $scanned->lastFullFrame();
        $this->assertNotNull($full);
        $this->assertStringContainsString("\xEE\x80\x80two\xEE\x80\x81", (string) $full, 'lastFullFrame() carries the zone envelopes the delta omits');

        $rehit = $scanned->scan((string) $full);
        $this->assertSame('two', $rehit->hit(11, 1)?->id, 'the moved button is hittable through lastFullFrame()');
    }

    public function testMarkedRowsStillFillTheCanvasUnderAnimation(): void
    {
        $veil = $this->veil(AnimationKind::FADE, 50);
        $frame = $veil->composite($veil->mark('b', 'Btn'), self::BG, Position::TOP, Position::LEFT);

        foreach (explode("\n", $frame) as $row) {
            $this->assertSame(30, MarkedFrame::measure($row));
        }
    }
}
