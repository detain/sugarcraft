<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * Parity for {@see Width::wrapWords()} against the two word-wrap copies it
 * replaces (AUD §10 F: "behaviour parity of the word-wrap replacements").
 *
 * The two reference functions below are verbatim algorithm copies of
 * candy-flappy `Renderer::foldNoticeLine()` and candy-minesweeper
 * `Renderer::wrapPlain()`. Those methods are private to other libraries, so
 * the oracle lives here. Fixtures are the inputs each copy actually receives,
 * plus the edges where a wrong port would show.
 */
final class WidthWrapWordsTest extends TestCase
{
    /** candy-flappy Renderer::foldNoticeLine(): fold without breaking words. */
    private static function flappyFoldReference(string $text, int $width): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $trial = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && Width::of($trial) > $width) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /** candy-minesweeper Renderer::wrapPlain(): greedy wrap, hard-breaking over-wide words. */
    private static function minesweeperWrapReference(string $text, int $width): array
    {
        $out = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/ +/', $paragraph) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                while (Width::of($word) > $width) {
                    if ($line !== '') {
                        $out[] = $line;
                        $line = '';
                    }
                    $chunk = '';
                    $rest = '';
                    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                        if ($rest === '' && ($chunk === '' || Width::of($chunk . $char) <= $width)) {
                            $chunk .= $char;
                        } else {
                            $rest .= $char;
                        }
                    }
                    $out[] = $chunk;
                    $word = $rest;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (Width::of($candidate) > $width && $line !== '') {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    /** @return iterable<string, array{string, int}> */
    public static function flappyFixtures(): iterable
    {
        yield 'short notice, wide box' => ['Press any key to continue', 38];
        yield 'short notice, narrow box' => ['Press any key to continue', 10];
        yield 'resize hint' => ['Terminal too small: resize to at least 80x24 to play', 20];
        yield 'resize hint at fold width' => ['Terminal too small: resize to at least 80x24 to play', 38];
        yield 'overlong word stands alone' => ['supercalifragilisticexpialidocious and more', 10];
        yield 'edge whitespace and runs' => ['  leading   and   trailing spaces  ', 12];
        yield 'tab separates words' => ["tab\tseparated words here", 8];
        yield 'cjk words' => ['中文 字符 测试 文本', 5];
        yield 'exact fit' => ['abc def', 7];
    }

    /** @return iterable<string, array{string, int}> */
    public static function minesweeperFixtures(): iterable
    {
        yield 'sentence' => ['the quick brown fox jumps over the lazy dog', 10];
        yield 'sentence wide' => ['the quick brown fox jumps over the lazy dog', 15];
        yield 'long word hard-broken' => ['supercalifragilisticexpialidocious', 10];
        yield 'long word after short' => ['a supercalifragilistic b', 8];
        yield 'hard newlines kept' => ["first line\nsecond line is longer than the width", 12];
        yield 'blank paragraphs' => ["\n\nblank paragraphs\n", 10];
        yield 'spaceless cjk toast' => ['保存失败：文件已被其他进程占用，请稍后重试', 20];
        yield 'empty input' => ['', 5];
        yield 'spaces only' => ['   ', 5];
        yield 'exact fit' => ['abcd efgh', 4];
    }

    #[DataProvider('flappyFixtures')]
    public function testMatchesFlappyFoldWithoutBreakingWords(string $text, int $width): void
    {
        $this->assertSame(
            self::flappyFoldReference($text, $width),
            Width::wrapWords($text, $width, breakWords: false),
        );
    }

    #[DataProvider('minesweeperFixtures')]
    public function testMatchesMinesweeperWrapWithBreakingWords(string $text, int $width): void
    {
        $this->assertSame(
            self::minesweeperWrapReference($text, $width),
            Width::wrapWords($text, $width, breakWords: true),
        );
    }

    public function testBreakingWordsIsTheDefault(): void
    {
        $this->assertSame(['abcd', 'efgh', 'i'], Width::wrapWords('abcdefghi', 4));
    }

    public function testHardBreakFlushesThePendingLineFirst(): void
    {
        $this->assertSame(['ab', 'cdef', 'gh'], Width::wrapWords('ab cdefgh', 4));
    }

    public function testWithoutBreakingAnOverlongWordStaysWhole(): void
    {
        $this->assertSame(['ab', 'abcdefgh', 'cd'], Width::wrapWords('ab abcdefgh cd', 4, breakWords: false));
    }

    public function testEachParagraphYieldsAtLeastOneLine(): void
    {
        $this->assertSame([''], Width::wrapWords('', 5));
        $this->assertSame(['', 'x'], Width::wrapWords("\nx", 5));
    }

    public function testAnsiIsStrippedBeforeWrapping(): void
    {
        $this->assertSame(['red blue'], Width::wrapWords("\x1b[31mred\x1b[0m blue", 10));
    }

    public function testGraphemeClustersAreNotSplitWhenBreaking(): void
    {
        // A ZWJ family is one grapheme of 2 cells. The minesweeper copy splits it
        // per codepoint; wrapWords keeps the cluster whole (a declared difference).
        $family = "\u{1f468}\u{200d}\u{1f469}\u{200d}\u{1f467}";
        $this->assertSame([$family, 'x'], Width::wrapWords($family . 'x', 2));
    }

    public function testRejectsNonPositiveWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Width::wrapWords('abc', 0);
    }

    public function testEveryLineFitsTheBudgetExceptOverWideSingleGraphemes(): void
    {
        $lines = Width::wrapWords('the quick brown fox 中文字符 jumps over the lazy dog', 7);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(7, Width::string($line), "line '$line' overflows");
        }
    }
}
