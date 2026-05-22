<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Render\FrameDedup;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Terminal;

/**
 * Tests for FrameDedup collapsing identical adjacent frames.
 *
 * Verifies that when FrameDedup::dedup() wraps a FrameStream,
 * consecutive identical frames are collapsed into a single output
 * frame, reducing the total frame count significantly for typical
 * terminal recordings where 80-95% of frames are identical.
 */
final class FrameDedupTest extends TestCase
{
    public function testDedupCollapsesIdenticalFrames(): void
    {
        $terminal = Terminal::new(80, 24);

        $frames = $this->createFramesWithIdenticalContent($terminal, 10);
        $stream = new ArrayFrameStream($frames);
        $deduped = iterator_to_array(FrameDedup::dedup($stream));

        $this->assertCount(1, $deduped);
    }

    public function testDedupPassesThroughUniqueFrames(): void
    {
        $terminal = Terminal::new(80, 24);

        $frames = [
            $terminal->snapshot(0.0),
            $this->makeSnapshotWithChar($terminal, 0, 0, 'A', 0.033),
            $this->makeSnapshotWithChar($terminal, 0, 0, 'B', 0.066),
        ];
        $stream = new ArrayFrameStream($frames);
        $deduped = iterator_to_array(FrameDedup::dedup($stream));

        $this->assertCount(3, $deduped);
    }

    public function testDedupCollapsesMiddleIdenticalRun(): void
    {
        $terminal = Terminal::new(80, 24);

        $frames = [
            $this->makeSnapshotWithChar($terminal, 0, 0, 'A', 0.0),
            $this->makeSnapshotWithChar($terminal, 0, 0, 'B', 0.033),
            $this->makeSnapshotWithChar($terminal, 0, 0, 'B', 0.066),
            $this->makeSnapshotWithChar($terminal, 0, 0, 'B', 0.099),
            $this->makeSnapshotWithChar($terminal, 0, 0, 'C', 0.132),
        ];
        $stream = new ArrayFrameStream($frames);
        $deduped = iterator_to_array(FrameDedup::dedup($stream));

        $this->assertCount(3, $deduped);
        $this->assertSame('A', $deduped[0]->grid->get(0, 0)->char);
        $this->assertSame('B', $deduped[1]->grid->get(0, 0)->char);
        $this->assertSame('C', $deduped[2]->grid->get(0, 0)->char);
    }

    public function testDedupWithTenIdenticalAndOneDifferent(): void
    {
        $terminal = Terminal::new(80, 24);

        $frames = [];
        for ($i = 0; $i < 10; $i++) {
            $frames[] = $this->makeSnapshotWithChar($terminal, 0, 0, 'X', $i * 0.033);
        }
        $frames[] = $this->makeSnapshotWithChar($terminal, 0, 0, 'Y', 10 * 0.033);

        $stream = new ArrayFrameStream($frames);
        $deduped = iterator_to_array(FrameDedup::dedup($stream));

        $this->assertCount(2, $deduped);
        $this->assertSame('X', $deduped[0]->grid->get(0, 0)->char);
        $this->assertSame('Y', $deduped[1]->grid->get(0, 0)->char);
    }

    public function testDedupHonorsHoldMax(): void
    {
        $terminal = Terminal::new(80, 24);

        $frames = [];
        for ($i = 0; $i < 10; $i++) {
            $frames[] = $this->makeSnapshotWithChar($terminal, 0, 0, 'Z', $i * 0.033);
        }

        $stream = new ArrayFrameStream($frames);
        $deduped = iterator_to_array(FrameDedup::dedup($stream, 5));

        $this->assertCount(2, $deduped);
    }

    public function testDedupEmptyStream(): void
    {
        $stream = new ArrayFrameStream([]);
        $deduped = iterator_to_array(FrameDedup::dedup($stream));

        $this->assertCount(0, $deduped);
    }

    public function testDedupWithHoldMaxZero(): void
    {
        $terminal = Terminal::new(80, 24);

        $frames = [];
        for ($i = 0; $i < 3; $i++) {
            $frames[] = $this->makeSnapshotWithChar($terminal, 0, 0, 'A', $i * 0.033);
        }

        $stream = new ArrayFrameStream($frames);
        $deduped = iterator_to_array(FrameDedup::dedup($stream, 0));

        $this->assertCount(3, $deduped);
    }

    /**
     * Create multiple frames with identical content (same char at 0,0).
     *
     * @return list<Snapshot>
     */
    private function createFramesWithIdenticalContent(Terminal $terminal, int $count): array
    {
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $frames[] = $this->makeSnapshotWithChar($terminal, 0, 0, 'SAME', $i * 0.033);
        }
        return $frames;
    }

    private function makeSnapshotWithChar(Terminal $terminal, int $row, int $col, string $char, float $time): Snapshot
    {
        $grid = new CellGrid(80, 24);
        $grid = $grid->set($row, $col, new Cell($char));
        $cursor = new Cursor();
        return new Snapshot($grid, $cursor, $time);
    }
}

/**
 * Test double for FrameStream that yields frames from a provided array.
 * Allows controlled testing of FrameDedup without crafting real cassettes.
 *
 * @implements IteratorAggregate<int, Snapshot>
 */
final class ArrayFrameStream implements \IteratorAggregate
{
    /** @var list<Snapshot> */
    private array $frames;

    /** @param list<Snapshot> $frames */
    public function __construct(array $frames)
    {
        $this->frames = $frames;
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->frames as $index => $frame) {
            yield $index => $frame;
        }
    }
}
