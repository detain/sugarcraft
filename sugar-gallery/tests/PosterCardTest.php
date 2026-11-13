<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Width;
use SugarCraft\Gallery\AnsiGuard;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Style\Layout;

final class PosterCardTest extends TestCase
{
    protected function setUp(): void
    {
        // The failed-cell body resolves card.failed through the T registry;
        // pin the locale so the '(unavailable)' assertions do not follow the
        // runner's LANG environment.
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::reset();
    }

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

        // M5 (gallery review, 2026-11): the clamped ends now spell themselves
        // out — the label rides inside the row, so the saturated run shrinks
        // to `width - label` (7 cells for ' (100%)', 5 for ' (0%)'). Exact
        // shapes pinned; old pins demanded the full 10-glyph runs digits
        // replace.
        self::assertSame('▓▓▓ (100%)', explode("\n", $full)[2]);
        self::assertSame('░░░░░ (0%)', explode("\n", $empty)[2]);
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

    public function testStyledTitleOverflowAppendsTheEllipsisLikeAPlainTitle(): void
    {
        // sugar-gallery-032 (gate G-6 leg (c)): the plain path flags clipped
        // text with '…'; the styled path used to drop cells silently — the
        // asymmetry made a cut styled title read as a complete one.
        $styled = (new PosterCard('1', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'))
            ->withStyledTitle("\e[1mABCDE\e[0mFGHIJKLMNOPQRSTUVWXYZ");
        $plain = new PosterCard('1', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $styledLine = explode("\n", $styled->render(false, 10, 1))[1];
        $plainLine = explode("\n", $plain->render(false, 10, 1))[1];

        self::assertStringEndsWith('…', $styledLine, 'an overflowing styled title is marked, not silently shortened');
        self::assertSame(10, Width::string($styledLine), 'the ellipsis rides the budget, it never overflows the cell');
        self::assertStringEndsWith('…', $plainLine, 'the plain path keeps its mark (parity witness)');
        self::assertSame(
            substr_count($plainLine, '…'),
            substr_count($styledLine, '…'),
            'both branches clip with exactly one ellipsis'
        );

        $fitted = (new PosterCard('1', 'Short'))->withStyledTitle("\e[1mShort\e[0m");
        self::assertStringNotContainsString(
            '…',
            explode("\n", $fitted->render(false, 10, 1))[1],
            'a styled title that fits is never marked'
        );
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

    public function testFitCacheRenderIsByteIdenticalAcrossClears(): void
    {
        $card = (new PosterCard('1', 'Movie', null, 0.5))->withPoster("AAA\nBBB");

        $cached = $card->render(true, 10, 2);
        PosterCard::clearFitCache();          // force the next render to re-fit cold
        $cold = $card->render(true, 10, 2);

        self::assertSame($cached, $cold, 'cached and freshly-fitted renders are byte-identical');
    }

    public function testFitCacheFitsTheBodyOncePerGeometryAcrossRenders(): void
    {
        PosterCard::clearFitCache();
        $card = (new PosterCard('1', 'X'))->withPoster("AAA\nBBB\nCCC");

        // Two renders differing only in $focused reuse the same fitted body — the
        // poster rows do not depend on the cursor marker, so nothing re-fits.
        $card->render(false, 8, 3);
        $card->render(true, 8, 3);

        self::assertSame(1, PosterCard::clearFitCache(), 'the fitted body is memoized once despite two renders');
        self::assertSame(0, PosterCard::clearFitCache(), 'the memo is now empty');
    }

    public function testFitCacheKeepsDistinctGeometriesApart(): void
    {
        PosterCard::clearFitCache();
        $card = (new PosterCard('1', 'X'))->withPoster("AAA\nBBB");

        $card->render(false, 8, 2);
        $card->render(false, 10, 2); // a different width is a distinct fitted body

        self::assertSame(2, PosterCard::clearFitCache(), 'each geometry memoizes its own body');
    }

    public function testProgressBarAtExactBounds(): void
    {
        // Progress at exactly 0.0 should produce an empty bar
        // posterHeight=1 gives 1 poster row + 1 title row + 1 progress row = 3 lines total
        $zero = (new PosterCard('1', 'X', null, 0.0))->render(false, 10, 1);
        $zeroLines = explode("\n", $zero);
        self::assertCount(3, $zeroLines, '3 lines: poster + title + progress');
        $zeroProgressLine = $zeroLines[2]; // progress is the 3rd line
        // M5: the label now lives inside the row — 5 bar cells + ' (0%)'.
        self::assertSame('░░░░░ (0%)', $zeroProgressLine, 'progress 0.0 produces full empty bar with its label');

        // Progress at exactly 1.0 should produce a full bar
        $full = (new PosterCard('1', 'X', null, 1.0))->render(false, 10, 1);
        $fullLines = explode("\n", $full);
        $fullProgressLine = $fullLines[2];
        // ' (100%)' is seven cells, so the saturated run shrinks to 3.
        self::assertSame('▓▓▓ (100%)', $fullProgressLine, 'progress 1.0 produces full filled bar with its label');

        // Progress in the middle
        $mid = (new PosterCard('1', 'X', null, 0.5))->render(false, 10, 1);
        $midLines = explode("\n", $mid);
        $midProgressLine = $midLines[2];
        self::assertSame('▓▓░░ (50%)', $midProgressLine, 'the mid bar splits the reserved run evenly');
        self::assertSame(10, Layout::width($midProgressLine), 'mid-progress bar is exactly 10 cells wide');
    }

    public function testTheProgressBarDropsItsLabelWhenTheRowIsTooNarrow(): void
    {
        // Floor width: ' (50%)' would leave no bar at all, so the label bows
        // out and the full-width glyph run (the pre-M5 shape) renders.
        $lines = explode("\n", (new PosterCard('1', 'X', null, 0.5))->render(false, 4, 1));

        self::assertSame('▓▓░░', $lines[2], 'narrow rows keep the whole width for the bar');
    }

    public function testStripC0RemovesVariousControlBytes(): void
    {
        // Test that various C0 control characters are stripped from plain titles
        // beyond just ESC (\x1B) and Bell (\x07) which are tested elsewhere
        $titleWithC0 = "Movie\x00\x01\x02\x03\x04\x05\x06\x08\x0B\x0C\x0E\x0F"
            . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1C\x1D\x1E\x1F\x7F";
        $card = new PosterCard('1', $titleWithC0);
        $out = $card->render(false, 20, 1);
        $titleLine = explode("\n", $out)[1];

        // All C0 controls should be stripped, leaving only "Movie"
        self::assertStringContainsString('Movie', $titleLine);
        self::assertStringNotContainsString("\x00", $titleLine, 'NUL stripped');
        self::assertStringNotContainsString("\x01", $titleLine, 'SOH stripped');
        self::assertStringNotContainsString("\x7F", $titleLine, 'DEL stripped');
    }

    public function testHasPosterWhenBothPosterAndPosterImageAreNull(): void
    {
        $card = new PosterCard('1', 'Title');
        self::assertFalse($card->hasPoster(), 'hasPoster is false when both poster and posterImage are null');
    }

    public function testHasPosterWhenOnlyPosterImageIsSet(): void
    {
        $card = (new PosterCard('1', 'Title'))->withImage('bytes', 1);
        self::assertTrue($card->hasPoster(), 'hasPoster is true when only posterImage is set');
    }

    public function testProgressBarWidthNormalization(): void
    {
        // At width 10, progress 0.3 should give 3 filled cells
        $card = (new PosterCard('1', 'X', null, 0.3))->withPoster("poster");
        $lines = explode("\n", $card->render(false, 10, 3));

        // Count filled and empty cells in the progress bar (last line)
        $progressLine = $lines[count($lines) - 1];
        $filled = substr_count($progressLine, '▓');
        $empty = substr_count($progressLine, '░');

        // M5: the '(30%)' label takes 6 of the 10 cells, so the ratio runs on
        // the 4 remaining bar cells — 1 filled, 3 empty, label beside them.
        self::assertSame(4, $filled + $empty, 'the bar run shrinks to leave room for the label');
        self::assertSame(1, $filled, 'progress 0.3 × bar-width 4 = 1 filled cell');
        self::assertSame(3, $empty, '4 - 1 = 3 empty cells');
        self::assertSame('▓░░░ (30%)', $progressLine, 'the spelled-out share is exact');
    }

    // ---- the styled-title trust boundary --------------------------------

    public function testWithStyledTitleIsStillVerbatimByDefault(): void
    {
        // Backwards compatibility: the guard is opt-in, so an existing caller that
        // passes raw bytes gets exactly what it always got — the payload is stored
        // and echoed, NOT sanitised. This is the documented trust boundary.
        $hostile = "\e[1mHi\e[0m there\e[2J";
        $card = (new PosterCard('1', 'Hi there'))->withStyledTitle($hostile);

        self::assertSame($hostile, $card->styledTitle, 'default path stores the bytes untouched');
        self::assertStringContainsString("\e[2J", $card->render(false, 20, 1), 'and renders them untouched');
    }

    public function testAssertSafeOptionAcceptsStylingProducedByTheCaller(): void
    {
        $styled = "\e[1mHi\e[0mghlight";
        $card = (new PosterCard('1', 'Highlight'))->withStyledTitle($styled, assertSafe: true);

        self::assertSame($styled, $card->styledTitle);
        $line = explode("\n", $card->render(false, 14, 1))[1];
        self::assertSame(14, Width::of($line), 'the styled title still fills its row');
        // render() pads every row unconditionally, so the width alone says nothing
        // about the title surviving the guard and the ANSI-aware truncate.
        self::assertStringContainsString(
            'Highlight',
            AnsiGuard::stripControls($line),
            'the guard must keep the word and lose only the styling',
        );
    }

    public function testAnOverlayImageTakesPrecedenceOverAnInlinePosterOnTheSameCard(): void
    {
        // The constructor documents the two fills as one-or-the-other; this pins
        // what actually happens if a caller holds both, so the doc cannot rot.
        $card = PosterCard::new('1', 'Both')
            ->withPoster("\x1b[38;2;1;2;3m▀▀▀▀\x1b[0m")
            ->withImage("\x1bP0;1;q1#0\x9c", 7);

        $frame = $card->render(false, 10, 3);

        self::assertStringContainsString(ImageOverlay::marker(7), $frame, 'the overlay is what the cell reserves');
        self::assertStringNotContainsString('▀', $frame, 'the inline bytes are not painted alongside it');
    }

    public function testAssertSafeOptionRejectsAnythingThatIsNotStyling(): void
    {
        $card = new PosterCard('1', 'Highlight');

        // A cursor move, an erase, an OSC hyperlink … all throw instead of being
        // written into a cell the runtime will echo verbatim.
        foreach (["\e[2Ahi", "hi\e[2J", "\e]8;;https://evil\x07click", "ring\x07", "a\nb"] as $hostile) {
            $thrown = null;
            try {
                $card->withStyledTitle($hostile, assertSafe: true);
            } catch (InvalidArgumentException $e) {
                $thrown = $e;
            }

            self::assertNotNull($thrown, 'the guard must reject ' . json_encode($hostile));
            self::assertNull($card->styledTitle, 'a rejected title leaves the receiver untouched (immutable builder)');
        }
    }

    public function testWithSafeStyledTitleKeepsStyleAndDropsPayload(): void
    {
        $card = (new PosterCard('1', 'Blade Runner'))
            ->withSafeStyledTitle("\e[1mBlade" . "\e[2J" . "\e[0m Runner");

        self::assertSame("\e[1mBlade\e[0m Runner", $card->styledTitle, 'coerced, not refused: the styling survives');
        self::assertStringNotContainsString("\e[2J", $card->render(false, 20, 1));
        self::assertSame(20, Width::of(explode("\n", $card->render(false, 20, 1))[1]));
    }

    public function testWithSafeStyledTitleFallsBackToThePlainTitleWhenNothingSurvives(): void
    {
        $card = new PosterCard('1', 'Plain Title');

        // Sanitising to empty would render a blank row and lose the title, so the
        // builder declines the change (identity) and the plain — sanitised — path wins.
        self::assertSame($card, $card->withSafeStyledTitle("\e[2J"));
        self::assertNull($card->styledTitle);
        self::assertStringContainsString('Plain Title', $card->render(false, 20, 1));
    }

    public function testWithSafeStyledTitleFallsBackWhenOnlyInvisibleStylingSurvives(): void
    {
        $card = new PosterCard('1', 'Plain Title');

        // A payload-only input sanitises to a lone colour sequence: not the empty
        // string, yet a terminal shows nothing for it. Accepting it would blank the
        // title row and throw away the plain title the fallback exists to protect.
        self::assertSame($card, $card->withSafeStyledTitle("\e[31m\e[2J"), 'styling with no text left is nothing');
        self::assertSame($card, $card->withSafeStyledTitle("\e[31m"));
        self::assertSame($card, $card->withSafeStyledTitle("\e[41m \e[0m"), 'a row of blank space says nothing either');
        self::assertNull($card->styledTitle);
        self::assertStringContainsString('Plain Title', $card->render(false, 20, 1));

        // Styling that does carry text still wins, colour and all — including text
        // the strip cannot mistake for a control: a digit, a wide glyph, an emoji.
        $styled = $card->withSafeStyledTitle("\e[31mNeon\e[2J");
        self::assertNotSame($card, $styled);
        self::assertSame("\e[31mNeon", $styled->styledTitle);
        self::assertStringContainsString('Neon', $styled->render(false, 20, 1));

        foreach (["\e[31m日\e[0m", "\e[31m7\e[0m", "\e[31m🎬\e[0m"] as $carries) {
            $kept = $card->withSafeStyledTitle($carries);
            self::assertNotSame($card, $kept, $carries . ' carries a glyph the plain title does not');
            self::assertSame($carries, $kept->styledTitle);
        }
    }

    public function testWithSafeStyledTitleTreatsANonBreakingSpaceAsTextNotEmpty(): void
    {
        $card = new PosterCard('1', 'Plain Title');

        // The blankness rule is trim(), and trim() does not know U+00A0. A
        // non-breaking space is TEXT to AnsiGuard — it survives the guard and the
        // stripper untouched — so styling wrapped around it is a fill the caller
        // asked for, not an empty row. Pinned so the boundary stays deliberate: if
        // this ever flips, the docblock on withSafeStyledTitle() must flip with it.
        $nbsp = "\e[41m\u{a0}\e[0m";
        $kept = $card->withSafeStyledTitle($nbsp);

        self::assertNotSame($card, $kept, 'an NBSP is a character, not whitespace');
        self::assertSame($nbsp, $kept->styledTitle);
        self::assertStringContainsString("\u{a0}", AnsiGuard::stripControls($kept->styledTitle));

        // Contrast the ASCII space, which the same rule calls blank.
        self::assertSame($card, $card->withSafeStyledTitle("\e[41m \e[0m"));
    }

    public function testAnOverlayImageWithoutAnIdIsNotAFilledCell(): void
    {
        // withImage() cannot build this state (it takes an int), but the constructor
        // can — and a blob with no marker id paints nothing, so it must not read as
        // filled or the cell parks as a permanent skeleton outside the fill policy.
        $card = PosterCard::new('1', 'Orphan', 'https://cdn.example/1.png', posterImage: 'bytes');

        self::assertFalse($card->hasPoster(), 'an overlay with no id paints no marker');
        self::assertStringContainsString('░', $card->render(false, 12, 3));
        self::assertTrue($card->withImage('bytes', 3)->hasPoster(), 'the same blob with an id is a fill');
    }

    public function testAssertSafeAnsiIsTheCardLevelEntryWayIntoTheGuard(): void
    {
        self::assertSame("\e[31mX\e[0m", PosterCard::assertSafeAnsi("\e[31mX\e[0m"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsafe escape sequence at offset \d+/');
        PosterCard::assertSafeAnsi("\e[H\e[2J");
    }

    public function testGuardedStyledTitleThreadsThroughTheOtherBuilders(): void
    {
        $card = (new PosterCard('1', 'X', 'https://cdn/y.png'))
            ->withStyledTitle("\e[1mX\e[0m", assertSafe: true)
            ->withPoster('poster')
            ->withProgress(0.5);

        self::assertSame("\e[1mX\e[0m", $card->styledTitle, 'the guard option does not disturb the immutable chain');
        self::assertSame('https://cdn/y.png', $card->posterUrl);
        self::assertSame(0.5, $card->progress);
    }

    public function testPlainTitleWithInvalidUtf8StillLosesItsControlBytes(): void
    {
        // The plain path is the documented safe fallback, so it must not fail open:
        // the old /u-based strip turned into a no-op the moment any byte was not
        // valid UTF-8, and the escape reached the frame with the rest of the title.
        $frame = (new PosterCard('1', "\xff\x1b[2J\x07Blade"))->render(false, 40, 3);

        self::assertStringNotContainsString("\x1b[2J", $frame, 'erase sequence must not survive an opaque byte');
        self::assertStringNotContainsString("\x07", $frame);
        self::assertStringContainsString('Blade', $frame, 'the text the user came for still renders');
    }

    public function testPlainTitleLosesC1ControlsInEitherWireForm(): void
    {
        // 8-bit C1 needs no ESC to act on a terminal, and a UTF-8 re-encoding
        // (U+009B) decodes to the same control — neither may reach the frame.
        foreach (["\x9b2J", "\xc2\x9b2J", "\xc2\x9d8;;https://evil\xc2\x9c"] as $i => $hostile) {
            $frame = (new PosterCard('1', 'Blade' . $hostile . ' Runner'))->render(false, 40, 3);

            self::assertStringNotContainsString("\x9b", $frame, 'case ' . $i . ' raw C1 byte');
            self::assertStringNotContainsString("\xc2\x9b", $frame, 'case ' . $i . ' encoded C1');
            self::assertStringNotContainsString("\xc2\x9d", $frame, 'case ' . $i . ' encoded OSC');
            self::assertStringContainsString('Blade', $frame);
            self::assertStringContainsString('Runner', $frame);
        }
    }

    public function testPlainTitleKeepsWideUtf8AndDropsStrayStyling(): void
    {
        // Stripping the C1 band must not cost the world its non-Latin titles: the
        // bytes in 0x80–0x9F are ordinary continuation bytes inside a real
        // sequence, which the scanner consumes whole.
        $frame = (new PosterCard('1', "\e[1m因果の物語 🎬 Süß"))->render(false, 40, 3);

        self::assertStringContainsString('因果の物語', $frame);
        self::assertStringContainsString('Süß', $frame);
        self::assertStringNotContainsString("\x1b", $frame, 'a plain title carries no styling');
    }

    // ---- the failed-fetch state (M4) -------------------------------------

    public function testAFailedCellRendersUnavailableNotTheLoadingSkeleton(): void
    {
        // Art carried ALONGSIDE the failure must not count as filled — this
        // is the only combination where the hasPoster() failed short-circuit
        // is observable (an art-less failed card reads unfilled anyway).
        $card = (new PosterCard('1', 'Blade', 'https://cdn/1.png'))
            ->withPoster("AAA\nBBB")
            ->withFailed();

        self::assertTrue($card->isFailed());
        self::assertFalse($card->hasPoster(), 'an unavailable cell is not art — it stays re-queueable');

        $lines = explode("\n", $card->render(false, 20, 3));
        self::assertSame(str_pad('(unavailable)', 20), $lines[0], 'the notice heads the body, padded to the row');
        self::assertSame(str_repeat('?', 20), $lines[1], "'?' fill is distinct from the '░' loading skeleton");
        self::assertSame(str_repeat('?', 20), $lines[2]);
        self::assertStringNotContainsString('░', $lines[0] . $lines[1] . $lines[2]);
        self::assertStringNotContainsString('AAA', $card->render(false, 20, 3), 'the carried art stays hidden while failed');
        foreach ($lines as $line) {
            self::assertSame(20, Width::string($line), 'the failed state upholds the width law');
        }
    }

    public function testTheFailedFlagOutlivesArtAttachedAlongsideItAndClearsOnDemand(): void
    {
        // A late async resolution that forgot the fetch had failed must not
        // repaint over the failure — withFailed(false) is the deliberate
        // retry-resolved transition.
        $card = (new PosterCard('1', 'X', 'https://cdn/1.png'))
            ->withFailed()
            ->withPoster("AAA\nBBB");
        self::assertStringContainsString('?', $card->render(false, 8, 2), 'failure wins while set');

        $recovered = $card->withFailed(false);
        self::assertFalse($recovered->isFailed());
        self::assertTrue($recovered->hasPoster(), 'the stored art counts again once the flag clears');
        $lines = explode("\n", $recovered->render(false, 8, 2));
        self::assertStringContainsString('AAA', $lines[0]);
        self::assertStringNotContainsString('?', $recovered->render(false, 8, 2));
    }

    public function testWithFailedCarriesEveryOtherFieldAndTheNoticeIsNotMemoized(): void
    {
        $card = (new PosterCard('1', 'X', 'https://cdn/1.png', 0.25, 'art', "\e[1mS", 'sixel', 5))
            ->withFailed();
        self::assertSame(
            ['1', 'X', 'https://cdn/1.png', 0.25, 'art', "\e[1mS", 'sixel', 5],
            [$card->id, $card->title, $card->posterUrl, $card->progress, $card->poster, $card->styledTitle, $card->posterImage, $card->imageId],
            'withFailed() rides the single carrier',
        );

        // The failed body reads the locale live (memo bypass): switching T's
        // locale between renders must change the notice — a cached frozen
        // translation is the bug this pin catches.
        $before = explode("\n", $card->render(false, 30, 1))[0];
        self::assertStringContainsString('(unavailable)', $before);

        $previous = T::locale();
        try {
            T::setLocale('de');
            $after = explode("\n", $card->render(false, 30, 1))[0];
            self::assertStringContainsString('(nicht verfügbar)', $after, 'the notice follows the live locale');
            self::assertNotSame($before, $after);
        } finally {
            T::setLocale($previous);
        }
    }

    public function testWithTitleReplacesOnlyThePlainTitle(): void
    {
        // sugar-gallery-010: withTitle was the one missing carrier — every
        // other field had a with* and a rename meant hand-spelling the
        // nine-argument constructor.
        $card = new PosterCard('1', 'X', 'https://cdn/1.png', 0.25, 'art', "\e[1mS", 'sixel', 5);
        $renamed = $card->withTitle('Dune');

        self::assertSame('Dune', $renamed->title);
        self::assertSame(
            ['1', 'https://cdn/1.png', 0.25, 'art', "\e[1mS", 'sixel', 5],
            [$renamed->id, $renamed->posterUrl, $renamed->progress, $renamed->poster, $renamed->styledTitle, $renamed->posterImage, $renamed->imageId],
            'withTitle() rides the single carrier',
        );
        self::assertSame('X', $card->title, 'the original is untouched (immutable)');
        self::assertNotSame($card, $renamed);
    }

    public function testFitMemoKeysRideTheIngressHashAndBranchesStayDisjoint(): void
    {
        // sugar-gallery-014: the fit memo hashed the WHOLE poster payload on
        // every bodyRows() call. The hash now rides the card from ingress —
        // the memo branches ('s|', 'i|', 'p|') must stay disjoint and
        // path-stable across that key change.
        $ref = new \ReflectionMethod(PosterCard::class, 'fitCacheRef');

        $skel = new PosterCard('sk', 'S');
        $artA = (new PosterCard('ar', 'A'))->withPoster('ZZZZ');
        $artB = (new PosterCard('ar', 'A'))->withPoster('AAAA');
        $img = (new PosterCard('im', 'I'))->withImage('bytes', 3);

        $skel->render(false, 8, 2);
        $artA->render(false, 8, 2);
        $artB->render(false, 8, 2);
        $img->render(false, 8, 2);

        $keys = array_keys($ref->invoke(null));

        self::assertContains('p|8|2|4|' . md5('ZZZZ'), $keys, 'poster branch key carries the ingress hash');
        self::assertContains('p|8|2|4|' . md5('AAAA'), $keys, 'different bytes miss each other — no stale art');
        self::assertContains('s|8|2', $keys, 'skeleton branch is dimension-only');
        self::assertContains('i|3|8|2', $keys, 'overlay branch keys the id, not the payload');

        // Same bytes through the plain constructor must hit the SAME memo
        // entry the carrier produced — the hash is path-stable, so ingress
        // never forks the cache.
        $countBefore = count($ref->invoke(null));
        (new PosterCard('zz', 'Z', poster: 'ZZZZ'))->render(false, 8, 2);
        self::assertCount($countBefore, $ref->invoke(null), 'ctor-built same-content card rides the p| key');

        $prop = new \ReflectionProperty(PosterCard::class, 'posterHash');
        self::assertSame(md5('ZZZZ'), $prop->getValue($artA), 'withPoster() recomputed the stored hash once');
        self::assertSame(md5('ZZZZ'), $prop->getValue($artA->withProgress(0.5)), 'a non-poster carrier rides it unchanged');
    }

    public function testWithProgressRefusesNonFiniteRatios(): void
    {
        // sugar-gallery-035: ±INF silently read as "100 %" downstream and NAN
        // even round()-ed into a full bar — a poisoned remote ratio must name
        // itself at admission instead of decorating the card.
        $card = new PosterCard('1', 'X');

        foreach ([['NAN', NAN], ['+INF', INF], ['-INF', -INF]] as [$label, $value]) {
            try {
                $card->withProgress($value);
                self::fail('withProgress(' . $label . ') must throw');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('refuses non-finite progress (' . $label . ')', $e->getMessage());
            }
        }
    }

    public function testWithProgressNullStaysTheDocumentedUnset(): void
    {
        $card = (new PosterCard('1', 'X', progress: 0.5))->withProgress(null);

        self::assertNull($card->progress);
        self::assertStringNotContainsString('%', $card->render(false, 20, 1), 'no progress row without a ratio');
    }

    /**
     * The README's cache-levers paragraph publishes the clear methods as the
     * sanctioned surface and states that both caps are PRIVATE constants whose
     * names fatal on external reference. This pin reddens if either const is
     * widened (the prose once claimed FIT_CACHE_CAP was public — it never was)
     * or if the 2048/oldest-half bound the paragraph quotes drifts.
     */
    public function testTheCacheCapsStayPrivateConstants(): void
    {
        foreach ([PosterCard::class => 'FIT_CACHE_CAP', PosterGrid::class => 'BOX_CACHE_CAP'] as $class => $name) {
            $constant = (new ReflectionClass($class))->getReflectionConstant($name);
            self::assertInstanceOf(ReflectionClassConstant::class, $constant, "{$class}::{$name} is declared");
            self::assertTrue($constant->isPrivate(), "{$class}::{$name} must stay private — the README publishes the clear methods, not the cap");
            self::assertSame(2048, $constant->getValue(), "{$class}::{$name} bounds the memo at 2048 entries");
        }
    }

    public function testTheFitMemoEvictsTheOldestHalfPastTheCap(): void
    {
        // sugar-gallery-008: the memo BOUND was pinned in shape (privacy, the
        // 2048 value) but never exercised — no test proved the oldest-half
        // slice fires, that survivors are still found by KEY (array_slice
        // with use_keys), or that a victim recomputes byte-identically.
        PosterCard::clearFitCache();
        $ref = new \ReflectionMethod(PosterCard::class, 'fitCacheRef');

        // Distinct overlay ids at a fixed small geometry give one memo key
        // per card with O(cell) per-frame cost — crossing the 2,048 cap
        // without the suite paying for 2,100 full-width renders.
        $early = [];
        for ($id = 1; $id <= 2100; $id++) {
            $frame = (new PosterCard('ev', 'Eviction driver'))->withImage('x', $id)->render(false, 4, 1);
            if ($id === 20 || $id === 2100) {
                $early[$id] = $frame;
            }
        }

        $cache = $ref->invoke(null);
        self::assertLessThanOrEqual(2048, count($cache), 'the memo never outruns its cap');
        self::assertGreaterThanOrEqual(1024, count($cache), 'the eviction keeps the young half, not everything');
        self::assertArrayHasKey('i|2100|4|1', $cache, 'a survivor stays addressable by its own key');
        self::assertArrayNotHasKey('i|20|4|1', $cache, 'the oldest half really left the building');

        $again = static fn (int $id): string => (new PosterCard('ev', 'Eviction driver'))->withImage('x', $id)->render(false, 4, 1);
        self::assertSame($early[2100], $again(2100), 'a surviving geometry re-renders byte-identical from the memo');
        self::assertSame($early[20], $again(20), 'an evicted geometry recomputes byte-identical');
    }

    public function testRenderMemoKeyNamesTheRenderBytesAndRefusesALocaleFrozenCell(): void
    {
        // SG-S15 (sugar-gallery-047): the seam the grid's box memo keys on.
        // Equal content + geometry + focus + title field + progress ⇒ equal
        // key (and a byte-identical render); any of those differing ⇒ a
        // different key; a failed cell ⇒ null (its notice is locale-resolved
        // at draw time and must never be frozen into a content-keyed memo).
        $card = (new PosterCard('1', 'Title'))->withPoster('AAAA')->withProgress(0.5);
        $sameBytes = (new PosterCard('7', 'Title'))->withPoster('AAAA')->withProgress(0.5);
        self::assertSame(
            $card->renderMemoKey(false, 12, 2),
            $sameBytes->renderMemoKey(false, 12, 2),
            'content keys on bytes, not on the id the render never emits',
        );
        self::assertSame($card->render(false, 12, 2), $sameBytes->render(false, 12, 2));

        $sameLenDifferentArt = $card->withPoster('AAAB');
        self::assertNotSame($card->renderMemoKey(false, 12, 2), $sameLenDifferentArt->renderMemoKey(false, 12, 2), 'art sensitivity is not length-only');

        $styled = (new PosterCard('1', 'ignored'))->withStyledTitle('Title');
        self::assertNotSame($card->renderMemoKey(false, 12, 2), $styled->renderMemoKey(false, 12, 2), 'the title-field discriminator keeps plain and styled bytes apart');

        self::assertNotSame($card->renderMemoKey(false, 12, 2), $card->renderMemoKey(true, 12, 2), 'focus rides the key');
        self::assertNotSame($card->renderMemoKey(false, 12, 2), $card->renderMemoKey(false, 12, 3), 'geometry rides the key');
        self::assertNotSame($card->renderMemoKey(false, 12, 2), $card->withProgress(0.6)->renderMemoKey(false, 12, 2), 'progress rides the key');
        self::assertNotSame($card->renderMemoKey(false, 12, 2), $card->withProgress(null)->renderMemoKey(false, 12, 2), 'and its absence changes the key shape');

        self::assertNull($card->withFailed()->renderMemoKey(false, 12, 2), 'a failed cell refuses to be content-keyed');

        self::assertSame($card->renderMemoKey(false, 2, 0), $card->renderMemoKey(false, 4, 1), 'the seam clamps exactly like render() does');
    }
}
