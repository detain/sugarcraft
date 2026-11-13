<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests\JsonLines;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\JsonLines;
use SugarCraft\McpClient\JsonLines\Reader;

final class ReaderTest extends TestCase
{
    public function testSplitsCompleteLinesAndRetainsThePartial(): void
    {
        $r = new Reader();
        self::assertSame(['a', 'b'], $r->feed("a\nb\nc"));
        self::assertSame('c', $r->partial());
        self::assertSame(1, $r->pendingBytes());
        self::assertSame(['cd', ''], $r->feed("d\n\n"));
        self::assertSame('', $r->partial());
    }

    public function testALineSplitAcrossManyChunksAssemblesOnce(): void
    {
        $r = new Reader();
        self::assertSame([], $r->feed('{"a"'));
        self::assertSame([], $r->feed(':1'));
        self::assertSame(['{"a":1}'], $r->feed("}\n"));
    }

    public function testCarriageReturnIsLeftToTheCaller(): void
    {
        self::assertSame(["x\r"], (new Reader())->feed("x\r\n"));
    }

    public function testOverCapUnterminatedLineIsDroppedAndCounted(): void
    {
        $r = new Reader(4);
        self::assertSame([], $r->feed('abcde'));
        self::assertSame('', $r->partial());
        self::assertSame(1, $r->takeOversized());
        self::assertSame(0, $r->takeOversized());
    }

    public function testSkipSpansChunksUpToTheNewlineThenResumes(): void
    {
        $r = new Reader(4);
        $r->feed('abcde');
        self::assertSame([], $r->feed('more'));
        self::assertSame(['ok'], $r->feed("tail\nok\n"));
        self::assertSame(1, $r->takeOversized());
    }

    public function testOverCapCompleteLineIsDroppedButNeighboursSurvive(): void
    {
        $r = new Reader(3);
        self::assertSame(['ab', 'cd'], $r->feed("ab\nwxyz\ncd\n"));
        self::assertSame(1, $r->takeOversized());
    }

    public function testLineExactlyAtTheCapPasses(): void
    {
        $r = new Reader(3);
        self::assertSame(['abc'], $r->feed("abc\n"));
        self::assertSame(0, $r->takeOversized());
    }

    public function testRetainedPartialPlusNewChunkCountsAgainstTheCap(): void
    {
        $r = new Reader(4);
        $r->feed('abc');
        self::assertSame(["next"], $r->feed("de\nnext\n"));
        self::assertSame(1, $r->takeOversized());
    }

    public function testResetClearsEverything(): void
    {
        $r = new Reader(4);
        $r->feed('abcde');
        $r->reset();
        self::assertSame(0, $r->takeOversized());
        self::assertSame(['z'], $r->feed("z\n"));
    }

    public function testCapMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Reader(0);
    }

    public function testFactoryBuildsAReader(): void
    {
        self::assertSame(['q'], JsonLines::reader(8)->feed("q\n"));
    }
}
