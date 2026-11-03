<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Render\SectionScanner;

/**
 * M3: push() consumed its buffer by re-slicing the remainder on every line,
 * making one large chunk quadratic in cost (measured: a 1.6 MB single push
 * took seconds while the same bytes line-by-line were instant). The scanner
 * now keeps a byte cursor and compacts only when the read head outweighs the
 * live tail. The cursor pins below are load-bearing: without the property
 * the re-slice shuffle is behaviourally invisible, only its cost betrays it.
 */
final class ScannerCursorTest extends TestCase
{
    private static function cursor(SectionScanner $scanner): int
    {
        $property = new \ReflectionProperty(SectionScanner::class, 'consumed');

        return (int) $property->getValue($scanner);
    }

    private static function liveBuffer(SectionScanner $scanner): string
    {
        $property = new \ReflectionProperty(SectionScanner::class, 'buffer');

        return (string) $property->getValue($scanner);
    }

    public function testTheCursorTracksReadLinesWithoutReSlicingThem(): void
    {
        $scanner = new SectionScanner();
        $scanner->push("a\nb");

        // 'a' is consumed and skipped, not copied out of the buffer.
        self::assertSame(2, self::cursor($scanner), 'cursor sits at the start of the live line');
        self::assertSame("a\nb", self::liveBuffer($scanner), 'the read head stays in place until compaction');
    }

    public function testAFullyDrainedPushLeavesNoBufferBehind(): void
    {
        $scanner = new SectionScanner();
        $scanner->push("intro\n\n");

        self::assertSame('', self::liveBuffer($scanner));
        self::assertSame(0, self::cursor($scanner));
    }

    public function testOneBigPushProvesTheSameSectionsAsATrickle(): void
    {
        $doc = str_repeat("## Head\n\nprose here\n\n", 800);

        $bulk = new SectionScanner();
        $closed = $bulk->push($doc);
        $tail = $bulk->finish();

        $trickle = new SectionScanner();
        $each = [];
        foreach (preg_split('/(?<=\n)/', $doc, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            foreach ($trickle->push($line) as $section) {
                $each[] = $section;
            }
        }
        $trickleTail = $trickle->finish();

        self::assertSame($each, $closed, 'chunk-split invariance holds at section granularity');
        self::assertSame($trickleTail, $tail);
    }

    public function testASingleLargePushCostsLinearTime(): void
    {
        // ~0.9 MB in one push: the re-slice shuffle measured quadratic
        // seconds here (1.6 MB took 5 s); the cursor makes it a
        // memory-bound scan finishing in tens of milliseconds.
        $doc = str_repeat("## Head\n\nprose line for the scanner to chew through\n\n", 25000);

        $start = microtime(true);
        $closed = (new SectionScanner())->push($doc);
        $elapsed = microtime(true) - $start;

        self::assertNotEmpty($closed);
        self::assertLessThan(1.0, $elapsed, 'one large push must stay linear in size');
    }
}
