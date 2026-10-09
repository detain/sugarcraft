<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Forms\Field\Slider;

/**
 * crush_media W3.2: Slider mouse-drag — host-callable zone form
 * (handleZoneMouse) and the widget-relative update() dispatch. Coercion
 * edges saturate through normalise() (the single clamp/snap site is the
 * ORACLE for every expected value), Press/Motion/Release are identical in
 * v1, wheel and non-left buttons are refused, and the keyboard path stays
 * byte-identical (SliderTest untouched).
 */
final class SliderMouseTest extends TestCase
{
    /** Left-press a zone coordinate through the host-callable form. */
    private function dragAt(Slider $s, int $x, int $zoneLeft, int $zoneWidth): ?Slider
    {
        return $s->handleZoneMouse(new MouseMsg($x, 1, MouseButton::Left, MouseAction::Press), $zoneLeft, $zoneWidth);
    }

    // ------------------------------------------------------------------
    // Coercion edges — normalise() is the oracle, never a re-derived snap
    // ------------------------------------------------------------------

    public function testZoneEdgesSaturateToLatticeEnds(): void
    {
        $s = Slider::new('s', 0, 10, 3); // lattice 0,3,6,9 (top 9 <= max 10)
        self::assertSame(0, $this->dragAt($s, -5, 1, 20)?->value, 'left of zone clamps to min');
        self::assertSame(0, $this->dragAt($s, 0, 1, 20)?->value);
        self::assertSame(9, $this->dragAt($s, 20, 1, 20)?->value, 'right of zone clamps to top lattice');
        self::assertSame(9, $this->dragAt($s, 500, 1, 20)?->value);
    }

    public function testExactZoneWalksTheLatticeEndpointToEndpoint(): void
    {
        $s = Slider::new('s', 0, 9, 3); // steps() == 3 -> zone width 4 hits every lattice point
        $expected = [1 => 0, 2 => 3, 3 => 6, 4 => 9];
        foreach ($expected as $x => $value) {
            self::assertSame($value, $this->dragAt($s, $x, 1, 4)?->value, "x=$x");
        }
    }

    public function testMidpointRoutesThroughNormaliseSnap(): void
    {
        $s = Slider::new('s', 0, 10, 3);
        // zone (1, 20): x=10 -> ratio 9/19 -> raw 4.7368... -> snaps to 6.
        $raw = (9 / 19) * 10;
        self::assertSame(6, $this->dragAt($s, 10, 1, 20)?->value);
        self::assertSame(
            $s->withValue($raw)->value,
            $this->dragAt($s, 10, 1, 20)?->value,
            'the drag value must equal what withValue() of the same raw number yields — single-site law',
        );
    }

    public function testDegenerateZoneNeverDividesByZero(): void
    {
        $s = Slider::new('s', 0, 10, 1);
        self::assertSame(0, $this->dragAt($s, 5, 5, 1)?->value, 'width-1 zone pins the left endpoint');
        self::assertSame(10, $this->dragAt($s, 10, 5, 1)?->value, 'one cell past a pinned span already overshoots — normalise saturates');
        self::assertSame(0, $this->dragAt($s, 5, 5, 0)?->value, 'zero-width zone floors the span at one cell');
    }

    public function testDragSweepIsMonotonicAndReachesTopLattice(): void
    {
        $s = Slider::new('s', 0, 100, 7); // top lattice 98
        $previous = null;
        $strictlyRose = false;
        for ($x = 1; $x <= 23; ++$x) {
            $value = $this->dragAt($s, $x, 2, 20)?->value;
            if ($previous !== null) {
                self::assertGreaterThanOrEqual($previous, $value, "x=$x broke monotonicity");
                if ($value > $previous) {
                    $strictlyRose = true;
                }
            }
            $previous = $value;
        }
        self::assertTrue($strictlyRose, 'a 1..23 sweep over a 0..100 zone must move');
        self::assertSame(98, $this->dragAt($s, 21, 2, 20)?->value);
        self::assertSame($s->withValue(100)->value, $this->dragAt($s, 21, 2, 20)?->value);
    }

    // ------------------------------------------------------------------
    // Keyboard-vs-drag lattice consistency + behaviour pairs
    // ------------------------------------------------------------------

    public function testKeyboardAndDragAgreeOnTheSameLatticePoints(): void
    {
        $s = Slider::new('s', 0, 10, 3);
        [$focused, ] = $s->focus();
        [$homeKey, ] = $focused->update(new KeyMsg(KeyType::Home));
        [$endKey, ] = $focused->update(new KeyMsg(KeyType::End));
        self::assertSame(0, $homeKey->value);
        self::assertSame(9, $endKey->value);
        // Same endpoints through the zone form.
        self::assertSame($homeKey->value, $this->dragAt($s, 1, 1, 4)?->value);
        self::assertSame($endKey->value, $this->dragAt($s, 4, 1, 4)?->value);
        // A mid key-walk equals the drag to the same lattice point: Home +
        // two Rights is 6, and x=3 of the exact 4-cell zone is also 6.
        [$walk, ] = $homeKey->update(new KeyMsg(KeyType::Right));
        [$walk, ] = $walk->update(new KeyMsg(KeyType::Right));
        self::assertSame(6, $walk->value);
        self::assertSame($walk->value, $this->dragAt($s, 3, 1, 4)?->value);
    }

    public function testDragReturnsANewInstanceAndLeavesTheSourcePure(): void
    {
        $s = Slider::new('s', 0, 100, 1);
        $first = $this->dragAt($s, 11, 2, 20);
        self::assertNotNull($first);
        self::assertNotSame($s, $first);
        self::assertSame(0, $s->value, 'the source instance must not move');
        self::assertSame($first->value, $this->dragAt($s, 11, 2, 20)?->value, 'handler is a pure function of (x, zone)');
    }

    public function testPressMotionReleaseAreIdenticalInV1(): void
    {
        $s = Slider::new('s', 0, 100, 1);
        $press   = $s->handleZoneMouse(new MouseMsg(10, 1, MouseButton::Left, MouseAction::Press), 2, 20);
        $motion  = $s->handleZoneMouse(new MouseMotionMsg(10, 1, MouseButton::Left, MouseAction::Motion), 2, 20);
        $release = $s->handleZoneMouse(new MouseReleaseMsg(10, 1, MouseButton::Left, MouseAction::Release), 2, 20);
        self::assertSame($press?->value, $motion?->value);
        self::assertSame($press?->value, $release?->value);
        self::assertNotNull($press);
    }

    public function testWheelRightButtonAndReadonlyAreRefused(): void
    {
        $s = Slider::new('s', 0, 100, 1);
        self::assertNull($s->handleZoneMouse(new MouseWheelMsg(10, 1, MouseButton::WheelDown, MouseAction::Press), 2, 20));
        self::assertNull($s->handleZoneMouse(new MouseMsg(10, 1, MouseButton::Right, MouseAction::Press), 2, 20));
        [$ro, ] = $s->withReadonly(true)->focus();
        self::assertNull($ro->handleZoneMouse(new MouseMsg(10, 1, MouseButton::Left, MouseAction::Press), 2, 20));
    }

    // ------------------------------------------------------------------
    // update() dispatch: widget-relative track row, focus and inertia
    // ------------------------------------------------------------------

    public function testUpdateIsDeafWhenUnfocused(): void
    {
        $s = Slider::new('s', 0, 100, 1);
        [$after, $cmd] = $s->update(new MouseMsg(10, 1, MouseButton::Left, MouseAction::Press));
        self::assertSame($s, $after, 'unfocused slider must identity-refuse mouse');
        self::assertNull($cmd);
    }

    public function testUpdateOnlyGrabsTheTrackRow(): void
    {
        [$s, ] = Slider::new('s', 0, 100, 1)->withTitle('Vol')->withDescription('volume')->focus();
        // view() lines: 1 title, 2 description, 3 track.
        [$missed, ] = $s->update(new MouseMsg(10, 1, MouseButton::Left, MouseAction::Press));
        self::assertSame($s, $missed, 'a press on the title row is inert');
        [$hit, ] = $s->update(new MouseMsg(10, 3, MouseButton::Left, MouseAction::Press));
        self::assertNotSame($s, $hit, 'a press on the track row drags');
        // The band starts at column 2 (bracket owns column 1) and spans width.
        self::assertSame($s->withValue((10 - 2) / 19 * 100)->value, $hit->value);
    }

    public function testTrackBracketsSaturateThroughUpdate(): void
    {
        [$s, ] = Slider::new('s', 0, 100, 1)->withValue(50)->focus(); // width 20
        [$left, ] = $s->update(new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press));
        self::assertSame(0, $left->value, 'pressing the left bracket saturates to min');
        [$right, ] = $s->update(new MouseMsg(22, 1, MouseButton::Left, MouseAction::Press));
        self::assertSame(100, $right->value, 'pressing the right bracket saturates to max');
    }

    public function testWheelIsInertOnTheTrackRow(): void
    {
        [$s, ] = Slider::new('s', 0, 100, 1)->withValue(50)->focus();
        [$after, ] = $s->update(new MouseWheelMsg(10, 1, MouseButton::WheelDown, MouseAction::Press));
        self::assertSame($s, $after, 'crush Viewport already routes wheel as Up/Down keys — no double-step');
    }

    public function testKeyboardPathStaysByteIdenticalAfterTheMouseArm(): void
    {
        // The ItemList E736 5.14 discipline: the MouseMsg arm sits BEFORE the
        // keyboard door, so every KeyMsg transition below is the pre-W3.2
        // behaviour re-pinned here as a smoke pair.
        [$s, ] = Slider::new('s', 0, 9, 3)->focus();
        [$s, ] = $s->update(new KeyMsg(KeyType::Right));
        self::assertSame(3, $s->value);
        [$s, ] = $s->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertSame(0, $s->value);
        [$s, ] = $s->update(new KeyMsg(KeyType::End));
        self::assertSame(9, $s->value);
        [$s, ] = $s->update(new KeyMsg(KeyType::Up));
        self::assertSame(9, $s->value, 'Up stays with the Form');
    }
}
