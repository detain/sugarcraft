<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\Rail;

final class RailTest extends TestCase
{
    protected function setUp(): void
    {
        // render() consults the T registry for the empty-state line; pin the
        // locale so the '(no items)' assertions below do not follow the
        // runner's LANG environment.
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::reset();
    }
    /** @return list<PosterCard> */
    private function cards(int $n): array
    {
        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = new PosterCard((string) $i, 'Card ' . $i);
        }

        return $cards;
    }

    public function testPerRowFitsCardsAtSpacing(): void
    {
        // (railWidth + spacing) / (cardWidth + spacing) = (50 + 2) / (10 + 2) = 4.
        self::assertSame(4, Rail::perRow(50, 10, 2));
        self::assertSame(1, Rail::perRow(2, 10, 2), 'always at least one');
    }

    public function testMoveCursorClampsAndScrollsIntoView(): void
    {
        $rail = new Rail('R', $this->cards(10));

        $rail = $rail->moveCursor(-1, 3);
        self::assertSame(0, $rail->cursor, 'cannot move before the first card');

        $rail = $rail->moveCursor(5, 3); // cursor 5, perRow 3 → scroll 3
        self::assertSame(5, $rail->cursor);
        self::assertSame(3, $rail->scroll);

        $rail = $rail->moveCursor(100, 3); // clamp to last
        self::assertSame(9, $rail->cursor);
    }

    public function testMoveCursorOnEmptyRailIsNoOp(): void
    {
        $rail = new Rail('R');
        self::assertSame($rail, $rail->moveCursor(1, 3));
    }

    public function testWithCardsClampsCursorAndScroll(): void
    {
        $rail = (new Rail('R', $this->cards(10)))->moveCursor(8, 3); // cursor 8
        $rail = $rail->withCards($this->cards(3)); // now only 3 cards

        self::assertSame(2, $rail->cursor);
        self::assertLessThanOrEqual(2, $rail->scroll);
    }

    public function testWithCardReplacesById(): void
    {
        $rail = new Rail('R', $this->cards(3));
        $loaded = (new PosterCard('1', 'Card 1'))->withPoster('IMG');
        $rail = $rail->withCard($loaded);

        self::assertTrue($rail->cards[1]->hasPoster());
        self::assertFalse($rail->cards[0]->hasPoster());
    }

    public function testFocusedCard(): void
    {
        $rail = (new Rail('R', $this->cards(5)))->moveCursor(2, 3);
        self::assertSame('2', $rail->focusedCard()?->id);
        self::assertNull((new Rail('R'))->focusedCard());
    }

    public function testRenderShowsTitleCountAndFocusGlyph(): void
    {
        $rail = new Rail('Movies', $this->cards(3));

        $focused = $rail->render(50, true, 10, 2);
        self::assertStringContainsString('● Movies', $focused);
        self::assertStringContainsString('(1/3)', $focused);

        $blurred = $rail->render(50, false, 10, 2);
        self::assertStringContainsString('○ Movies', $blurred);
    }

    public function testRenderEmptyRail(): void
    {
        $out = (new Rail('Empty'))->render(50, false, 10, 2);
        self::assertStringContainsString('(no items)', $out);
    }

    public function testIsEmpty(): void
    {
        self::assertTrue((new Rail('R'))->isEmpty());
        self::assertFalse((new Rail('R', $this->cards(1)))->isEmpty());
    }

    public function testWithCardForUnknownIdLeavesCardsUnchanged(): void
    {
        $rail = new Rail('R', $this->cards(2));
        $same = $rail->withCard((new PosterCard('99', 'Ghost'))->withPoster('IMG'));

        self::assertCount(2, $same->cards);
        self::assertFalse($same->cards[0]->hasPoster());
        self::assertFalse($same->cards[1]->hasPoster());
    }

    public function testRailWithLoadedPosterKeepsUniformRowWidth(): void
    {
        // A rail with an over-wide poster (16 W's at cardWidth 8) must render
        // rows that are uniformly 8 cells wide — the bug was that Rail::render()
        // fed un-normalised rows straight into joinHorizontalWithSpacing.
        $rail = new Rail('R', [
            (new PosterCard('0', 'A'))->withPoster("WWWWWWWWWWWWWWWW\nW"),
            new PosterCard('1', 'B'),
        ]);

        $out = $rail->render(50, false, 8, 3);
        $lines = explode("\n", $out);

        // Extract card body rows (skip the head "● R (1/2)" line at index 0).
        $cardLines = array_slice($lines, 1);
        $widths = array_map(static fn (string $l): int => \SugarCraft\Style\Layout::width($l), $cardLines);
        self::assertCount(1, array_unique($widths), 'all card body rows have the same width');
    }

    public function testRenderKeepsFocusedCardVisibleAfterStaleScroll(): void
    {
        // Build a rail, move cursor far right (scroll advances), then call
        // withCards() to create a stale scroll where cursor < scroll.
        $rail = new Rail('R', $this->cards(5));
        $rail = $rail->moveCursor(4, 3); // cursor=4, scroll=3
        $rail = $rail->withCards($this->cards(5)); // scroll is now potentially stale

        $out = $rail->render(50, true, 10, 3);
        self::assertStringContainsString('Card 4', $out, 'focused card (Card 4) is visible after stale scroll');
    }

    public function testWithCardsEmptyResetsCursor(): void
    {
        $rail = (new Rail('R', $this->cards(5)))->moveCursor(4, 3);
        $empty = $rail->withCards([]);

        self::assertSame(0, $empty->cursor);
        self::assertSame(0, $empty->scroll);
        self::assertTrue($empty->isEmpty());
    }

    public function testRenderUsesDefaultSpacing(): void
    {
        $rail = new Rail('R', $this->cards(2));

        $explicit = $rail->render(50, false, 10, 2, 2);
        $default = $rail->render(50, false, 10, 2); // spacing defaults to 2

        self::assertSame($explicit, $default, '5-arg render with default spacing matches explicit spacing=2');
    }

    public function testWithTitleReturnsRelabeledCopy(): void
    {
        $rail = new Rail('Old', $this->cards(3));
        $relabeled = $rail->withTitle('New');

        self::assertSame('New', $relabeled->title);
        self::assertSame('Old', $rail->title, 'original is unchanged');
        self::assertSame($rail->cursor, $relabeled->cursor);
        self::assertSame($rail->scroll, $relabeled->scroll);
        self::assertSame($rail->cards, $relabeled->cards);
    }

    public function testWithCursorClampsToLastCard(): void
    {
        $rail = new Rail('R', $this->cards(3));
        $moved = $rail->withCursor(99);

        self::assertSame(2, $moved->cursor, 'cursor clamped to last index');
    }

    public function testNewFactoryMatchesConstructor(): void
    {
        $byNew = Rail::new('R', $this->cards(2));
        $byNew2 = new Rail('R', $this->cards(2));

        self::assertSame($byNew->title, $byNew2->title);
        self::assertSame($byNew->cursor, $byNew2->cursor);
        self::assertSame($byNew->scroll, $byNew2->scroll);
        self::assertCount(2, $byNew->cards);
    }

    public function testWithCursorOnEmptyRailReturnsEmptyRail(): void
    {
        $rail = new Rail('R');
        $moved = $rail->withCursor(5);

        // On an empty rail, withCursor should return a rail with empty cards
        self::assertTrue($moved->isEmpty());
        self::assertSame(0, $moved->cursor);
        self::assertSame(0, $moved->scroll);
    }

    public function testPerRowEdgeCases(): void
    {
        // perRow should always return at least 1
        self::assertSame(1, Rail::perRow(1, 100, 2), 'always at least one card even if card is wider');
        self::assertSame(1, Rail::perRow(10, 10, 0), 'at least one even with zero spacing');
        self::assertSame(1, Rail::perRow(10, 10, -1), 'negative spacing treated as zero');

        // Exactly fitting
        self::assertSame(3, Rail::perRow(34, 10, 2), '(34+2)/(10+2) = 3');
    }

    public function testCardWidthFloorKeepsEveryRowInsideTheRail(): void
    {
        // sugar-gallery-005: cardWidth below 4 inflated perRow with the raw
        // value while each card re-clamped to 4 at paint — QA measured 88-cell
        // rows at railWidth=60, cardWidth=2 (fifteen 4-wide cards). One floor
        // now rules window and paint alike.
        $wide = (new Rail('R', $this->cards(20)))->render(60, false, 2, 1);
        foreach (array_slice(explode("\n", $wide), 1) as $line) {
            self::assertLessThanOrEqual(60, \SugarCraft\Style\Layout::width($line), 'floored window fits the rail');
        }

        // The row's cited edge: railWidth=27, cardWidth=3 — the unfloored
        // division promised five slots (28 painted cells); the floored one
        // fits four (22).
        $narrow = (new Rail('R', $this->cards(9)))->render(27, false, 3, 1);
        foreach (array_slice(explode("\n", $narrow), 1) as $line) {
            self::assertLessThanOrEqual(27, \SugarCraft\Style\Layout::width($line), 'every painted row ≤ railWidth');
        }
    }

    public function testDegenerateRailWidthAndSpacingClampOnceAtTheHead(): void
    {
        // sugar-gallery-021: the header clamped max(1, railWidth) while
        // perRow() ran the raw value — two disagreeing idioms inside one
        // method. The law is single-sourced now (render head + perRow mirror).
        self::assertSame(1, Rail::perRow(-5, 10, 2), 'a non-positive railWidth still yields one card');
        self::assertSame(15, Rail::perRow(60, 2, -3), 'cardWidth floors at 4 (intdiv(60, 4)); negative spacing floors at 0');
        self::assertSame(5, Rail::perRow(30, 6, -5), 'raw negative spacing used to tighten the divisor to 1 and promise 25 slots; floored to 0 it yields 5');

        $out = (new Rail('R', $this->cards(4)))->render(30, false, 6, 1, -5);
        // Spacing -5 clamps to 0: four 6-wide cards join to 24 cells, inside
        // the 30-cell rail, with no exceptions and no phantom fifth slot.
        $lines = explode("\n", $out);
        self::assertStringContainsString('○ R  (1/4)', $lines[0]);
        foreach (array_slice($lines, 1) as $line) {
            self::assertLessThanOrEqual(30, \SugarCraft\Style\Layout::width($line));
        }
    }

    public function testFocusedCardOnEmptyRail(): void
    {
        $rail = new Rail('R');
        self::assertNull($rail->focusedCard(), 'empty rail has no focused card');
    }

    public function testRenderWithCardsLargerThanPerRow(): void
    {
        // 10 cards with perRow=4 means 3 visible + scroll
        $rail = new Rail('R', $this->cards(10));
        $rail = $rail->moveCursor(5, 4); // cursor 5, scroll should adjust

        $out = $rail->render(50, true, 10, 2, 2);

        // Should contain all visible cards
        self::assertStringContainsString('Card 5', $out, 'focused card is visible');
        self::assertStringNotContainsString('Card 8', $out, 'card beyond visible window not rendered');
    }

    public function testMoveCursorNegativeDeltaClampsToZero(): void
    {
        $rail = new Rail('R', $this->cards(5));
        $moved = $rail->moveCursor(-10, 3);

        self::assertSame(0, $moved->cursor);
        self::assertSame(0, $moved->scroll);
    }

    public function testStoredGeometryDrivesTheMoveCursorWindow(): void
    {
        // sugar-gallery-001: with $perRow omitted, moveCursor() uses the
        // geometry stored by withGeometry() — and the window it scrolls into
        // agrees with the one render() paints at the same values.
        $rail = Rail::new('R', $this->cards(10))->withGeometry(50, 10, 2);

        $stored = $rail->moveCursor(6);
        $explicit = $rail->moveCursor(6, Rail::perRow(50, 10, 2));

        self::assertSame(6, $stored->cursor);
        self::assertSame(3, $stored->scroll);
        self::assertSame($explicit->scroll, $stored->scroll, 'stored geometry agrees with the explicit derivation');

        $frame = $stored->render(50, true, 10, 2, 2);
        self::assertStringContainsString('Card 3', $frame, 'the render window starts at the stored-geometry scroll');
        self::assertStringContainsString('Card 6', $frame, 'the focused card is inside the window');
        self::assertStringNotContainsString('Card 2', $frame, 'one card above the window stays unpainted');
    }

    public function testExplicitPerRowStillOverridesStoredGeometry(): void
    {
        $rail = Rail::new('R', $this->cards(10))->withGeometry(50, 10, 2); // stored perRow is 4

        $override = $rail->moveCursor(6, 2); // explicit 2-wide window scrolls further

        self::assertSame(5, $override->scroll, 'the explicit argument wins over stored geometry');
    }

    public function testMoveCursorWithoutGeometryOrPerRowFailsLoud(): void
    {
        $rail = new Rail('R', $this->cards(3));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a $perRow argument or stored geometry');
        $rail->moveCursor(1);
    }

    public function testWithCardsWithSingleCard(): void
    {
        $rail = new Rail('R', $this->cards(1));
        $single = $rail->withCards([new PosterCard('0', 'Only')]);

        self::assertSame(1, count($single->cards));
        self::assertSame('Only', $single->cards[0]->title);
        self::assertSame(0, $single->cursor, 'cursor reset to 0');
    }

    public function testWithCardNotFoundLeavesRailUnchanged(): void
    {
        $rail = new Rail('R', $this->cards(2));
        $unchanged = $rail->withCard(new PosterCard('99', 'Ghost'));

        self::assertCount(2, $unchanged->cards);
        self::assertSame('Card 0', $unchanged->cards[0]->title);
        self::assertSame('Card 1', $unchanged->cards[1]->title);
    }

    public function testTheConstructorIsHandSpelledExactlyTwiceInTheSource(): void
    {
        // M9 mirror of PosterCardCarriersTest: new() mirrors the constructor
        // and carrying() is the one internal rebuild. A third CODE-site
        // `new self(` is a re-introduced hand-rolled carrier.
        $source = file_get_contents((new \ReflectionClass(Rail::class))->getFileName());
        self::assertIsString($source);
        $code = implode("\n", array_filter(
            explode("\n", $source),
            static fn (string $line): bool => !preg_match('/^\s*(\*|\/\/)/', $line),
        ));
        self::assertSame(2, substr_count($code, 'new self('), 'Rail gained a hand-rolled carrier reconstruction');
    }

    public function testCarryingRebuildsEveryFieldAndRefusesUnknownOnes(): void
    {
        $rail = (new Rail('Old', $this->cards(4)))->moveCursor(3, 2);
        $carrying = new \ReflectionMethod(Rail::class, 'carrying');

        $relabeled = $carrying->invoke($rail, ['title' => 'Sentinel']);
        self::assertSame('Sentinel', $relabeled->title);
        self::assertSame($rail->cards, $relabeled->cards);
        self::assertSame($rail->cursor, $relabeled->cursor);
        self::assertSame($rail->scroll, $relabeled->scroll);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a constructor field');
        $carrying->invoke($rail, ['scrollRow' => 1]);
    }
}
