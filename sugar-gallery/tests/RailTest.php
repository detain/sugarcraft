<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\Rail;

final class RailTest extends TestCase
{
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
}
