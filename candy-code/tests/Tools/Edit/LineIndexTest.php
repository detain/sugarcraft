<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\LineIndex;

final class LineIndexTest extends TestCase
{
    public function testOfRecordsLinesAndByteStarts(): void
    {
        $i = LineIndex::of("ab\ncde\n\nf");

        self::assertSame(['ab', 'cde', '', 'f'], $i->lines);
        self::assertSame([0, 3, 7, 8], $i->starts);
        self::assertSame(4, $i->count());
    }

    public function testEmptyContentIsOneEmptyLine(): void
    {
        $i = LineIndex::of('');

        self::assertSame([''], $i->lines);
        self::assertSame(1, $i->count());
    }

    public function testStartsAreByteOffsetsForMultibyte(): void
    {
        self::assertSame([0, 3], LineIndex::of("é\nx")->starts);
    }

    public function testSpanWithoutNewlineStopsAtLineEnd(): void
    {
        $c = "one\ntwo\nthree";
        $i = LineIndex::of($c);

        [$off, $len] = $i->span(0, 1, false);

        self::assertSame("one\ntwo", substr($c, $off, $len));
    }

    public function testSpanWithNewlineIncludesTheTerminator(): void
    {
        $c = "one\ntwo\nthree";
        $i = LineIndex::of($c);

        [$off, $len] = $i->span(0, 1, true);

        self::assertSame("one\ntwo\n", substr($c, $off, $len));
    }

    public function testSpanWithNewlineOnFinalLineHasNoTerminatorToTake(): void
    {
        $c = "one\ntwo";
        [$off, $len] = LineIndex::of($c)->span(1, 1, true);

        self::assertSame('two', substr($c, $off, $len));
    }

    public function testSpanWithoutNewlineDropsCarriageReturnOfLastLine(): void
    {
        $c = "a\r\nb\r\nc";
        [$off, $len] = LineIndex::of($c)->span(0, 1, false);

        self::assertSame("a\r\nb", substr($c, $off, $len));
    }

    public function testNeedleStripsOneTrailingNewline(): void
    {
        self::assertSame(['lines' => ['a', 'b'], 'trailingNewline' => true], LineIndex::needle("a\nb\n"));
        self::assertSame(['lines' => ['a', 'b'], 'trailingNewline' => false], LineIndex::needle("a\nb"));
    }

    public function testNeedleLoneNewlineIsNotTrailing(): void
    {
        self::assertSame(['lines' => ['', ''], 'trailingNewline' => false], LineIndex::needle("\n"));
    }

    public function testNeedleKeepsInnerBlankLines(): void
    {
        self::assertSame(['a', '', 'b'], LineIndex::needle("a\n\nb\n")['lines']);
    }
}
