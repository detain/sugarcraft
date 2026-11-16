<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use PHPUnit\Framework\TestCase;

/**
 * Platform limits are config-passed (docs/SPEC.md §8.5: Teams sizes come from the
 * face's settings, not this class); the pins below drive explicit caps.
 * Fenced code blocks must stay balanced inside every chunk — the chunker
 * opens synthetic fences and closes them, and never emits a chunk that is
 * only fence noise. The mid-fence chunk-start pins are the mutation-proof
 * leg for the fence-state bookkeeping the bridge's first copy failed.
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
        // A chunker that forgets the OPENING state when a fresh chunk starts
        // mid-fence emits the closer alone — this pin reddens it.
        self::assertStringStartsWith('```php', $chunks[count($chunks) - 1]);
    }

    public function testAChunkBeginningMidFenceReopensWithTheSourceInfoString(): void
    {
        // Two lines only: the fence opens and closes INSIDE the budget, so
        // the split is forced by the line cap alone — the second chunk
        // exists purely to prove it knows the fence it inherited is closed.
        $text = "```py\nprint(1)\nprint(2)\n```";
        $chunks = OutboundChunker::split($text, 60, 2);

        self::assertGreaterThanOrEqual(2, $chunks);
        foreach ($chunks as $chunk) {
            $fences = preg_match_all('/^```/m', $chunk, $unused);
            self::assertSame(0, $fences % 2, 'every chunk balances: ' . var_export($chunk, true));
        }
        self::assertStringStartsWith('```py', $chunks[0]);
        self::assertStringContainsString('print(2)', $chunks[count($chunks) - 1]);
    }

    public function testPureFenceNoiseIsDroppedRatherThanSent(): void
    {
        self::assertSame([], OutboundChunker::split("```php\n```", 2000, 17));
    }

    public function testIndentedFencesToggleTheBlockStateLikeBareOnes(): void
    {
        // msteams-20 leg 031: CommonMark fences tolerate up to 3 leading
        // spaces. The old column-0-only law read an indented block as prose,
        // so a chunk boundary could split it open — and stack a synthetic
        // fence on top of the real ones.
        $body = implode("\n", array_map(static fn (int $i): string => "payload line number {$i}", range(1, 30)));
        $text = "intro line\n  ```php\n{$body}\n   ```\noutro line";

        $chunks = OutboundChunker::split($text, 500, 8);
        self::assertGreaterThanOrEqual(2, $chunks, 'the body must force a split inside the block');
        foreach ($chunks as $chunk) {
            $fences = preg_match_all('/^ {0,3}```/m', $chunk, $unused);
            self::assertSame(0, $fences % 2, 'an indented fence block was split open: ' . var_export($chunk, true));
        }
        self::assertStringStartsWith('intro line', $chunks[0]);
        self::assertStringContainsString('outro line', $chunks[count($chunks) - 1]);
    }

    public function testAFourSpaceIndentedFenceIsIndentedCodeNotABlockEdge(): void
    {
        // msteams-20 leg 031 (the ceiling): a 4-space-indented fence line is
        // indented CODE, not a block edge — the new tolerance stops at 3, or
        // the chunker would start reopening fences around literal prose.
        $body = implode("\n", array_map(static fn (int $i): string => "payload line number {$i}", range(1, 30)));
        $text = "intro line\n    ```php\n{$body}\ntrailing prose";

        $chunks = OutboundChunker::split($text, 500, 8);
        self::assertGreaterThanOrEqual(2, $chunks, 'the budget still forces the split');
        foreach ($chunks as $chunk) {
            self::assertStringNotContainsString("\n```", "\n" . $chunk, 'no synthetic fence may decorate a 4-space-indented lookalike: ' . var_export($chunk, true));
        }
        self::assertStringStartsWith('intro line', $chunks[0]);
        self::assertStringContainsString('trailing prose', $chunks[count($chunks) - 1]);
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
        $line = str_repeat("word \u{1F600} chunk ", 20000); // about 260k codepoints, one line
        $chunks = OutboundChunker::split($line, 3800, 40);
        self::assertGreaterThan(50, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(3800, OutboundChunker::len($chunk));
        }
        self::assertSame(trim($line), implode(' ', $chunks));
    }

    public function testWordSplitCostGrowsLinearlyWithTheLength(): void
    {
        // A 4x longer line costs about 4x as much when linear, and about 12x as
        // much under the original quadratic cut. Noise only ever adds time, so the
        // fastest of five runs per side is a stable floor and a loaded runner
        // cannot fail the ratio on one slow sample.
        $small = str_repeat("word \u{1F600} chunk ", 7000);
        $large = str_repeat("word \u{1F600} chunk ", 28000);
        $best = static function (string $line): float {
            $fastest = INF;
            for ($run = 0; $run < 5; $run++) {
                $started = hrtime(true);
                OutboundChunker::split($line, 3800, 40);
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
        $line = str_repeat('ab ', 1500) . "\xff" . str_repeat(' xy', 600);
        $chunks = OutboundChunker::split($line, 3800, 40);
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
            $cut = \mb_strrpos(\mb_substr($body, 0, $room), ' ');
            $cut = $cut === false ? $room : $cut + 1;
            $pieces[] = \rtrim(\mb_substr($body, 0, $cut));
            $body = \ltrim(\mb_substr($body, $cut));
        }
        if ($body !== '') {
            $pieces[] = $body;
        }

        return $pieces;
    }
}
