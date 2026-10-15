<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Zone\Manager;
use SugarCraft\Zone\Zone;

/**
 * {@see Manager::scan()} returns a cleaned frame whose visible text must sit
 * exactly where candy-mouse's {@see \SugarCraft\Mouse\Scan::parse()} measured
 * it. Scan accepts a `U+E000 [/] <id> U+E001` tag only when `<id>` passes
 * {@see Mark::isValidId()}, and otherwise skips just the 3 sentinel bytes and
 * measures the text after them — so the stripper must keep that text too, or
 * every zone to its right / below lands on cells it never painted.
 *
 * Cases mirror candy-mouse's `ScanMalformedMarkupTest`.
 */
final class ManagerStripMarkersTest extends TestCase
{
    /** @return array{int,int,int,int} [startCol, startRow, endCol, endRow] */
    private static function box(?Zone $z): array
    {
        self::assertNotNull($z);
        return [$z->startCol, $z->startRow, $z->endCol, $z->endRow];
    }

    /**
     * Terminal cell [col, row] where $needle starts in the cleaned frame:
     * "\n" (and "\r\n") start a new row, a lone "\r" returns to column 1.
     *
     * @return array{int,int}
     */
    private static function cellOf(string $clean, string $needle): array
    {
        $pos = strpos($clean, $needle);
        self::assertNotFalse($pos, "'{$needle}' missing from the cleaned frame");
        $before = substr($clean, 0, $pos);
        $row    = substr_count($before, "\n") + 1;
        $nl     = strrpos($before, "\n");
        $line   = $nl === false ? $before : substr($before, $nl + 1);
        $cr     = strrpos($line, "\r");
        if ($cr !== false) {
            $line = substr($line, $cr + 1);
        }
        return [Width::string($line) + 1, $row];
    }

    private static function assertNoSentinels(string $clean): void
    {
        self::assertStringNotContainsString(Sentinel::OPEN, $clean);
        self::assertStringNotContainsString(Sentinel::CLOSE, $clean);
    }

    // ─── forged / stray sentinels ───────────────────────────────────────────

    public function testStraySentinelPairKeepsTheTextScanMeasures(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        // The would-be id 'cd efgh ij' has spaces → lone sentinel; the text
        // after it is visible, so it must survive the strip.
        $clean = $m->scan("ab{$o}cd efgh ij{$c}" . str_repeat('x', 20) . $m->mark('z', 'QQQQ'));

        self::assertSame('abcd efgh ij' . str_repeat('x', 20) . 'QQQQ', $clean);
        self::assertSame([33, 1, 36, 1], self::box($m->get('z')));
        self::assertSame([33, 1], self::cellOf($clean, 'QQQQ'));
    }

    public function testStraySentinelSpanningNewlinesKeepsRows(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan("ab{$o}cd\nef\ngh{$c}xx\n" . $m->mark('z', 'QQQQ'));

        self::assertSame("abcd\nef\nghxx\nQQQQ", $clean);
        self::assertSame([1, 4, 4, 4], self::box($m->get('z')));
        self::assertSame([1, 4], self::cellOf($clean, 'QQQQ'));
    }

    public function testForgedTagWrappingAnEscapeKeepsTheEscape(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        // An "id" carrying an SGR is not a tag: the old stripper ate the SGR
        // (and anything else up to the U+E001) along with the sentinels.
        $clean = $m->scan("{$o}\x1b[31m{$c}red\x1b[0m" . $m->mark('z', 'Q'));

        self::assertSame("\x1b[31mred\x1b[0mQ", $clean);
        self::assertSame([4, 1, 4, 1], self::box($m->get('z')));
        self::assertSame([4, 1], self::cellOf($clean, 'Q'));
    }

    public function testLoneOpenBeforeARealTagDoesNotSwallowIt(): void
    {
        $o = Sentinel::OPEN;
        $m = Manager::newGlobal();
        // The first U+E001 ahead terminates z's open tag; the would-be id
        // 'abc<U+E000>z' is invalid, so only the stray 3 bytes go.
        $clean = $m->scan("{$o}abc" . $m->mark('z', 'Q'));

        self::assertSame('abcQ', $clean);
        self::assertSame([4, 1, 4, 1], self::box($m->get('z')));
    }

    public function testEmptyIdSentinelPairsAreConsumedWhole(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan("{$o}{$c}{$o}{$c}{$o}/{$c}" . $m->mark('z', 'QQQQ'));

        self::assertSame('QQQQ', $clean);
        self::assertSame([1, 1, 4, 1], self::box($m->get('z')));
    }

    public function testOversizedIdFieldIsTreatedAsLoneSentinel(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $long = str_repeat('a', Mark::MAX_ID_BYTES + 1);
        $clean = $m->scan($o . $long . $c . $m->mark('z', 'Q'));

        self::assertSame($long . 'Q', $clean);
        self::assertSame(Mark::MAX_ID_BYTES + 2, $m->get('z')?->startCol);
        self::assertSame([Mark::MAX_ID_BYTES + 2, 1], self::cellOf($clean, 'Q'));
    }

    public function testMaxLengthIdIsStillATag(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $edge = str_repeat('a', Mark::MAX_ID_BYTES);
        // A valid open with no close (clipped frame): consumed whole.
        $clean = $m->scan($o . $edge . $c . 'Q');

        self::assertSame('Q', $clean);
    }

    public function testInvalidIdCloseTagKeepsItsText(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan($m->mark('z', 'AB') . "{$o}/z z{$c}" . $m->mark('y', 'C'));

        self::assertSame('AB/z zC', $clean);
        self::assertSame([1, 1, 2, 1], self::box($m->get('z')));
        self::assertSame([7, 1, 7, 1], self::box($m->get('y')));
        self::assertSame([7, 1], self::cellOf($clean, 'C'));
    }

    public function testBareCloseSentinelDropsOnlyItsBytes(): void
    {
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan("A{$c}B" . $m->mark('z', 'Q'));

        self::assertSame('ABQ', $clean);
        self::assertSame([3, 1, 3, 1], self::box($m->get('z')));
    }

    public function testUnterminatedOpenAtEndOfFrameDropsOnlyItsBytes(): void
    {
        $o = Sentinel::OPEN;
        $m = Manager::newGlobal();

        self::assertSame('AB', $m->scan("AB{$o}"));
        self::assertSame('AB/', $m->scan("AB{$o}/"));
    }

    public function testManyUnmatchedOpensAreAllKeptAsText(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        // Every would-be id is ' ' + more stray sentinels → all lone.
        $clean = $m->scan(str_repeat("{$o} ", 5000) . $c . $m->mark('z', 'Q'));

        self::assertSame(str_repeat(' ', 5000) . 'Q', $clean);
        self::assertSame([5001, 1, 5001, 1], self::box($m->get('z')));
    }

    // ─── line endings ───────────────────────────────────────────────────────

    public function testCrlfRowIsPreservedAndMatchesScan(): void
    {
        $m = Manager::newGlobal();
        $clean = $m->scan("AAAA\r\n" . $m->mark('z', 'QQQQ'));

        self::assertSame("AAAA\r\nQQQQ", $clean);
        self::assertSame([1, 2, 4, 2], self::box($m->get('z')));
        self::assertSame([1, 2], self::cellOf($clean, 'QQQQ'));
    }

    public function testCrlfInsideMultiRowZone(): void
    {
        $m = Manager::newGlobal();
        $clean = $m->scan($m->mark('z', "ABC\r\nDE") . "\r\n" . $m->mark('y', 'F'));

        self::assertSame("ABC\r\nDE\r\nF", $clean);
        self::assertSame([1, 1, 3, 2], self::box($m->get('z')));
        self::assertSame([1, 3, 1, 3], self::box($m->get('y')));
    }

    public function testStraySentinelWhoseIdFieldSpansCrlfKeepsTheRow(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan("ab{$o}cd\r\nef{$c}" . $m->mark('z', 'QQ'));

        self::assertSame("abcd\r\nefQQ", $clean);
        self::assertSame([3, 2, 4, 2], self::box($m->get('z')));
        self::assertSame([3, 2], self::cellOf($clean, 'QQ'));
    }

    public function testStraySentinelWhoseIdFieldHoldsALoneCrKeepsIt(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan("AAAA{$o}x\r{$c}" . $m->mark('z', 'QQ'));

        self::assertSame("AAAAx\rQQ", $clean);
        self::assertSame([1, 1, 2, 1], self::box($m->get('z')));
        self::assertSame([1, 1], self::cellOf($clean, 'QQ'));
    }

    public function testLoneCarriageReturnBeforeZone(): void
    {
        $m = Manager::newGlobal();
        $clean = $m->scan("AAAA\r" . $m->mark('z', 'QQ'));

        self::assertSame("AAAA\rQQ", $clean);
        self::assertSame([1, 1, 2, 1], self::box($m->get('z')));
    }

    // ─── documented lenient cases (valid ids) ───────────────────────────────

    public function testOrphanCloseWithValidIdIsRemovedWhole(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan("AA{$o}/gone{$c}" . $m->mark('z', 'Q'));

        self::assertSame('AAQ', $clean);
        self::assertSame([3, 1, 3, 1], self::box($m->get('z')));
        self::assertNull($m->get('gone'));
    }

    public function testUnclosedOpenWithValidIdIsRemovedWhole(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $m = Manager::newGlobal();
        $clean = $m->scan($m->mark('z', 'Q') . "{$o}cut{$c}BB");

        self::assertSame('QBB', $clean);
        self::assertNoSentinels($clean);
        self::assertNull($m->get('cut'));
    }

    public function testWellFormedFrameStillStripsCompletely(): void
    {
        $m = Manager::newPrefix('w');
        $clean = $m->scan($m->mark('a', 'one') . "\n" . $m->mark('b:2', 'two'));

        self::assertSame("one\ntwo", $clean);
        self::assertNoSentinels($clean);
        self::assertSame([1, 2, 3, 2], self::box($m->get('b:2')));
    }
}
