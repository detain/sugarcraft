<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use PHPUnit\Framework\TestCase;

/**
 * Platform limits are config-passed (§8.5: Teams sizes come from the
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
}
