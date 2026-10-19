<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Tools\ShellTailRing;

/**
 * The ring's two contracts (WEB-4): the tail it carries is the LAST
 * budget bytes of everything seen (never a torn leading codepoint), and the
 * frames it emits are capped to one per interval - the first exception being
 * the opening frame, which must reach the browser before the viewer gives up.
 * The clock is injected, so no test here sleeps.
 */
final class ShellTailRingTest extends TestCase
{
    /** @var list<ToolProgress> */
    private array $frames = [];

    private float $now = 100.0;

    private function ring(int $budget = 64, float $interval = 0.5): ShellTailRing
    {
        $clock = function (): float {
            return $this->now;
        };

        return new ShellTailRing(
            'c1',
            'Bash',
            function (ToolProgress $frame): void {
                $this->frames[] = $frame;
            },
            $budget,
            $interval,
            $clock,
        );
    }

    public function testTheOpeningChunkFramesImmediately(): void
    {
        $ring = $this->ring();
        $ring->onChunk('hello');

        $this->assertCount(1, $this->frames);
        $this->assertSame('hello', $this->frames[0]->tail);
        $this->assertSame(1, $this->frames[0]->seq);
        $this->assertFalse($this->frames[0]->truncated);
        $this->assertSame(64, $this->frames[0]->tailBudget);
        $this->assertSame('c1', $this->frames[0]->toolCallId);
        $this->assertSame('Bash', $this->frames[0]->toolName);
    }

    public function testFurtherChunksWaitForTheInterval(): void
    {
        $ring = $this->ring();
        $ring->onChunk('a');
        $this->now += 0.1;
        $ring->onChunk('b');
        $this->assertCount(1, $this->frames, 'a frame per interval, not per chunk');

        $this->now += 0.4;
        $ring->onChunk('c');
        $this->assertCount(2, $this->frames);
        $this->assertSame('abc', $this->frames[1]->tail, 'the newer frame carries the whole tail');
        $this->assertSame(2, $this->frames[1]->seq);
    }

    public function testTheTailKeepsTheNewestBytesAndFlagsTruncation(): void
    {
        $ring = $this->ring(budget: 8);
        $ring->onChunk(str_repeat('x', 20));

        $this->assertCount(1, $this->frames);
        $this->assertSame(8, strlen($this->frames[0]->tail));
        $this->assertTrue($this->frames[0]->truncated, 'dropped bytes are honest, not silent');
    }

    public function testATornLeadingCodepointIsCutFromTheTail(): void
    {
        $ring = $this->ring(budget: 2);
        $ring->onChunk(chr(0xE9) . chr(0xA9) . chr(0x41));

        // Clamp keeps the last two bytes; the leading continuation byte would
        // render as mojibake (or invalid UTF-8) in the browser, so the ring
        // trims it and the tail starts at a whole character.
        $this->assertSame('A', $this->frames[0]->tail);
        $this->assertTrue($this->frames[0]->truncated);
    }

    public function testFinishFlushesPendingBytesOnceAndSilenceOtherwise(): void
    {
        $ring = $this->ring();
        $ring->onChunk('a');
        $this->now += 0.1;
        $ring->onChunk('b');
        $this->assertCount(1, $this->frames);

        $ring->finish();
        $this->assertCount(2, $this->frames);
        $this->assertSame('ab', $this->frames[1]->tail);

        $ring->finish();
        $this->assertCount(2, $this->frames, 'a clean ring has nothing left to say');
    }

    public function testQuietChunksCostNoFrames(): void
    {
        $ring = $this->ring();
        $ring->onChunk('');
        $ring->finish();

        $this->assertSame([], $this->frames);
    }

    public function testSequenceNumbersStrictlyIncrease(): void
    {
        $ring = $this->ring();
        foreach (['one', 'two', 'three'] as $i => $chunk) {
            $ring->onChunk($chunk);
            $this->now += 1.0;
        }
        $ring->finish();

        $seqs = array_map(static fn (ToolProgress $f): int => $f->seq, $this->frames);
        $this->assertNotEmpty($seqs);
        $sorted = $seqs;
        sort($sorted);
        $this->assertSame($sorted, $seqs, 'monotone');
        $this->assertSame(\count($seqs), \count(array_unique($seqs)), 'no frame repeats a seq');
    }
}
