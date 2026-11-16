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

    public function testWordSplitMatchesTheOriginalGreedyCutOnRandomLines(): void
    {
        // The single-pass wordSplit must cut exactly where the original did:
        // referenceWordSplit() is that original, kept as the oracle.
        $alphabet = [' ', ' ', ' ', "\t", "\r", "\x0B", "\0", 'a', 'bc', 'é', '中', '😀', '  '];
        $wordSplit = new \ReflectionMethod(OutboundChunker::class, 'wordSplit');
        mt_srand(20261116);
        for ($case = 0; $case < 400; $case++) {
            $line = '';
            for ($i = 0, $len = mt_rand(0, 220); $i < $len; $i++) {
                $line .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $limit = mt_rand(1, 60);
            self::assertSame(
                self::referenceWordSplit($line, $limit),
                $wordSplit->invoke(null, $line, $limit),
                "case {$case} (limit {$limit}) diverged from the original cut: " . var_export($line, true),
            );
        }
    }

    public function testALongLineIsCutInOnePassWithEveryWordKept(): void
    {
        // Long-line fixture at real-world scale: every piece stays under the cap
        // and no word is lost at a cut. Scaling is pinned by the ratio test below.
        $line = str_repeat("word \u{1F600} chunk ", 20000); // about 280k UTF-16 units, one line
        $chunks = OutboundChunker::split($line, 2000, 17);
        self::assertGreaterThan(100, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(2000, OutboundChunker::len($chunk));
        }
        self::assertSame(trim($line), implode(' ', $chunks));
    }

    public function testWordSplitCostGrowsLinearlyWithTheLength(): void
    {
        // A 4x longer line costs about 4x as much when linear, and about 12x as
        // much under the original quadratic cut. Best of three runs damps noise.
        $small = str_repeat("word \u{1F600} chunk ", 7000);
        $large = str_repeat("word \u{1F600} chunk ", 28000);
        $best = static function (string $line): float {
            $fastest = INF;
            for ($run = 0; $run < 3; $run++) {
                $started = hrtime(true);
                OutboundChunker::split($line, 2000, 17);
                $fastest = min($fastest, (hrtime(true) - $started) / 1e9);
            }

            return $fastest;
        };
        $ratio = $best($large) / max($best($small), 1e-6);
        self::assertLessThan(8.0, $ratio, sprintf('4x the line cost %.1fx the time: the cut is no longer linear', $ratio));
    }

    public function testInvalidUtf8BytesReachTheChunksAsTheyWere(): void
    {
        // Nothing upstream sanitises outbound text, and the original cut swapped
        // a stray byte for '?' in some pieces. A byte must pass through intact.
        $line = str_repeat('ab ', 800) . "\xff" . str_repeat(' xy', 400);
        $chunks = OutboundChunker::split($line, 2000, 17);
        self::assertStringContainsString("\xff", implode('', $chunks));
        self::assertStringNotContainsString('?', implode('', $chunks));
    }

    /**
     * The original (pre-single-pass) greedy cut, verbatim. Quadratic, so only
     * for the short randomised lines above.
     *
     * @return list<string>
     */
    private static function referenceWordSplit(string $line, int $limit): array
    {
        $room = \max(16, $limit - 16);
        $pieces = [];
        $body = $line;
        while (OutboundChunker::len($body) > $room) {
            $window = self::referenceCodepointsWithin($body, $room);
            $cut = \mb_strrpos(\mb_substr($body, 0, $window), ' ');
            $cut = $cut === false ? $window : $cut + 1;
            $pieces[] = \rtrim(\mb_substr($body, 0, $cut));
            $body = \ltrim(\mb_substr($body, $cut));
        }
        if ($body !== '') {
            $pieces[] = $body;
        }

        return $pieces;
    }

    private static function referenceCodepointsWithin(string $text, int $room): int
    {
        $fit = 0;
        $units = 0;
        foreach (\mb_str_split($text, 1, 'UTF-8') as $ch) {
            $width = OutboundChunker::len($ch);
            if ($units + $width > $room) {
                break;
            }
            $units += $width;
            ++$fit;
        }

        return $fit;
    }
}
