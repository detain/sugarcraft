<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\Pager;

/**
 * @covers \SugarCraft\Glow\Pager
 */
final class PagerTest extends TestCase
{
    public function testEmptyStreamYieldsNothing(): void
    {
        $stream = fopen('php://memory', 'r');
        $pager = new Pager($stream);
        $chunks = iterator_to_array($pager);
        fclose($stream);

        self::assertEmpty($chunks);
    }

    public function testSingleChunkYieldsAllLines(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "line1\nline2\nline3\n");
        rewind($stream);

        $pager = new Pager($stream, 100); // chunk size > line count
        $chunks = iterator_to_array($pager);
        fclose($stream);

        self::assertCount(1, $chunks);
        self::assertCount(3, $chunks[0]);
        self::assertSame("line1\n", $chunks[0][0]);
        self::assertSame("line2\n", $chunks[0][1]);
        self::assertSame("line3\n", $chunks[0][2]);
    }

    public function testChunkingDividesLinesCorrectly(): void
    {
        $stream = fopen('php://memory', 'r+');
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $line = "line{$i}\n";
            $lines[] = $line;
            fwrite($stream, $line);
        }
        rewind($stream);

        $pager = new Pager($stream, 3); // 3 lines per chunk
        $chunks = iterator_to_array($pager);
        fclose($stream);

        self::assertCount(4, $chunks); // 10 lines / 3 = 4 chunks (3+3+3+1)
        self::assertCount(3, $chunks[0]);
        self::assertCount(3, $chunks[1]);
        self::assertCount(3, $chunks[2]);
        self::assertCount(1, $chunks[3]);
    }

    public function testChunkingWithExactDivision(): void
    {
        $stream = fopen('php://memory', 'r+');
        for ($i = 1; $i <= 6; $i++) {
            fwrite($stream, "line{$i}\n");
        }
        rewind($stream);

        $pager = new Pager($stream, 3); // 6 lines / 3 = exactly 2 chunks
        $chunks = iterator_to_array($pager);
        fclose($stream);

        self::assertCount(2, $chunks);
        self::assertCount(3, $chunks[0]);
        self::assertCount(3, $chunks[1]);
    }

    public function testImplementsIteratorAggregate(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "line1\n");
        rewind($stream);

        $pager = new Pager($stream);
        self::assertInstanceOf(\IteratorAggregate::class, $pager);
        self::assertInstanceOf(\Generator::class, $pager->getIterator());

        fclose($stream);
    }
}
