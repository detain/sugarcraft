<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Diff\Diff;

/**
 * Delegation pins for the folded diff builder (lane W1): the trait's engine is
 * sugar-diff, and what remains trait-side is a memory-bounded window (audit
 * F-T2) plus GNU-faithful relocation of the library's hunk headers. The engine
 * itself is tested — and GNU-oracle-pinned — in sugar-diff; this suite never
 * re-tests it (AGENTS.md façade law). What it pins instead:
 *
 * 1. windowing + {@see relocation} fidelity: for every shape a byte-level cut
 *    could disagree with whole-line trimming on — edits at the first and last
 *    line and byte, a missing trailing newline, CRLF, an empty side, ambiguous
 *    duplicate lines, multi-hunk and merged-hunk `replace_all`, a change
 *    straddling the 64 KiB compare chunk, a change far into a large file — the
 *    wrapper renders byte-identical output to calling the library on the whole
 *    inputs; and
 * 2. the handful of wrapper-owned literal shapes: the adopted GNU header syntax
 *    (count of 1 elided, `,0` zero-side anchors kept), "no change" => '', and
 *    that Write and Edit share one builder.
 *
 * @see \SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff
 */
final class UnifiedDiffEquivalenceTest extends TestCase
{
    #[DataProvider('edits')]
    public function testWindowedWrapperMatchesTheLibraryOnTheWholeInput(string $old, string $new): void
    {
        $this->assertSame(self::wholeFileLibraryDiff($old, $new), self::diff(Edit::class, 'f.txt', $old, $new));
    }

    /**
     * Write uses the same trait; a regression that only one user sees (a
     * per-class copy, say) would otherwise pass above.
     */
    #[DataProvider('edits')]
    public function testWriteSharesTheSameDiff(string $old, string $new): void
    {
        $this->assertSame(self::wholeFileLibraryDiff($old, $new), self::diff(Write::class, 'f.txt', $old, $new));
    }

    /**
     * The header shape the fold adopted from sugar-diff (the old private
     * engine's `-N,1` twin divergence): a one-line range elides its count, a
     * zero-count side keeps `,0` and anchors at 0 only where GNU does.
     */
    public function testAdoptedGnuHeaderShapes(): void
    {
        $this->assertSame(
            "--- a/f.txt\n+++ b/f.txt\n@@ -1 +1 @@\n-Hello World\n+Hello PHP\n",
            self::diff(Edit::class, 'f.txt', "Hello World\n", "Hello PHP\n"),
        );
        $this->assertSame(
            "--- a/f.txt\n+++ b/f.txt\n@@ -0,0 +1 @@\n+new line\n",
            self::diff(Edit::class, 'f.txt', '', "new line\n"),
        );
        $this->assertSame(
            "--- a/f.txt\n+++ b/f.txt\n@@ -1 +0,0 @@\n-only line\n",
            self::diff(Edit::class, 'f.txt', "only line\n", ''),
        );
        $this->assertSame(
            "--- a/f.txt\n+++ b/f.txt\n@@ -0,0 +1,2 @@\n+a\n+b\n",
            self::diff(Edit::class, 'f.txt', '', "a\nb"),
        );
    }

    /** Equal-after-line-splitting inputs (incl. a pure trailing-newline flip) render no diff. */
    public function testNoChangeYieldsEmptyDiff(): void
    {
        $this->assertSame('', self::diff(Edit::class, 'f.txt', "a\nb", "a\nb\n"));
        $this->assertSame('', self::diff(Edit::class, 'f.txt', "same\n", "same\n"));
        $this->assertSame('', self::diff(Edit::class, 'f.txt', '', ''));
    }

    /** @return iterable<string, array{string, string}> */
    public static function edits(): iterable
    {
        $ten = self::lines(1, 10);
        $twelve = self::lines(1, 12);
        $twenty = self::lines(1, 20);
        $scattered = "TARGET\n" . self::lines(1, 8) . "TARGET\n" . self::lines(9, 16) . "TARGET\n";
        $crlf = "one\r\ntwo\r\nthree\r\nfour\r\n";
        // ~18,000 short lines: big enough that the prefix spans several
        // compare chunks and the window is cut out of the middle of the
        // file, small enough to keep the provider instant.
        $big = self::rows(200_000);

        yield 'middle line changed' => [$ten, str_replace("line5\n", "LINE5\n", $ten)];
        yield 'first line changed' => [$ten, str_replace("line1\n", "FIRST\n", $ten)];
        yield 'last line changed' => [$ten, str_replace("line10\n", "LAST\n", $ten)];
        yield 'last line changed, no trailing newline' => [
            rtrim($ten, "\n"),
            rtrim(str_replace("line10\n", "LAST\n", $ten), "\n"),
        ];
        yield 'trailing newline added only' => [rtrim($ten, "\n"), $ten];
        yield 'trailing newline removed with last-line change' => [
            $ten,
            substr($ten, 0, -strlen("line10\n")) . 'LAST',
        ];
        yield 'single line replaced by one line' => ["Hello World\n", "Hello PHP\n"];
        yield 'two far-apart changes, two hunks' => [
            $twenty,
            str_replace(["line2\n", "line18\n"], ["TWO\n", "EIGHTEEN\n"], $twenty),
        ];
        yield 'changes 7 apart merge into one hunk' => [
            $twelve,
            str_replace(["line2\n", "line9\n"], ["TWO\n", "NINE\n"], $twelve),
        ];
        yield 'replace_all scattered incl. first and last line' => [$scattered, str_replace('TARGET', 'REPLACED', $scattered)];
        yield 'CRLF line changed' => [$crlf, str_replace("two\r\n", "TWO\r\n", $crlf)];
        yield 'CRLF converted to LF' => [$crlf, str_replace("\r\n", "\n", $crlf)];
        yield 'new file (empty old side)' => ['', "a\nb\nc\n"];
        yield 'new file without trailing newline' => ['', "a\nb"];
        yield 'new file of one line' => ['', "solo\n"];
        yield 'everything deleted' => ["a\nb\n", ''];
        yield 'one line deleted to empty' => ["solo\n", ''];
        yield 'insertion after an unchanged line' => [
            "start\nmiddle\nend\n",
            "start\nmiddle\nextra1\nextra2\nend\n",
        ];
        yield 'duplicate line removed (ambiguous alignment)' => ["a\na\na\nb\n", "a\na\nb\n"];
        yield 'duplicate line added at end' => ["x\ny\n", "x\ny\ny\n"];
        yield 'blank lines' => ["\n\n\n", "\n\nx\n\n"];
        yield 'change inside a long line' => [
            str_repeat('a', 300) . "\n" . self::lines(1, 5),
            str_repeat('a', 150) . 'B' . str_repeat('a', 149) . "\n" . self::lines(1, 5),
        ];
        yield 'change straddling a 64 KiB compare chunk' => [
            $big,
            substr($big, 0, 65530) . 'XYZXYZXYZXYZ' . substr($big, 65542),
        ];
        yield 'change at the last byte of a large file' => [$big, substr($big, 0, -2) . "!\n"];
        yield 'change at the first byte of a large file' => [$big, '#' . substr($big, 1)];
        yield 'insertion deep in a large file' => [$big, substr($big, 0, 100_004) . "NEW ROW\n" . substr($big, 100_004)];
    }

    /**
     * The library's own answer on the whole inputs, with both sides handed
     * over as git-counted line lists — the same pre-split shape the trait's
     * window uses, which is what keeps unterminated final lines marker-free
     * (the folded behavior; the raw-string parse would add GNU's
     * `\ No newline at end of file` rows the preview has never printed).
     */
    private static function wholeFileLibraryDiff(string $old, string $new): string
    {
        return Diff::compute(self::gitLines($old), self::gitLines($new))->unified('f.txt');
    }

    /** @return list<string> */
    private static function gitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private static function diff(string $class, string $path, string $old, string $new): string
    {
        $method = new \ReflectionMethod($class, 'unifiedDiff');

        return $method->invoke(null, $path, $old, $new);
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
