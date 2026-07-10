<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Bar;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Bar\Segment;
use SugarCraft\Sprinkles\Bar\StatusBar;
use SugarCraft\Sprinkles\Style;

final class StatusBarTest extends TestCase
{
    // ─── Factory + defaults ─────────────────────────────────────────────

    public function testNewDefaults(): void
    {
        $b = StatusBar::new();
        $this->assertSame([], $b->leftSegments());
        $this->assertSame([], $b->centerSegments());
        $this->assertSame([], $b->rightSegments());
        $this->assertSame(' ', $b->getSeparator());
        $this->assertNull($b->getWidth());
        $this->assertNull($b->getStyle());
        $this->assertSame(['', ''], $b->getCaps());
        $this->assertSame(' ', $b->getFillChar());
        $this->assertTrue($b->isVisible());
        $this->assertSame('', $b->render());
    }

    // ─── Segment groups + accessors ─────────────────────────────────────

    public function testLeftAcceptsStringsAndSegments(): void
    {
        $seg = Segment::of('b', Style::new()->bold());
        $b = StatusBar::new()->left('a', $seg);
        $segs = $b->leftSegments();
        $this->assertCount(2, $segs);
        $this->assertInstanceOf(Segment::class, $segs[0]);
        $this->assertSame('a', $segs[0]->text());
        $this->assertSame($seg, $segs[1]);
    }

    public function testCenterAndRightSetters(): void
    {
        $b = StatusBar::new()->center('C')->right('R');
        $this->assertSame('C', $b->centerSegments()[0]->text());
        $this->assertSame('R', $b->rightSegments()[0]->text());
    }

    public function testAddAppendsToGroups(): void
    {
        $b = StatusBar::new()->left('a')->addLeft('b')->addLeft('c');
        $this->assertSame(['a', 'b', 'c'], array_map(fn(Segment $s) => $s->text(), $b->leftSegments()));
    }

    public function testAddCenterAndAddRight(): void
    {
        $b = StatusBar::new()->center('a')->addCenter('b')->right('x')->addRight('y');
        $this->assertSame(['a', 'b'], array_map(fn(Segment $s) => $s->text(), $b->centerSegments()));
        $this->assertSame(['x', 'y'], array_map(fn(Segment $s) => $s->text(), $b->rightSegments()));
    }

    // ─── Natural (width-less) rendering ─────────────────────────────────

    public function testNaturalConcatenatesGroups(): void
    {
        $b = StatusBar::new()->left('L')->center('C')->right('R');
        $this->assertSame('LCR', $b->render());
    }

    public function testNaturalJoinsGroupWithSeparator(): void
    {
        // Mirrors the sugar-crush pipe-joined bar wrapped in edge spaces.
        $b = StatusBar::new()->separator('  |  ')->left('a', 'b', 'c')->caps(' ', ' ');
        $this->assertSame(' a  |  b  |  c ', $b->render());
    }

    public function testEmptySegmentsAreSkippedInJoin(): void
    {
        // A blank segment must not produce a doubled separator.
        $b = StatusBar::new()->separator('|')->left('a', '', 'c');
        $this->assertSame('a|c', $b->render());
    }

    // ─── Fixed-width three-column layout ────────────────────────────────

    public function testThreeColumnFill(): void
    {
        $b = StatusBar::new()->left('L')->center('C')->right('R')->width(11);
        $this->assertSame('L    C    R', $b->render());
        $this->assertSame(11, Width::string($b->render()));
    }

    public function testLeftOnlyFillsToWidth(): void
    {
        $b = StatusBar::new()->left('hi')->width(6);
        $this->assertSame('hi    ', $b->render());
    }

    public function testRightAnchoredWithoutCenter(): void
    {
        $b = StatusBar::new()->left('L')->right('R')->width(5);
        $this->assertSame('L   R', $b->render());
    }

    public function testOddFreeSpaceSplitsCeilOnRight(): void
    {
        // free = 4, center present → leftGap floor(2), rightGap ceil(2).
        $b = StatusBar::new()->left('L')->center('C')->right('R')->width(8);
        $this->assertSame('L  C   R', $b->render());
    }

    public function testCapsCountTowardWidth(): void
    {
        $b = StatusBar::new()->left('hi')->width(4)->caps('[', ']');
        $this->assertSame('[hi]', $b->render());
        $this->assertSame(4, Width::string($b->render()));
    }

    // ─── Overflow / truncation ──────────────────────────────────────────

    public function testOverflowTruncatesByPriorityLeftRightCenter(): void
    {
        // total 14 > 8: left keeps 5, right keeps 3, center dropped.
        $b = StatusBar::new()->left('LEFTY')->center('CENT')->right('RIGHT')->width(8);
        $this->assertSame('LEFTYRIG', $b->render());
        $this->assertSame(8, Width::string($b->render()));
    }

    public function testOverflowLeftAloneTruncates(): void
    {
        $b = StatusBar::new()->left('abcdefgh')->width(3);
        $this->assertSame('abc', $b->render());
    }

    public function testZeroWidthRendersEmptyContentButKeepsCaps(): void
    {
        $b = StatusBar::new()->left('hi')->width(0);
        $this->assertSame('', $b->render());
    }

    public function testCapsExceedingWidthAreClamped(): void
    {
        // Caps alone (2) exceed width 1 → clamp the whole bar to width.
        $b = StatusBar::new()->left('x')->width(1)->caps('[', ']');
        $this->assertSame(1, Width::string($b->render()));
    }

    // ─── Per-segment + bar-wide styling ─────────────────────────────────

    public function testPerSegmentStyleBytes(): void
    {
        $seg = Segment::of('ok', Style::new()->foreground(Color::hex('#6ee7b7')));
        $b = StatusBar::new()->left($seg);
        $this->assertSame("\x1b[38;2;110;231;183mok\x1b[0m", $b->render());
    }

    public function testBarWideStyleWrapsWholeLine(): void
    {
        // Mirrors sugar-dash: one fg+bg over the whole padded bar.
        $style = Style::new()->background(Color::hex('#1A1B26'))->foreground(Color::hex('#874BFD'));
        $b = StatusBar::new()->left('X')->right('Y')->width(5)->style($style);
        $this->assertSame("\x1b[38;2;135;75;253m\x1b[48;2;26;27;38mX   Y\x1b[0m", $b->render());
    }

    public function testFillCharAppliesInGaps(): void
    {
        $b = StatusBar::new()->left('L')->right('R')->width(5)->fillChar('.');
        $this->assertSame('L...R', $b->render());
    }

    public function testFillCharEmptyCoercesToSpace(): void
    {
        $this->assertSame(' ', StatusBar::new()->fillChar('')->getFillChar());
    }

    // ─── Visibility ─────────────────────────────────────────────────────

    public function testHiddenRendersEmpty(): void
    {
        $b = StatusBar::new()->left('x')->width(10)->hidden();
        $this->assertFalse($b->isVisible());
        $this->assertSame('', $b->render());
    }

    public function testVisibleTrueRenders(): void
    {
        $b = StatusBar::new()->left('x')->hidden()->visible();
        $this->assertTrue($b->isVisible());
        $this->assertSame('x', $b->render());
    }

    public function testToStringMatchesRender(): void
    {
        $b = StatusBar::new()->left('hi')->width(4);
        $this->assertSame($b->render(), (string) $b);
    }

    // ─── Setters + accessors round-trip ─────────────────────────────────

    public function testSeparatorAccessor(): void
    {
        $this->assertSame(' :: ', StatusBar::new()->separator(' :: ')->getSeparator());
    }

    public function testWidthAccessor(): void
    {
        $this->assertSame(20, StatusBar::new()->width(20)->getWidth());
        $this->assertNull(StatusBar::new()->width(20)->width(null)->getWidth());
    }

    public function testStyleAccessor(): void
    {
        $style = Style::new()->bold();
        $this->assertSame($style, StatusBar::new()->style($style)->getStyle());
        $this->assertNull(StatusBar::new()->style($style)->style(null)->getStyle());
    }

    public function testCapsAccessor(): void
    {
        $this->assertSame(['<', '>'], StatusBar::new()->caps('<', '>')->getCaps());
    }

    public function testWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatusBar::new()->width(-1);
    }

    // ─── Immutability ───────────────────────────────────────────────────

    public function testSettersDoNotMutateSource(): void
    {
        $base = StatusBar::new()->left('a')->width(5);
        $derived = $base->left('z')->width(9)->separator('|')->caps('[', ']')->fillChar('.')->hidden();
        $this->assertNotSame($base, $derived);
        $this->assertSame('a', $base->leftSegments()[0]->text());
        $this->assertSame(5, $base->getWidth());
        $this->assertSame(' ', $base->getSeparator());
        $this->assertSame(['', ''], $base->getCaps());
        $this->assertSame(' ', $base->getFillChar());
        $this->assertTrue($base->isVisible());
    }
}
