<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
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

    public function testWideCjkTitleIsMeasuredInCellsNotChars(): void
    {
        // 日本語 = 3 CJK chars = 6 visible cells; fits in width-2 → no ellipsis,
        // and the row is exactly `width` visible cells (the old mb_strlen path
        // would have mis-measured the wide glyphs).
        $titleLine = explode("\n", (new PosterCard('1', '日本語'))->render(false, 10, 1))[1];

        self::assertSame(10, Width::of($titleLine), 'wide title row is exactly width cells');
        self::assertStringContainsString('日本語', $titleLine);
        self::assertStringNotContainsString('…', $titleLine);
    }

    public function testStyledTitleRendersAtTheCorrectVisibleWidth(): void
    {
        // Visible title is "Highlight" (9 cells) but carries inline ANSI.
        $card = (new PosterCard('1', 'Highlight'))->withStyledTitle("\e[1mHi\e[0mghlight");
        $titleLine = explode("\n", $card->render(false, 14, 1))[1];

        // The row is exactly `width` VISIBLE cells despite the escape bytes …
        self::assertSame(14, Width::of($titleLine), 'styled title row is exactly width cells');
        // … and the escape sequences survive intact (not split by the width math).
        self::assertStringContainsString("\e[1mHi\e[0m", $titleLine);
    }

    public function testStyledTitleTruncatesAnsiAwareKeepingEscapes(): void
    {
        // 26 visible cells, with a styled run at the front; width-2 = 8 cells fit.
        $card = (new PosterCard('1', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'))
            ->withStyledTitle("\e[1mABCDE\e[0mFGHIJKLMNOPQRSTUVWXYZ");
        $titleLine = explode("\n", $card->render(false, 10, 1))[1];

        self::assertSame(10, Width::of($titleLine), 'truncated to the cell width, ANSI not counted');
        self::assertStringContainsString("\e[1m", $titleLine, 'the leading style survives the truncation');
    }

    public function testWithStyledTitleKeepsThePlainTitleForIdentity(): void
    {
        $card = (new PosterCard('id-1', 'Plain'))->withStyledTitle("\e[1mP\e[0mlain");

        self::assertSame('Plain', $card->title, 'plain title retained for identity/sort');
        self::assertSame("\e[1mP\e[0mlain", $card->styledTitle);
    }

    public function testStyledTitleSurvivesWithPosterAndWithProgress(): void
    {
        $card = (new PosterCard('1', 'X'))
            ->withStyledTitle("\e[1mX\e[0m")
            ->withPoster("AAA")
            ->withProgress(0.5);

        self::assertSame("\e[1mX\e[0m", $card->styledTitle, 'styled title threads through withPoster/withProgress');
        self::assertStringContainsString("\e[1mX\e[0m", $card->render(false, 8, 1));
    }

    public function testImageModeRendersAMarkerBlockReservingThePosterArea(): void
    {
        $card = (new PosterCard('1', 'Movie'))->withImage('SIXELBYTES', 7);
        $lines = explode("\n", $card->render(false, 14, 9));

        // 9 reserved poster rows + 1 title row.
        self::assertCount(10, $lines);
        // Top-left cell is the overlay marker for id 7; the row is full width.
        self::assertStringContainsString(\SugarCraft\Core\ImageOverlay::marker(7), $lines[0]);
        self::assertSame(14, Width::string($lines[0]));
        // Remaining poster rows are blank (no inline ░ placeholder, no escapes).
        self::assertSame(str_repeat(' ', 14), $lines[5]);
        // hasPoster() is true so the loader won't re-request it.
        self::assertTrue($card->hasPoster());
        self::assertSame('SIXELBYTES', $card->posterImage);
        self::assertSame(7, $card->imageId);
    }

    public function testImageModeMarkerResolvesToAPaintInstruction(): void
    {
        $card = (new PosterCard('1', 'Movie'))->withImage('BYTES', 3);
        $images = [3 => new \SugarCraft\Core\ImagePlacement('BYTES', 14, 9)];
        [$body, $paints] = \SugarCraft\Core\ImageOverlay::resolve($card->render(false, 14, 9), $images);

        self::assertCount(1, $paints);
        self::assertSame('BYTES', $paints[0]['bytes']);
        self::assertStringNotContainsString(\SugarCraft\Core\ImageOverlay::marker(3), $body, 'marker blanked');
    }

    public function testWithImageThreadsThroughTitleAndProgress(): void
    {
        $card = (new PosterCard('1', 'X'))
            ->withImage('B', 1)
            ->withProgress(0.5)
            ->withStyledTitle("\e[1mX\e[0m");

        self::assertSame('B', $card->posterImage);
        self::assertSame(1, $card->imageId);
        self::assertSame(0.5, $card->progress);
        self::assertSame("\e[1mX\e[0m", $card->styledTitle);
    }

    public function testCrlfJoinedPosterLeavesNoStrayCarriageReturn(): void
    {
        // A poster encoded with CRLF (e.g. a stale cache entry from before an
        // upstream renderer fix) must not leak a "\r" into the stitched output —
        // an embedded carriage return collapses the whole rail to one line.
        $card = (new PosterCard('1', 'X'))->withPoster("AAA\r\nBBB\r\nCCC");
        $out = $card->render(false, 8, 3);

        self::assertStringNotContainsString("\r", $out, 'no stray carriage return survives');
        self::assertCount(4, explode("\n", $out), '3 poster rows + 1 title row');
        foreach (['AAA', 'BBB', 'CCC'] as $row) {
            self::assertStringContainsString($row, $out);
        }
    }

    public function testLoneCrJoinedPosterIsSplitIntoRows(): void
    {
        $card = (new PosterCard('1', 'X'))->withPoster("AAA\rBBB");
        $lines = explode("\n", $card->render(false, 8, 2));

        self::assertStringContainsString('AAA', $lines[0]);
        self::assertStringContainsString('BBB', $lines[1]);
    }

    public function testPosterIsPaddedToReservedHeight(): void
    {
        // A poster shorter than the reserved height is padded with blank rows so
        // the tile still occupies posterHeight rows and cards stay aligned.
        $lines = explode("\n", (new PosterCard('1', 'X'))->withPoster('ONLY')->render(false, 9, 4));

        self::assertCount(5, $lines, '4 poster rows (1 real + 3 padded) + 1 title');
        self::assertStringContainsString('ONLY', $lines[0]);
        self::assertSame(9, Layout::width($lines[2]), 'padded rows are exactly width cells');
    }

    public function testPosterTallerThanReservedHeightIsCropped(): void
    {
        $poster = implode("\n", ['R0', 'R1', 'R2', 'R3', 'R4']);
        $lines = explode("\n", (new PosterCard('1', 'X'))->withPoster($poster)->render(false, 9, 3));

        self::assertCount(4, $lines, '3 poster rows + 1 title');
        self::assertStringContainsString('R2', $lines[2]);
        self::assertStringNotContainsString('R3', $lines[2], 'overflow rows are dropped');
    }

    public function testEveryPosterRowIsExactlyWidthCells(): void
    {
        // A poster with one over-wide row (16 cells) and one under-wide row (2
        // cells) must be normalised to exactly width=8 cells each.
        $card = (new PosterCard('1', 'Hi'))->withPoster("WWWWWWWWWWWWWWWW\nWW");
        $lines = explode("\n", $card->render(false, 8, 3));

        // 3 poster rows + 1 title = 4 total.
        self::assertCount(4, $lines);
        foreach (array_slice($lines, 0, 3) as $line) {
            self::assertSame(8, Layout::width($line), 'every poster row is exactly 8 cells');
        }
    }

    public function testOverWidePosterRowIsTruncatedNotWidened(): void
    {
        // An over-wide row must be CLAMPED to width, not extended.
        $card = (new PosterCard('1', 'X'))->withPoster("TTTTTTTTTTTTTTTT"); // 16 T's at width 8
        $line = explode("\n", $card->render(false, 8, 1))[0];

        self::assertLessThanOrEqual(8, Layout::width($line), 'over-wide row is clamped to width');
    }

    public function testPlainTitleStripsControlBytes(): void
    {
        // Plain DB-sourced titles must not leak C0 controls into the terminal.
        // ESC, Bell, and clear-screen sequences must all be stripped.
        $titleLine = explode("\n", (new PosterCard('1', "Movie\e[2J\x07"))->render(false, 10, 1))[1];

        self::assertStringContainsString('Movie', $titleLine);
        self::assertStringNotContainsString("\e", $titleLine, 'ANSI escapes stripped from plain title');
        self::assertStringNotContainsString("\x07", $titleLine, 'bell byte stripped from plain title');
    }

    public function testNewFactoryMatchesConstructor(): void
    {
        $byNew = PosterCard::new('id1', 'Title');
        $byNew2 = new PosterCard('id1', 'Title');

        self::assertSame($byNew->id, $byNew2->id);
        self::assertSame($byNew->title, $byNew2->title);
        self::assertSame($byNew->poster, $byNew2->poster);
        self::assertSame($byNew->progress, $byNew2->progress);
    }
}
