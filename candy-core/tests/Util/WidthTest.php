<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class WidthTest extends TestCase
{
    public function testAsciiWidth(): void
    {
        $this->assertSame(11, Width::string('hello world'));
    }

    public function testStripsAnsiBeforeMeasuring(): void
    {
        $this->assertSame(5, Width::string("\x1b[31mhello\x1b[0m"));
    }

    public function testEmpty(): void
    {
        $this->assertSame(0, Width::string(''));
    }

    public function testCjkWideEachCounts2(): void
    {
        $this->assertSame(4, Width::string('日本'));
    }

    public function testEmojiCounts2(): void
    {
        $this->assertSame(2, Width::string('🎉'));
    }

    public function testOfHandlesZwjFamilyEmoji(): void
    {
        $this->assertSame(2, Width::of("👨‍👩‍👧‍👦"));
    }

    public function testZeroWidthJoinerInvisible(): void
    {
        $this->assertSame(0, Width::string("\u{200b}"));
    }

    public function testCombiningMarkInvisible(): void
    {
        $this->assertSame(1, Width::string("e\u{0301}"));
    }

    public function testTruncate(): void
    {
        $this->assertSame('hello', Width::truncate('hello world', 5));
    }

    public function testTruncateRespectsWideChars(): void
    {
        $this->assertSame('日', Width::truncate('日本', 3));
    }

    public function testTruncateZero(): void
    {
        $this->assertSame('', Width::truncate('hello', 0));
    }

    public function testTruncateAnsiPreservesEscapes(): void
    {
        $out = Width::truncateAnsi("\x1b[31mhello\x1b[0m", 3);
        $this->assertSame("\x1b[31mhel\x1b[0m", $out);
    }

    public function testTruncateAnsiRespectsWideChars(): void
    {
        $out = Width::truncateAnsi("\x1b[31m日本\x1b[0m", 3);
        // '日' uses 2 cells; '本' would need 4 → drop, keep trailing ANSI.
        $this->assertSame("\x1b[31m日\x1b[0m", $out);
    }

    public function testTruncateAnsiZero(): void
    {
        $this->assertSame('', Width::truncateAnsi("\x1b[31mhi\x1b[0m", 0));
    }

    public function testPadRight(): void
    {
        $this->assertSame('hi   ', Width::padRight('hi', 5));
        $this->assertSame('hello', Width::padRight('hello', 5));
        $this->assertSame('hello', Width::padRight('hello', 3));
        $this->assertSame('hi***', Width::padRight('hi', 5, '*'));
    }

    public function testPadLeft(): void
    {
        $this->assertSame('   hi', Width::padLeft('hi', 5));
        $this->assertSame('00042', Width::padLeft('42', 5, '0'));
    }

    public function testPadCenter(): void
    {
        $this->assertSame(' hi  ', Width::padCenter('hi', 5));
        $this->assertSame('  hi  ', Width::padCenter('hi', 6));
    }

    public function testPadIgnoresAnsi(): void
    {
        $padded = Width::padRight("\x1b[31mhi\x1b[0m", 5);
        $this->assertSame("\x1b[31mhi\x1b[0m   ", $padded);
        $this->assertSame(5, Width::string($padded));
    }

    public function testWrapShortText(): void
    {
        $this->assertSame('hello', Width::wrap('hello', 10));
    }

    public function testWrapBreaksOnSpaces(): void
    {
        $this->assertSame("hello\nworld", Width::wrap('hello world', 5));
    }

    public function testWrapHonorsExistingNewlines(): void
    {
        $this->assertSame("a\nb", Width::wrap("a\nb", 80));
    }

    public function testWrapBreaksLongWord(): void
    {
        $this->assertSame("abcd\nefgh\ni", Width::wrap('abcdefghi', 4));
    }

    public function testWrapZeroOrNegativeReturnsInput(): void
    {
        $this->assertSame('hello world', Width::wrap('hello world', 0));
        $this->assertSame('hello world', Width::wrap('hello world', -1));
    }

    public function testWrapMultipleWordsAcrossLines(): void
    {
        $out = Width::wrap('the quick brown fox jumps over the lazy dog', 12);
        $this->assertSame("the quick\nbrown fox\njumps over\nthe lazy dog", $out);
    }

    public function testWrapAnsiPreservesStyling(): void
    {
        $out = Width::wrapAnsi("\x1b[31mhello\x1b[0m world", 5);
        $this->assertSame("\x1b[31mhello\x1b[0m\nworld", $out);
    }

    public function testTruncateMiddleShortStringUnchanged(): void
    {
        $this->assertSame('short', Width::truncateMiddle('short', 10));
    }

    public function testTruncateMiddleKeepsBothEnds(): void
    {
        // "abcdefghij" (10) into 7 cells: budget 6, head 3, tail 3 → "abc…hij".
        $this->assertSame('abc…hij', Width::truncateMiddle('abcdefghij', 7));
    }

    public function testTruncateMiddleResultFitsWidth(): void
    {
        $out = Width::truncateMiddle('/var/lib/mysql/data/very/deep/path.db', 20);
        $this->assertLessThanOrEqual(20, Width::string($out));
        $this->assertStringContainsString('…', $out);
    }

    public function testTruncateMiddleStripsAnsi(): void
    {
        $this->assertSame('hello', Width::truncateMiddle("\x1b[31mhello\x1b[0m", 10));
    }

    public function testTruncateMiddleZeroWidth(): void
    {
        $this->assertSame('', Width::truncateMiddle('anything', 0));
    }

    public function testTruncateMiddleEllipsisWiderThanMaxFallsBack(): void
    {
        // max smaller than the ellipsis → plain head-truncate, still fits.
        $out = Width::truncateMiddle('abcdef', 1, '...');
        $this->assertLessThanOrEqual(1, Width::string($out));
    }

    public function testStringMemoRepeatedInputReturnsIdentical(): void
    {
        $s = "\x1b[31mhello 日本\x1b[0m";
        $first  = Width::string($s);       // cold: computes + memoizes
        $second = Width::string($s);       // warm: memo hit
        $this->assertSame($first, $second);
        // "hello" (5) + " " (1) + 日 (2) + 本 (2) = 10.
        $this->assertSame(10, $second);
    }

    /**
     * The static memo behind {@see Width::string()} must never grow past its
     * cap, and every result (fresh, memo-hit, or evicted-and-recomputed) must
     * equal the uncached width. Reverting the cap makes the memo unbounded and
     * fails the size assertion.
     */
    public function testStringMemoStaysBoundedAndCorrect(): void
    {
        $memoProp = new \ReflectionProperty(Width::class, 'memo');
        $memoProp->setAccessible(true);
        $memoProp->setValue(null, []); // clean baseline for a precise size check

        $cap = (new \ReflectionClassConstant(Width::class, 'MEMO_MAX'))->getValue();
        $this->assertIsInt($cap);

        // Feed more distinct strings than the cap; each is pure ASCII so its
        // display width equals its byte length.
        for ($i = 0; $i < $cap + 500; $i++) {
            $s = 'row-' . $i;
            $this->assertSame(\strlen($s), Width::string($s));
        }

        $memo = $memoProp->getValue();
        $this->assertIsArray($memo);
        $this->assertGreaterThan(0, \count($memo), 'memo should be populated');
        $this->assertLessThanOrEqual($cap, \count($memo), 'memo must stay bounded');

        $memoProp->setValue(null, []); // don't leak state into sibling tests
    }

    public function testDropAnsiDropsPrefixCells(): void
    {
        // "hello" = 5 cells, drop first 3 → "lo" (ANSI-stripped)
        $this->assertSame('lo', Width::dropAnsi('hello', 3));
    }

    public function testDropAnsiPreservesTrailingAnsi(): void
    {
        // Drop 3 cells from colored text, trailing color reset preserved.
        $s = "\x1b[31mhello\x1b[0m";
        $out = Width::dropAnsi($s, 3);
        $this->assertSame("\x1b[31mlo\x1b[0m", $out);
    }

    public function testDropAnsiZeroReturnsFull(): void
    {
        $this->assertSame('hello', Width::dropAnsi('hello', 0));
    }

    public function testDropAnsiNegativeReturnsFull(): void
    {
        // Negative skip is treated as 0 (early-exit guard)
        $this->assertSame('hello', Width::dropAnsi('hello', -5));
    }

    public function testDropAnsiDropsWideCharWhole(): void
    {
        // "日本" = 4 cells, "日" = 2 cells wide. Dropping 1 cell (landing mid-grapheme)
        // should drop the whole wide cluster, leaving only "本".
        $out = Width::dropAnsi('日本', 1);
        $this->assertSame('本', $out);
    }

    public function testDropAnsiPreservesAnsiInDroppedRegion(): void
    {
        // ANSI in the dropped prefix is preserved in output prefix.
        $s = "he\x1b[31mllo\x1b[0m";
        $out = Width::dropAnsi($s, 2);
        // ANSI sequences from dropped region appear in output
        $this->assertSame("\x1b[31mllo\x1b[0m", $out);
    }

    public function testTakeAnsiTakesPrefixCells(): void
    {
        // "hello" = 5 cells, take first 3 → "hel"
        $this->assertSame('hel', Width::takeAnsi('hello', 3));
    }

    public function testTakeAnsiPreservesAnsiInRegion(): void
    {
        // Take 2 cells from colored text, color sequences preserved.
        $s = "\x1b[31mhello\x1b[0m";
        $out = Width::takeAnsi($s, 2);
        $this->assertSame("\x1b[31mhe\x1b[0m", $out);
    }

    public function testTakeAnsiZeroReturnsEmpty(): void
    {
        $this->assertSame('', Width::takeAnsi('hello', 0));
    }

    public function testTakeAnsiNegativeReturnsEmpty(): void
    {
        // Negative take is treated as 0 (early-exit guard)
        $this->assertSame('', Width::takeAnsi('hello', -5));
    }

    public function testTakeAnsiIncludesWideCharWhole(): void
    {
        // "日本" = 4 cells, taking 1 cell (landing mid-grapheme) should include
        // the whole wide cluster, giving "日" (2 cells).
        $out = Width::takeAnsi('日本', 1);
        $this->assertSame('日', $out);
    }

    public function testTakeAnsiPreservesTrailingAnsi(): void
    {
        // When take lands inside a wide grapheme, trailing ANSI after the
        // cut point should still be captured.
        $s = "日\x1b[31m本\x1b[0m";
        $out = Width::takeAnsi($s, 1);
        // "日" is taken (wide char), trailing sequences preserved
        $this->assertStringContainsString('日', $out);
    }

    public function testDropAnsiEmptyString(): void
    {
        $this->assertSame('', Width::dropAnsi('', 5));
    }

    public function testTakeAnsiEmptyString(): void
    {
        $this->assertSame('', Width::takeAnsi('', 5));
    }

    /**
     * E68, minimised. `truncateAnsi()` is a budget, and its result measured by
     * `string()` — the measure every caller in the repo clamps with — must
     * never exceed it.
     *
     * The specific input is the one recorded in the finding: THUMBS UP SIGN
     * followed by EMOJI MODIFIER FITZPATRICK TYPE-4, then `xy`, at budget 3.
     * It used to return 5 cells.
     */
    public function testTruncateAnsiNeverReturnsMoreCellsThanItsBudget(): void
    {
        $this->assertSame(3, Width::string(Width::truncateAnsi("\u{1F44D}\u{1F3FD}xy", 3)));

        // Cluster shapes on which the two segmentations used to disagree:
        // emoji + skin-tone modifier, regional-indicator pair (a flag),
        // ZWJ sequence, variation selector, and a combining mark.
        $fixtures = [
            "\u{1F44D}\u{1F3FD}xy",
            "\u{1F1E6}\u{1F1F8}xy",
            "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}xy",
            "\u{2600}\u{FE0F}xy",
            "e\u{0301}xy",
            "\u{1F3FD}\u{1F1E6}\u{0301}",
            "\x1b[31m\u{1F44D}\u{1F3FD}\x1b[0mxy",
            '日本語abc',
        ];
        foreach ($fixtures as $s) {
            for ($budget = 1; $budget <= 8; $budget++) {
                $this->assertLessThanOrEqual(
                    $budget,
                    Width::string(Width::truncateAnsi($s, $budget)),
                    sprintf('truncateAnsi(%s, %d) came back over budget', bin2hex($s), $budget),
                );
            }
        }
    }

    /**
     * E69, third measure. `wrapAnsi()` had its own tab rule — it charged a
     * `\t` **1** cell in its whitespace branch, where `string()` charged 0 and
     * `Style::render()` laid out {@see Width::TAB_WIDTH}. Three answers for one
     * character. It now charges what `graphemeWidth()` says, so a wrapped line
     * cannot come back wider than the column it was wrapped to.
     *
     * `wrapAnsi("ab\tcd", 7)` is the reproduction: at a 1-cell charge the whole
     * string scored 5 against a budget of 7 and came back as ONE line of 8
     * cells. At `TAB_WIDTH` it breaks, and both lines are 2 cells.
     */
    /**
     * The tab charge is FOUR, asserted as a literal, in the library that owns
     * the constant.
     *
     * Every other tab assertion added in round 40 — here and in
     * candy-sprinkles' StyleTest — is written in terms of `Width::TAB_WIDTH`,
     * so all of them agree with each other for any value the constant takes.
     * MEASURED: `TAB_WIDTH = 4` -> `= 8` was killed ONLY by a pre-existing
     * candy-sprinkles test (`testUnsetTabWidthRevertsToFour`), which means
     * candy-core's own suite pinned nothing about the value and the constant
     * could have drifted with this lib green. A shared constant makes two
     * measures AGREE; it does not make either of them RIGHT.
     *
     * Four is not arbitrary: it is what `Style::render()` has always expanded a
     * tab to, so changing it changes rendered output everywhere.
     */
    public function testATabIsChargedExactlyFourCells(): void
    {
        self::assertSame(4, Width::TAB_WIDTH);
        self::assertSame(4, Width::string("\t"));
        self::assertSame(8, Width::string("\t\t"));
        self::assertSame(5, Width::string("a\t"));
    }

    public function testWrapAnsiChargesATabTheSameCellsAsEveryOtherMeasure(): void
    {
        $lines = explode("\n", Width::wrapAnsi("ab\tcd", 7));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(7, Width::string($line), 'wrapAnsi returned a line over its column');
        }
        $this->assertCount(2, $lines);
    }

    /**
     * The reason E68 existed: `string()` and every truncation path have to
     * agree on where one grapheme ends and the next begins. They did not —
     * `string()` split per codepoint on PHP 8.3 (its preferred
     * `grapheme_str_split()` is 8.4+) while `truncateAnsi()` split per
     * cluster. This pins the agreement itself, so a future edit that gives
     * either side its own splitter fails here rather than in a pane.
     */
    public function testStringScoresAWholeClusterAsOneGlyph(): void
    {
        // Emoji + skin-tone modifier is ONE glyph in ONE 2-cell box; the
        // per-codepoint sum used to score it 4.
        $this->assertSame(2, Width::string("\u{1F44D}\u{1F3FD}"));
        // A regional-indicator pair is one flag in 2 cells; a lone regional
        // indicator is a 1-cell letter box.
        $this->assertSame(2, Width::string("\u{1F1E6}\u{1F1F8}"));
        $this->assertSame(1, Width::string("\u{1F1E6}"));
        // WHAT THIS SAID: "already true before the fix, via a ZWJ special
        // case — pinned here so that special case cannot be dropped
        // silently." WHAT IS TRUE NOW: the special case is gone (E73) and
        // this assertion holds without it, because ICU returns the whole
        // family as ONE cluster whose base is U+1F468. WHY THE ASSERTION
        // STILL EARNS ITS PLACE: it is the check that a ZWJ sequence is
        // scored once rather than per-emoji — which is what would break if
        // segmentation ever fell back to a per-codepoint splitter. Measured
        // on PHP 8.3.6, ext-intl ICU 74.2 / Unicode 15.1: 1 cluster, 2 cells.
        $this->assertSame(1, \count(self::icuClusters("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}")));
        $this->assertSame(2, Width::of("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}"));
        // Truncation and measurement must charge the same cluster the same
        // number of cells, which is the invariant, stated directly.
        foreach (["\u{1F44D}\u{1F3FD}", "\u{1F1E6}\u{1F1F8}", "\u{2600}\u{FE0F}", "e\u{0301}", '日'] as $cluster) {
            $w = Width::string($cluster);
            $this->assertSame(
                $cluster,
                Width::truncateAnsi($cluster, $w),
                sprintf('%s did not survive truncation at its own width %d', bin2hex($cluster), $w),
            );
            $this->assertSame(
                '',
                Width::truncateAnsi($cluster, $w - 1),
                sprintf('%s was emitted whole into a budget of %d', bin2hex($cluster), $w - 1),
            );
        }
    }

    /**
     * E73. A Control character immediately before a ZWJ made
     * `Width::string()` score the whole run 0.
     *
     * Mechanism, measured on PHP 8.3.6 with ext-intl ICU 74.2 / Unicode 15.1:
     * UAX #29 GB4 breaks after a Control, so `"\t" ZWJ U+1F44D` comes back as
     * THREE clusters — the tab, a bare ZWJ, and the emoji — where a joining
     * ZWJ would have been inside one. `compute()`'s old look-ahead saw the
     * next cluster was a ZWJ and charged the tab 0; the `$inZwjSequence` flag
     * it then set charged the emoji 0 as well. Total 0 cells for a run
     * `Style::new()->render()` lays out as 6.
     *
     * This is the OVER-RUN direction — the caller is told a 6-cell run is
     * free — and an over-wide row is frame corruption here, because the diff
     * renderer paints one line per terminal row.
     *
     * Exact expected values, not bounds, so the sign of any future
     * disagreement is visible: {@see WidthTest::testATabIsChargedExactlyFourCells}
     * pins TAB_WIDTH = 4 as a literal in this same file.
     */
    public function testAControlBeforeAZwjNoLongerZeroesTheRunAroundIt(): void
    {
        // The two inputs recorded in the finding.
        $this->assertSame(Width::TAB_WIDTH + 2, Width::string("\t\u{200d}\u{1F44D}"));
        $this->assertSame(1 + Width::TAB_WIDTH + 2, Width::string("a\t\u{200d}\u{1F44D}"));

        // Same defect with no tab in sight: a ZWJ at the START of the string
        // is also a bare cluster, so the flag zeroed the emoji after it.
        $this->assertSame(2, Width::string("\u{200d}\u{1F44D}"));

        // Every C0/C1 control breaks the cluster the same way, so none of them
        // may zero their neighbours either.
        foreach (["\x01", "\x07", "\x0b", "\x7f", "\n"] as $control) {
            $this->assertSame(
                2,
                Width::string($control . "\u{200d}\u{1F44D}"),
                sprintf('control %s zeroed the emoji after a ZWJ', bin2hex($control)),
            );
        }

        // A ZWJ that actually joins is unaffected: one cluster, 2 cells.
        $this->assertSame(2, Width::string("\u{1F469}\u{200D}\u{1F4BB}"));
    }

    /**
     * The invariant E73's fix installs, stated as a property rather than as a
     * list of inputs: `string()` carries NO state across cluster boundaries,
     * so measuring a string equals the sum of measuring its clusters one at a
     * time.
     *
     * The old ZWJ look-ahead violated this by construction — it read
     * `$clusters[$i + 1]` — which is why a Control could change the score of
     * a cluster two positions away. Any future cross-cluster rule fails here.
     *
     * Corpus: 20,000 deterministic strings (seed 20260822, `mt_srand`) of 1-6
     * symbols over an alphabet of controls, ZWJ/ZWNJ, skin-tone modifiers,
     * regional indicators, Hangul jamo, Indic conjunct parts, combining
     * marks, variation selectors, CJK and ASCII. Segmentation oracle is
     * ext-intl's `grapheme_extract()`, i.e. the same ICU the class uses.
     */
    public function testMeasuringCarriesNoStateAcrossClusterBoundaries(): void
    {
        $mismatches = [];
        $checked = 0;
        foreach (self::widthFuzzCorpus(20000, 20260822) as $s) {
            $checked++;
            $sum = 0;
            foreach (self::icuClusters($s) as $cluster) {
                $sum += Width::string($cluster);
            }
            $whole = Width::string($s);
            if ($sum !== $whole && \count($mismatches) < 5) {
                $mismatches[] = sprintf('%s: clusters sum to %d, whole measures %d', bin2hex($s), $sum, $whole);
            }
        }
        // Aggregated rather than asserted per trial: 20,000 per-trial
        // assertions would swamp this lib's assertion count without adding
        // information, and the first five failures name their own input.
        $this->assertSame([], $mismatches, 'cross-cluster state moved a width');
        $this->assertSame(20000, $checked);
    }

    /**
     * No input may be measured NEGATIVE or measured 0 while containing a
     * cluster that is not zero-width — the shape E73 presented as.
     *
     * Same corpus as {@see self::testMeasuringCarriesNoStateAcrossClusterBoundaries()}.
     */
    public function testAnInputContainingANonZeroWidthClusterNeverMeasuresZero(): void
    {
        $swallowed = [];
        $checked = 0;
        foreach (self::widthFuzzCorpus(20000, 776611) as $s) {
            $checked++;
            $w = Width::string($s);
            $widest = 0;
            foreach (self::icuClusters($s) as $cluster) {
                $widest = \max($widest, Width::string($cluster));
            }
            if (($w < 0 || $w < $widest) && \count($swallowed) < 5) {
                $swallowed[] = sprintf('%s measured %d, widest cluster alone is %d', bin2hex($s), $w, $widest);
            }
        }
        $this->assertSame([], $swallowed, 'a string measured narrower than one of its own clusters');
        $this->assertSame(20000, $checked);
    }

    /**
     * `$count` deterministic random strings of 1-6 symbols drawn from
     * {@see self::widthFuzzAlphabet()}, seeded so a failure is reproducible.
     *
     * @return \Generator<int, string>
     */
    private static function widthFuzzCorpus(int $count, int $seed): \Generator
    {
        $alphabet = self::widthFuzzAlphabet();
        $n = \count($alphabet);
        \mt_srand($seed);
        for ($t = 0; $t < $count; $t++) {
            $len = 1 + \mt_rand(0, 5);
            $s = '';
            for ($k = 0; $k < $len; $k++) {
                $s .= $alphabet[\mt_rand(0, $n - 1)];
            }
            yield $s;
        }
    }

    /**
     * Emoji-heavy alphabet shared by the property tests above. Deliberately
     * includes the shapes each of E68, E69 and E73 turned on: controls
     * (cluster breakers), ZWJ/ZWNJ, skin-tone modifiers (Extend), regional
     * indicator pairs, Hangul jamo L/V/T, an Indic conjunct, a combining
     * mark, and both variation selectors.
     *
     * @return list<string>
     */
    private static function widthFuzzAlphabet(): array
    {
        return [
            'a', 'Z', ' ', "\t", "\x01", "\x07", "\x0b", "\x7f",
            "\u{200d}", "\u{200c}", "\u{200b}", "\u{feff}",
            "\u{1F44D}", "\u{1F469}", "\u{1F4BB}", "\u{1F3C3}", "\u{2600}",
            "\u{1F3FB}", "\u{1F3FD}", "\u{1F3FF}",
            "\u{1F1FA}", "\u{1F1F8}", "\u{1F1EF}", "\u{1F1F5}",
            "\u{1100}", "\u{1161}", "\u{11A8}",
            "\u{0915}", "\u{094D}", "\u{0937}",
            "\u{0301}", "\u{FE0F}", "\u{FE0E}", "\u{4E00}",
        ];
    }

    /**
     * ICU grapheme clusters of `$s`, via ext-intl — the same segmenter
     * `Width` walks, used here as an independent oracle rather than by
     * calling the class's own private splitter.
     *
     * @return list<string>
     */
    private static function icuClusters(string $s): array
    {
        $out = [];
        $i = 0;
        $len = \strlen($s);
        while ($i < $len) {
            $next = 0;
            $cluster = \grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            if (!\is_string($cluster) || $cluster === '') {
                $cluster = $s[$i];
            }
            $out[] = $cluster;
            $i += \strlen($cluster);
        }
        return $out;
    }
}
