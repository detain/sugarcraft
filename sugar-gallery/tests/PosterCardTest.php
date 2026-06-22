<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Sprinkles\Layout;

final class PosterCardTest extends TestCase
{
    public function testPlaceholderRowsWhenNoPosterLoaded(): void
    {
        $card = new PosterCard('1', 'Title');
        $out = $card->render(false, 10, 3);
        $lines = explode("\n", $out);

        // 3 placeholder rows + 1 title row.
        self::assertCount(4, $lines);
        self::assertSame(str_repeat('░', 10), $lines[0]);
        self::assertStringContainsString('Title', $lines[3]);
    }

    public function testFocusMarkerOnlyWhenFocused(): void
    {
        $card = new PosterCard('1', 'Movie');

        self::assertStringContainsString('▸', $card->render(true, 12, 2));
        self::assertStringNotContainsString('▸', $card->render(false, 12, 2));
    }

    public function testLoadedPosterReplacesPlaceholder(): void
    {
        $card = (new PosterCard('1', 'X'))->withPoster("AAA\nBBB");

        self::assertTrue($card->hasPoster());
        $out = $card->render(false, 8, 2);
        self::assertStringContainsString('AAA', $out);
        self::assertStringContainsString('BBB', $out);
        self::assertStringNotContainsString('░', $out);
    }

    public function testProgressRowAppendedWhenSet(): void
    {
        $noProgress = new PosterCard('1', 'X');
        $withProgress = $noProgress->withProgress(0.5);

        self::assertCount(3, explode("\n", $noProgress->render(false, 10, 2)));
        self::assertCount(4, explode("\n", $withProgress->render(false, 10, 2)), 'progress adds a row');
    }

    public function testProgressBarClampsAndFills(): void
    {
        $full = (new PosterCard('1', 'X', null, 1.5))->render(false, 10, 1);
        $empty = (new PosterCard('1', 'X', null, -0.5))->render(false, 10, 1);

        self::assertStringContainsString(str_repeat('▓', 10), $full);
        self::assertStringContainsString(str_repeat('░', 10), explode("\n", $empty)[2]);
    }

    public function testTitleTruncatedToWidth(): void
    {
        $card = new PosterCard('1', 'An Extremely Long Movie Title');
        $titleLine = explode("\n", $card->render(false, 10, 1))[1];

        self::assertSame(10, Layout::width($titleLine), 'title row is exactly width cells');
        self::assertStringContainsString('…', $titleLine);
    }

    public function testEveryRowIsExactlyWidthCells(): void
    {
        $card = new PosterCard('1', 'Hi', null, 0.3);
        foreach (explode("\n", $card->render(true, 9, 2)) as $line) {
            self::assertSame(9, Layout::width($line));
        }
    }

    public function testWidthAndHeightAreClampedToMinimums(): void
    {
        $out = (new PosterCard('1', 'X'))->render(false, 1, 0);
        $lines = explode("\n", $out);

        self::assertGreaterThanOrEqual(4, Layout::width($lines[0]), 'width floored at 4');
        self::assertCount(2, $lines, 'posterHeight floored at 1 → 1 poster + 1 title');
    }
}
