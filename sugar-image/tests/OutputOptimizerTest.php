<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * A-01/A-14 pins for the streaming pen-delta optimizer.
 *
 * The emission law under test: bytes leave the optimizer only when a pen
 * channel actually changes (chafa-canvas-printer.c:139-215), blanks copy the
 * tracked foreground instead of arming one (chafa-symbol-renderer.c:862-886),
 * and every row closes with a scoped restore of the ENTRY style. A-14's
 * capacity bound rides here: every produced row must fit the documented
 * worst case — the shape that breaks it is the pre-A-01 per-cell `ESC[0m`,
 * which grows with the cell count instead of staying bounded per cell.
 */
final class OutputOptimizerTest extends TestCase
{
    public function testRepeatedForegroundIsEmittedOnce(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('a', OutputOptimizer::trueColor(1, 2, 3));
        $pen->cell('b', OutputOptimizer::trueColor(1, 2, 3));

        self::assertSame("\x1b[38;2;1;2;3mab\x1b[39m", $pen->finishRow(), 'the delta fires exactly once for a two-cell run');
    }

    public function testChangingOnlyForegroundTouchesOnlyForeground(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('a', OutputOptimizer::trueColor(1, 2, 3), OutputOptimizer::trueColor(4, 5, 6));
        $pen->cell('b', OutputOptimizer::trueColor(7, 8, 9), OutputOptimizer::trueColor(4, 5, 6));

        $row = $pen->finishRow();
        self::assertSame(
            "\x1b[38;2;1;2;3m\x1b[48;2;4;5;6ma\x1b[38;2;7;8;9mb\x1b[39;49m",
            $row,
            'only the changed channel may re-arm; the shared background stays silent',
        );
    }

    public function testBackgroundUsesTheRewrittenChannelCode(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('x', null, OutputOptimizer::trueColor(9, 8, 7));

        self::assertStringContainsString("\x1b[48;2;9;8;7m", $pen->finishRow(), 'normalized 38; parameters must land on the 48 background channel');
    }

    public function testIndexed256PenAlsoRewritesForBackground(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('x', null, OutputOptimizer::indexed256(196));

        self::assertStringContainsString("\x1b[48;5;196m", $pen->finishRow());
    }

    public function testDroppingToDefaultEmitsScopedResetOnce(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('a', OutputOptimizer::trueColor(1, 2, 3));
        $pen->cell('b', null);
        $pen->cell('c', null);

        $row = $pen->finishRow();
        self::assertSame("\x1b[38;2;1;2;3ma\x1b[39mbc", $row, 'the default pen is announced once, not once per default cell');
    }

    public function testBlankCopiesForegroundAndDropsBackground(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('a', OutputOptimizer::trueColor(1, 2, 3), OutputOptimizer::trueColor(4, 5, 6));
        $pen->blank();

        $row = $pen->finishRow();
        self::assertSame("\x1b[38;2;1;2;3m\x1b[48;2;4;5;6ma\x1b[49m \x1b[39m", $row, 'the blank drops the background where it lands (a space on colour must paint the entry bg) and keeps the tracked foreground (copy-fg)');
        self::assertStringNotContainsString("\x1b[39m\x1b[48", $row, 'no wasted fg drop may ride a blank');
    }

    public function testSpaceGlyphIgnoresForegroundRequest(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell(' ', OutputOptimizer::trueColor(1, 2, 3));

        self::assertNull($pen->foreground(), 'a space carries no ink; requesting a foreground for it must not arm the pen');
        self::assertSame(' ', $pen->finishRow());
    }

    public function testAttributeChannelsDeltaAndThrow(): void
    {
        $pen = new OutputOptimizer();
        $pen->attribute('blink', true);
        $pen->attribute('blink', true);
        $row = $pen->finishRow();

        self::assertSame("\x1b[5m\x1b[25m", $row, 'attribute on rides once; finishRow must switch it back off');

        $this->expectException(\InvalidArgumentException::class);
        $pen->attribute('nonsense', true);
    }

    public function testFinishRowIsSilentWhenPenAlreadyMatchesEntry(): void
    {
        $pen = new OutputOptimizer();
        self::assertSame('', $pen->finishRow());
        $pen->cell('a');
        self::assertSame('a', $pen->finishRow(), 'default-pen cells need no restore sequence at all');
    }

    public function testCustomEntryStyleIsRestoredNotDefaulted(): void
    {
        // Entry pens are carried in the same normalized "38;" spelling the
        // cells use; the background channel rewrites at emission.
        $pen = new OutputOptimizer('38;2;1;2;3', '38;2;4;5;6');
        $pen->cell('a', OutputOptimizer::trueColor(9, 9, 9), OutputOptimizer::trueColor(8, 8, 8));
        $row = $pen->finishRow();

        self::assertSame("\x1b[38;2;9;9;9m\x1b[48;2;8;8;8ma\x1b[38;2;1;2;3;48;2;4;5;6m", $row, "the caller's pens ride back in one combined scoped sequence");
        self::assertStringNotContainsString("\x1b[0m", $row);
        self::assertSame('38;2;1;2;3', $pen->foreground());
        self::assertSame('38;2;4;5;6', $pen->background());
    }

    public function testCombinedRestoreIsOneSequence(): void
    {
        $pen = new OutputOptimizer();
        $pen->cell('a', OutputOptimizer::trueColor(1, 1, 1), OutputOptimizer::trueColor(2, 2, 2));
        $pen->attribute('inverse', true);

        self::assertSame(
            "\x1b[38;2;1;1;1m\x1b[48;2;2;2;2ma\x1b[7m\x1b[27;39;49m",
            $pen->finishRow(),
            'one row end is one SGR sequence at most',
        );
    }

    public function testNegativeCapacityArgumentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutputOptimizer::rowByteCapacity(-1);
    }

    public function testCapacityBoundHoldsForWorstCaseRows(): void
    {
        $worstColour = OutputOptimizer::trueColor(235, 235, 235);
        $pen = new OutputOptimizer($worstColour, $worstColour);
        $cells = 64;
        $bound = OutputOptimizer::rowByteCapacity($cells);

        for ($i = 0; $i < $cells; $i++) {
            // Every cell alternates both pens and carries an attribute flip —
            // the maximum per-cell sequence pressure the optimizer can emit.
            $pen->cell('▀', $i % 2 === 0 ? $worstColour : OutputOptimizer::indexed256(231), $i % 2 === 0 ? OutputOptimizer::indexed256(99) : $worstColour);
            $pen->attribute('underline', $i % 2 === 0);
        }

        self::assertLessThanOrEqual($bound, strlen($pen->finishRow()), 'chafa-derived 3*seq+6B per cell must bound the worst produced row');
    }
}
