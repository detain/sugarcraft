<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\OutboundChunker;
use PHPUnit\Framework\TestCase;

/**
 * Discord's platform limits: 2000 characters per message, and a line-count
 * budget on top. Fenced code blocks must stay balanced inside every chunk —
 * the chunker opens synthetic fences and closes them, and never emits a chunk
 * that is only fence noise.
 */
final class OutboundChunkerTest extends TestCase
{
    public function testTheEmptyTextProducesNoChunksAtAll(): void
    {
        self::assertSame([], OutboundChunker::split('', 2000, 17));
        self::assertSame([], OutboundChunker::split("   \n  ", 2000, 17));
    }

    public function testShortTextPassesThroughTrimmedAsOneChunk(): void
    {
        self::assertSame(['hello'], OutboundChunker::split('  hello  ', 2000, 17));
    }

    public function testALongUnbrokenLineSplitsOnWordBoundariesUnderTheLimit(): void
    {
        $text = implode(' ', array_fill(0, 600, 'wordy'));
        self::assertGreaterThan(2000, OutboundChunker::len($text));

        $chunks = OutboundChunker::split($text, 2000, 17);
        self::assertGreaterThanOrEqual(2, $chunks);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(2000, OutboundChunker::len($chunk), 'word-split chunk overran the platform limit');
        }
        self::assertSame($text, implode(' ', $chunks), 'word content must survive the split');
    }

    public function testTheLineBudgetCapsEveryChunk(): void
    {
        $text = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 40)));

        $chunks = OutboundChunker::split($text, 2000, 17);
        self::assertGreaterThanOrEqual(3, $chunks);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(17, substr_count($chunk, "\n") + 1);
        }
    }

    public function testFencedBlocksStayBalancedAcrossChunks(): void
    {
        $body = implode("\n", array_map(static fn (int $i): string => "payload line number {$i}", range(1, 30)));
        $text = "intro line\n```php\n{$body}\n```\noutro line";

        $chunks = OutboundChunker::split($text, 500, 8);
        self::assertGreaterThanOrEqual(2, $chunks);
        foreach ($chunks as $chunk) {
            $fences = preg_match_all('/^```/m', $chunk, $unused);
            self::assertSame(0, $fences % 2, 'a chunk carried an unbalanced fence');
        }
        self::assertStringStartsWith('intro line', $chunks[0]);
        self::assertStringContainsString('outro line', $chunks[count($chunks) - 1]);
        // The chunk that carries only the real closer reopens synthetically
        // first (bridge-faithful law): balanced counts, never a bare fence.
        self::assertStringStartsWith('```php', $chunks[count($chunks) - 1]);
    }

    public function testPureFenceNoiseIsDroppedRatherThanSent(): void
    {
        self::assertSame([], OutboundChunker::split("```php\n```", 2000, 17));
    }

    public function testLenCountsUtf16UnitsSoAstralCharactersCostTwo(): void
    {
        self::assertSame(0, OutboundChunker::len(''));
        self::assertSame(1, OutboundChunker::len('a'));
        self::assertSame(2, OutboundChunker::len('😀'));
        self::assertSame(4, OutboundChunker::len('aé😀'));
    }

    public function testExactlyTwoThousandUnitsOfAstralTextRidesTheFastPath(): void
    {
        $text = str_repeat('😀', 1000); // 1000 codepoints, 2000 units
        self::assertSame([$text], OutboundChunker::split($text, 2000, 17));
    }

    public function testFourThousandAstralUnitsSplitsIntoUnitBudgetedChunks(): void
    {
        $chunks = OutboundChunker::split(str_repeat('😀', 4000), 2000, 17);
        // wordSplit keeps 16 units of fence headroom: 1984-unit pieces.
        self::assertCount(5, $chunks);
        self::assertSame(1984, OutboundChunker::len($chunks[0]));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(2000, OutboundChunker::len($chunk));
        }
        self::assertSame(4000, \array_sum(\array_map(\mb_strlen(...), $chunks)), 'no character lost or duplicated');
    }

    public function testAnAstralHeavySpacedLineKeepsEveryChunkUnderTheCap(): void
    {
        $line = str_repeat('😀a ', 1500); // 6000 units, word-breakable
        $chunks = OutboundChunker::split($line, 2000, 17);
        self::assertGreaterThan(1, $chunks);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(2000, OutboundChunker::len($chunk));
        }
        self::assertSame(trim($line), implode(' ', $chunks));
    }
}
