<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * `Width::wrapAnsi()` and hard line breaks.
 *
 * The `\n` branch appended the running word to the line without asking
 * whether it fit, so the last word before a hard break rode onto a full
 * line: `wrapAnsi("aaaa bbbb x\ny", 10)` returned an 11-cell first line
 * while the same text without the `\ny` tail wrapped correctly. The
 * over-wide-cluster branch had the same unconditional append. sugar-crush
 * wraps on its hot render path, and the diff renderer owns exactly one
 * terminal row per line, so an over-wide line corrupts the frame.
 *
 * `\r\n` was worse: nextCluster() hands it back as ONE 0-width grapheme, so
 * it was glued into the running word — the CR leaked into the output and
 * the column count never reset across the break.
 */
final class WidthWrapAnsiHardBreakTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function hardBreakCases(): iterable
    {
        yield 'last word before LF moves down' => ["aaaa bbbb x\ny", 10, "aaaa bbbb\nx\ny"];
        yield 'last word before CRLF moves down' => ["aaaa bbbb x\r\ny", 10, "aaaa bbbb\nx\ny"];
        yield 'CRLF resets the column count' => ["aaaa\r\nbbbb cc", 9, "aaaa\nbbbb cc"];
        yield 'blank lines from CRLF survive' => ["a\r\n\r\nb", 5, "a\n\nb"];
        yield 'SGR span crossing the break' => [
            "\x1b[31maaaa bbbb x\x1b[0m\ny",
            10,
            "\x1b[31maaaa bbbb\nx\x1b[0m\ny",
        ];
        yield 'SGR opened on the word that moves' => [
            "aaaa bbbb \x1b[32mx\x1b[0m\ny",
            10,
            "aaaa bbbb\n\x1b[32mx\x1b[0m\ny",
        ];
        yield 'wide chars before LF' => ["文文 文文 x\ny", 10, "文文 文文\nx\ny"];
        yield 'wide char is the word that moves' => ["aaaa bbbb 文\nz", 10, "aaaa bbbb\n文\nz"];
    }

    #[DataProvider('hardBreakCases')]
    public function testHardBreakNeverLeavesAnOverWideLine(string $in, int $max, string $expected): void
    {
        $out = Width::wrapAnsi($in, $max);

        $this->assertSame($expected, $out);
        $this->assertLinesFit($out, $max);
    }

    public function testHardBreakWrapsLikeTheSameTextWithoutTheTail(): void
    {
        $withTail = explode("\n", Width::wrapAnsi("aaaa bbbb x\ny", 10));
        $without = explode("\n", Width::wrapAnsi('aaaa bbbb x', 10));

        $this->assertSame($without, \array_slice($withTail, 0, \count($without)));
    }

    public function testCrlfAgreesWithWrap(): void
    {
        $in = "aaaa bbbb x\r\nyy zz\r\n\r\nw";

        $this->assertSame(Width::wrap($in, 10), Width::wrapAnsi($in, 10));
    }

    public function testOverWideClusterBranchChecksTheLineToo(): void
    {
        // A leading space leaves the line non-empty at width 1; the word
        // `b` was then appended unconditionally before `文` was hard-broken.
        $this->assertLinesFit(Width::wrapAnsi(' b文', 1), 1);
    }

    /**
     * The invariant itself, over a deterministic spread of inputs mixing
     * hard breaks, CRLF, tabs, SGR/OSC spans and wide glyphs.
     */
    public function testNoLineExceedsTheBudget(): void
    {
        $alphabet = ['a', 'b', 'c', ' ', ' ', "\n", "\r\n", "\t", '文', 'é', "\x1b[31m", "\x1b[0m", "\x1b]8;;http://x\x07"];
        mt_srand(4242);
        for ($n = 0; $n < 3000; $n++) {
            $in = '';
            for ($k = mt_rand(1, 16); $k > 0; $k--) {
                $in .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
            }
            $max = mt_rand(1, 8);
            $out = Width::wrapAnsi($in, $max);

            $this->assertLinesFit($out, $max, json_encode([$in, $max]) ?: '');
            $this->assertSame(
                preg_replace('/\s+/u', '', Ansi::strip($in)),
                preg_replace('/\s+/u', '', Ansi::strip($out)),
                'wrapAnsi() lost or reordered visible content for ' . json_encode([$in, $max]),
            );
        }
        mt_srand();
    }

    private function assertLinesFit(string $out, int $max, string $context = ''): void
    {
        foreach (explode("\n", $out) as $line) {
            $this->assertStringNotContainsString("\r", $line, "CR leaked into wrapped output {$context}");
            $plain = Ansi::strip($line);
            if (mb_strlen($plain) <= 1) {
                // A lone glyph wider than the budget is emitted on its own
                // over-wide row by design; there is nothing to break. (Every
                // over-wide glyph these inputs use is a single codepoint.)
                continue;
            }
            $this->assertLessThanOrEqual(
                $max,
                Width::string($line),
                'wrapAnsi() returned ' . json_encode($line) . " wider than {$max} {$context}",
            );
        }
    }
}
