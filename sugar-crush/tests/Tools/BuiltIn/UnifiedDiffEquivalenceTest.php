<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Write;

/**
 * The memory-bounded unified diff (audit F-T2) produces exactly the bytes the
 * line-array version did.
 *
 * The rewrite trims the unchanged prefix/suffix on the raw strings and only
 * splits the changed middle into lines, so every place a byte-level cut could
 * disagree with whole-line trimming is pinned here: edits at the first and
 * last line and byte, a missing trailing newline, CRLF, an empty side,
 * ambiguous duplicate lines, multi-hunk and merged-hunk `replace_all`, and a
 * change straddling the 64 KiB compare chunk. Every expected string was
 * captured from the pre-fix implementation before the trait changed, so a
 * difference here is a behaviour change, not a style choice.
 *
 * @see \SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff
 */
final class UnifiedDiffEquivalenceTest extends TestCase
{
    #[DataProvider('edits')]
    public function testDiffMatchesTheLineArrayAlgorithm(string $old, string $new, string $expected): void
    {
        $this->assertSame($expected, self::diff(Edit::class, $old, $new));
    }

    /**
     * Write uses the same trait; a regression that only one user sees (a
     * per-class copy, say) would otherwise pass above.
     */
    #[DataProvider('edits')]
    public function testWriteSharesTheSameDiff(string $old, string $new, string $expected): void
    {
        $this->assertSame($expected, self::diff(Write::class, $old, $new));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function edits(): iterable
    {
        $ten = self::lines(1, 10);
        $twelve = self::lines(1, 12);
        $twenty = self::lines(1, 20);
        $scattered = "TARGET\n" . self::lines(1, 8) . "TARGET\n" . self::lines(9, 16) . "TARGET\n";
        $crlf = "one\r\ntwo\r\nthree\r\nfour\r\n";
        // ~18,000 short lines: big enough that the prefix spans several
        // compare chunks, small enough to keep the provider instant.
        $big = self::rows(200_000);

        yield 'middle line changed' => [
            $ten,
            str_replace("line5\n", "LINE5\n", $ten),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -2,7 +2,7 @@\n"
            . " line2\n"
            . " line3\n"
            . " line4\n"
            . "-line5\n"
            . "+LINE5\n"
            . " line6\n"
            . " line7\n"
            . " line8\n",
        ];
        yield 'first line changed' => [
            $ten,
            str_replace("line1\n", "FIRST\n", $ten),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,4 @@\n"
            . "-line1\n"
            . "+FIRST\n"
            . " line2\n"
            . " line3\n"
            . " line4\n",
        ];
        yield 'last line changed' => [
            $ten,
            str_replace("line10\n", "LAST\n", $ten),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -7,4 +7,4 @@\n"
            . " line7\n"
            . " line8\n"
            . " line9\n"
            . "-line10\n"
            . "+LAST\n",
        ];
        yield 'last line changed, no trailing newline' => [
            rtrim($ten, "\n"),
            rtrim(str_replace("line10\n", "LAST\n", $ten), "\n"),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -7,4 +7,4 @@\n"
            . " line7\n"
            . " line8\n"
            . " line9\n"
            . "-line10\n"
            . "+LAST\n",
        ];
        yield 'trailing newline added only' => [
            rtrim($ten, "\n"),
            $ten,
            '',
        ];
        yield 'trailing newline removed with last-line change' => [
            $ten,
            substr($ten, 0, -strlen("line10\n")) . 'LAST',
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -7,4 +7,4 @@\n"
            . " line7\n"
            . " line8\n"
            . " line9\n"
            . "-line10\n"
            . "+LAST\n",
        ];
        yield 'two far-apart changes, two hunks' => [
            $twenty,
            str_replace(["line2\n", "line18\n"], ["TWO\n", "EIGHTEEN\n"], $twenty),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,5 +1,5 @@\n"
            . " line1\n"
            . "-line2\n"
            . "+TWO\n"
            . " line3\n"
            . " line4\n"
            . " line5\n"
            . "@@ -15,6 +15,6 @@\n"
            . " line15\n"
            . " line16\n"
            . " line17\n"
            . "-line18\n"
            . "+EIGHTEEN\n"
            . " line19\n"
            . " line20\n",
        ];
        yield 'changes 7 apart merge into one hunk' => [
            $twelve,
            str_replace(["line2\n", "line9\n"], ["TWO\n", "NINE\n"], $twelve),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,12 +1,12 @@\n"
            . " line1\n"
            . "-line2\n"
            . "+TWO\n"
            . " line3\n"
            . " line4\n"
            . " line5\n"
            . " line6\n"
            . " line7\n"
            . " line8\n"
            . "-line9\n"
            . "+NINE\n"
            . " line10\n"
            . " line11\n"
            . " line12\n",
        ];
        yield 'replace_all scattered incl. first and last line' => [
            $scattered,
            str_replace('TARGET', 'REPLACED', $scattered),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,4 @@\n"
            . "-TARGET\n"
            . "+REPLACED\n"
            . " line1\n"
            . " line2\n"
            . " line3\n"
            . "@@ -7,7 +7,7 @@\n"
            . " line6\n"
            . " line7\n"
            . " line8\n"
            . "-TARGET\n"
            . "+REPLACED\n"
            . " line9\n"
            . " line10\n"
            . " line11\n"
            . "@@ -16,4 +16,4 @@\n"
            . " line14\n"
            . " line15\n"
            . " line16\n"
            . "-TARGET\n"
            . "+REPLACED\n",
        ];
        yield 'CRLF line changed' => [
            $crlf,
            str_replace("two\r\n", "TWO\r\n", $crlf),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,4 @@\n"
            . " one\r\n"
            . "-two\r\n"
            . "+TWO\r\n"
            . " three\r\n"
            . " four\r\n",
        ];
        yield 'CRLF converted to LF' => [
            $crlf,
            str_replace("\r\n", "\n", $crlf),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,4 @@\n"
            . "-one\r\n"
            . "-two\r\n"
            . "-three\r\n"
            . "-four\r\n"
            . "+one\n"
            . "+two\n"
            . "+three\n"
            . "+four\n",
        ];
        yield 'new file (empty old side)' => [
            '',
            "a\nb\nc\n",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -0,0 +1,3 @@\n"
            . "+a\n"
            . "+b\n"
            . "+c\n",
        ];
        yield 'new file without trailing newline' => [
            '',
            "a\nb",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -0,0 +1,2 @@\n"
            . "+a\n"
            . "+b\n",
        ];
        yield 'everything deleted' => [
            "a\nb\n",
            '',
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,2 +0,0 @@\n"
            . "-a\n"
            . "-b\n",
        ];
        yield 'insertion after an unchanged line' => [
            "start\nmiddle\nend\n",
            "start\nmiddle\nextra1\nextra2\nend\n",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,3 +1,5 @@\n"
            . " start\n"
            . " middle\n"
            . "+extra1\n"
            . "+extra2\n"
            . " end\n",
        ];
        yield 'duplicate line removed (ambiguous alignment)' => [
            "a\na\na\nb\n",
            "a\na\nb\n",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,3 @@\n"
            . " a\n"
            . " a\n"
            . "-a\n"
            . " b\n",
        ];
        yield 'duplicate line added at end' => [
            "x\ny\n",
            "x\ny\ny\n",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,2 +1,3 @@\n"
            . " x\n"
            . " y\n"
            . "+y\n",
        ];
        yield 'blank lines' => [
            "\n\n\n",
            "\n\nx\n\n",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,3 +1,4 @@\n"
            . " \n"
            . " \n"
            . "+x\n"
            . " \n",
        ];
        yield 'change inside a long line' => [
            str_repeat('a', 300) . "\n" . self::lines(1, 5),
            str_repeat('a', 150) . 'B' . str_repeat('a', 149) . "\n" . self::lines(1, 5),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,4 @@\n"
            . "-" . str_repeat('a', 300) . "\n"
            . "+" . str_repeat('a', 150) . 'B' . str_repeat('a', 149) . "\n"
            . " line1\n"
            . " line2\n"
            . " line3\n",
        ];
        yield 'change straddling a 64 KiB compare chunk' => [
            $big,
            substr($big, 0, 65530) . 'XYZXYZXYZXYZ' . substr($big, 65542),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -5955,8 +5955,7 @@\n"
            . " row 005954\n"
            . " row 005955\n"
            . " row 005956\n"
            . "-row 005957\n"
            . "-row 005958\n"
            . "+rowXYZXYZXYZXYZ005958\n"
            . " row 005959\n"
            . " row 005960\n"
            . " row 005961\n",
        ];
        yield 'change at the last byte of a large file' => [
            $big,
            substr($big, 0, -2) . "!\n",
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -18179,4 +18179,4 @@\n"
            . " row 018178\n"
            . " row 018179\n"
            . " row 018180\n"
            . "-row 018181\n"
            . "+row 01818!\n",
        ];
        yield 'change at the first byte of a large file' => [
            $big,
            '#' . substr($big, 1),
            "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1,4 +1,4 @@\n"
            . "-row 000000\n"
            . "+#ow 000000\n"
            . " row 000001\n"
            . " row 000002\n"
            . " row 000003\n",
        ];
    }

    private static function diff(string $class, string $old, string $new): string
    {
        $method = new \ReflectionMethod($class, 'unifiedDiff');

        return $method->invoke(null, 'f.txt', $old, $new);
    }

    private static function lines(int $from, int $to): string
    {
        $text = '';
        for ($i = $from; $i <= $to; $i++) {
            $text .= "line$i\n";
        }

        return $text;
    }

    private static function rows(int $bytes): string
    {
        $text = '';
        for ($i = 0; strlen($text) < $bytes; $i++) {
            $text .= sprintf("row %06d\n", $i);
        }

        return $text;
    }
}
