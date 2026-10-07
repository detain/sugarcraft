<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Fuzzy\Matcher\CharFold;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Regression family for MAJOR-1 (audit 2026-09-30): matchedIndices must address
 * the ORIGINAL haystack code points, never the lowercased comparison space.
 *
 * Whole-string `mb_strtolower` expands U+0130 (İ → i + U+0307) on every PHP 8.3
 * box (BMP+SMP census — İ is the ONLY expansion), which made the lowercased split
 * one element longer than the original and desynced every index after it. The
 * fix (CharFold) folds per code point, keeping the comparison arrays 1:1 with
 * the original. These pins characterize the fixed semantics, including the two
 * accepted deviations: 'i' does not match 'İ', and a decomposed "i+U+0307"
 * query does not match a composed 'İ' candidate (old code "matched" by shifting
 * all later indices — strictly worse than an honest non-match).
 */
#[CoversClass(CharFold::class)]
#[CoversClass(SmithWatermanMatcher::class)]
#[CoversClass(SahilmMatcher::class)]
#[CoversClass(Highlighter::class)]
final class CodePointExpansionTest extends TestCase
{
    private const ISTANBUL = "\u{0130}stanbul";

    private SmithWatermanMatcher $sw;

    private SahilmMatcher $sm;

    protected function setUp(): void
    {
        $this->sw = new SmithWatermanMatcher();
        $this->sm = new SahilmMatcher();
    }

    /** foldSplit must return exactly one element per original code point. */
    #[Test]
    public function testFoldSplitStaysOneToOneWithCodePoints(): void
    {
        $strings = [self::ISTANBUL, "İ1\u{1E9E}ßﬁǰıΣς中文", 'plain ASCII', '', "i\u{0307}stanbul",
            // 4-byte SMP code points (audit 2026-10-07 test gap): mb_str_split and
            // the per-char fold must stay 1:1 across the astral plane too.
            "\u{1F600}abc\u{1F4A9}", "\u{1F600}\u{1F600}\u{1F600}",
            // Malformed UTF-8: one element per invalid byte, folded to '?'.
            "\xFF" . 'ab', "\xFF\xFE",];
        foreach ($strings as $s) {
            $this->assertCount(mb_strlen($s, 'UTF-8'), CharFold::foldSplit($s), "1:1 broken for " . json_encode($s));
        }

        // The expansion itself: İ folds to TWO code points in ONE element.
        $folded = CharFold::foldSplit(self::ISTANBUL);
        $this->assertSame("i\u{0307}", $folded[0]);
        $this->assertCount(8, $folded);
    }

    #[Test]
    public function testSmithWatermanIndicesAddressOriginalCharsAfterExpansion(): void
    {
        // 'ist' vs 'İstanbul': no bare 'i' exists after the honest fold, so the
        // best alignment is the contiguous 's','t' run at ORIGINAL indices 1,2.
        // Pre-fix this returned [0, 2] — styling 'İ' and 't' for query "ist".
        $result = $this->sw->match('ist', self::ISTANBUL);

        $this->assertNotNull($result);
        $this->assertSame(11, $result->score);
        $this->assertSame([1, 2], $result->indices());
        $orig = mb_str_split(self::ISTANBUL);
        $this->assertSame('s', $orig[1]);
        $this->assertSame('t', $orig[2]);
    }

    #[Test]
    public function testSahilmIndicesAddressOriginalCharsAfterExpansion(): void
    {
        $result = $this->sm->match('sta', self::ISTANBUL);

        $this->assertNotNull($result);
        $this->assertSame(26, $result->score);
        $this->assertSame([1, 2, 3], $result->indices());
        $this->assertSame(['s', 't', 'a'], array_map(static fn(int $i): string => mb_str_split(self::ISTANBUL)[$i], [1, 2, 3]));
    }

    #[Test]
    public function testSahilmTailCharsReachableAfterExpansion(): void
    {
        // Pre-fix the candidate's lowercased split was 9 long but the scan bound
        // was the original 8, so the trailing 'l' was never reachable: [5,6] with
        // the loop dying before the final query char → whole match LOST (null).
        $result = $this->sm->match('bul', self::ISTANBUL);

        $this->assertNotNull($result);
        $this->assertSame([5, 6, 7], $result->indices());
        $this->assertSame(16, $result->score);
    }

    #[Test]
    public function testExpandedCharQueryMatchesSameExpandedChar(): void
    {
        // Whole-element fold equality: 'İ' query matches the 'İ' candidate char
        // (both fold to the identical 2-code-point string), all indices in order.
        $swResult = $this->sw->match(self::ISTANBUL, self::ISTANBUL);
        $smResult = $this->sm->match(self::ISTANBUL, self::ISTANBUL);

        $this->assertNotNull($swResult);
        $this->assertSame(59, $swResult->score);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], $swResult->indices());
        $this->assertNotNull($smResult);
        $this->assertSame(75, $smResult->score);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], $smResult->indices());
    }

    #[Test]
    public function testPlainISmallRTurkishIsHonestNonMatchAndStaysInRange(): void
    {
        // Accepted deviation (documented in CharFold): 'i' does NOT match 'İ'.
        // Smith-Waterman still finds the real suffix alignment with truthful indices.
        $result = $this->sw->match('istanbul', self::ISTANBUL);

        $this->assertNotNull($result);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $result->indices());
        $this->assertNull($this->sm->match('istanbul', self::ISTANBUL));

        // Decomposed query must not fake-match the composed candidate either.
        $this->assertNull($this->sw->match("i\u{0307}", self::ISTANBUL));
        $this->assertNull($this->sm->match("i\u{0307}", self::ISTANBUL));
    }

    #[Test]
    public function testHighlighterRoundTripStylesExactlyTheMatchedChars(): void
    {
        // The production shape (sugar-crush Renderer): MatchResult → Highlighter.
        // Pre-fix the round trip produced "[İ]s[ta]nbul" for query 'ist'.
        $highlighter = new Highlighter();

        $swOut = $highlighter->highlight($this->sw->match('ist', self::ISTANBUL), static fn(string $m): string => "[$m]");
        $smOut = $highlighter->highlight($this->sm->match('sta', self::ISTANBUL), static fn(string $m): string => "[$m]");

        $this->assertSame('İ[st]anbul', $swOut);
        $this->assertSame('İ[sta]nbul', $smOut);
    }

    // ── 4-byte SMP coverage (audit 2026-10-07 test gap #1) ────────────────────
    // The matrix sweep above proves index honesty across pairings; these pins
    // characterize the exact production shapes: an emoji is ONE index slot even
    // though it occupies FOUR bytes, and the highlighter round-trips it whole.

    #[Test]
    public function testSmpEmojiQueryMatchesItselfInBothMatchersAtCodePointIndex(): void
    {
        $emoji = "\u{1F600}"; // 4 bytes in UTF-8, 1 code point

        $swResult = $this->sw->match($emoji, $emoji . 'abc');
        $smResult = $this->sm->match($emoji, $emoji . 'abc');

        $this->assertNotNull($swResult);
        $this->assertSame([0], $swResult->indices(), 'SW must address the emoji as code point 0, not byte 0-3');
        $this->assertNotNull($smResult);
        $this->assertSame([0], $smResult->indices());
    }

    #[Test]
    public function testSmpCharBeforeQueryKeepsAsciiIndicesInCodePointSpace(): void
    {
        $haystack = "\u{1F600}" . 'abc';

        // A byte-indexed implementation would report 4/5/6 here; code point
        // indexing must report 1/2/3 with the emoji counted as a single slot.
        $this->assertSame([1], $this->sw->match('a', $haystack)?->indices());
        $this->assertSame([3], $this->sm->match('c', $haystack)?->indices());
        $this->assertSame([0, 3], $this->sw->match("\u{1F600}c", $haystack)?->indices());

        // And trailing: Sahilm scanning past an astral char at the end.
        $this->assertSame([2], $this->sm->match('z', 'abz' . "\u{1F600}")?->indices());
    }

    #[Test]
    public function testSmpHighlighterRoundTripWrapsTheWholeEmoji(): void
    {
        $emoji = "\u{1F600}";
        $highlighter = new Highlighter();

        $selfOut = $highlighter->highlight($this->sw->match($emoji, $emoji . 'abc'), static fn(string $m): string => "[$m]");
        $afterOut = $highlighter->highlight($this->sw->match('a', $emoji . 'abc'), static fn(string $m): string => "[$m]");
        $mixedOut = $highlighter->highlight($this->sw->match($emoji . 'c', $emoji . 'abc'), static fn(string $m): string => "[$m]");

        $this->assertSame('[' . $emoji . ']abc', $selfOut, 'emoji must survive the run splice whole');
        $this->assertSame($emoji . '[a]bc', $afterOut);
        $this->assertSame('[' . $emoji . ']ab[c]', $mixedOut);
    }

    // ── Malformed UTF-8 fold semantics (audit 2026-10-07 ruling #2) ──────────
    // Documenting the ACCEPTED behaviour, not fixing it: an invalid byte folds
    // to a literal '?' yet stays 1:1, so indices never desync (see CharFold
    // class docblock). If a future change breaks the count or the alignment,
    // this pin goes red.

    #[Test]
    public function testMalformedByteFoldsToQuestionMarkWithoutDesync(): void
    {
        $bad = "\xFF" . 'ab';

        // One element per (invalid) byte, bad byte folded to '?' — the 1:1
        // index contract holds even on corrupt input.
        $this->assertSame(['?', 'a', 'b'], CharFold::foldSplit($bad));
        $this->assertSame('?', CharFold::fold("\xFF"));

        // Matching past the bad byte stays aligned on the ORIGINAL code points.
        $result = $this->sw->match('ab', $bad);
        $this->assertNotNull($result);
        $this->assertSame([1, 2], $result->indices());
        $this->assertSame($bad, $result->haystack, 'result keeps the raw bytes even though the fold shows ?');

        // Accepted consequence of the fold: a literal '?' query matches the bad byte.
        $this->assertNotNull($this->sw->match('?', $bad));
    }

    /**
     * Deterministic sweep over case-expansion-relevant code points: every index
     * any matcher reports must (a) be in range of the original haystack and
     * (b) name a char whose fold appears in the query's folded elements.
     *
     * @return array<string, array{query: string, candidate: string}>
     */
    public static function expansionMatrix(): array
    {
        // U+1F600 (4-byte UTF-8) joins the sweep so every pairing exercises a
        // Supplementary-Multilingual-Plane char through BOTH matcher styles
        // (audit 2026-10-07 gap: coverage was BMP-only, so a byte-vs-code-point
        // index error astral of U+FFFF had no test to hide from).
        $chars = ["\u{0130}", "\u{1E9E}", "\u{00DF}", "\u{FB01}", "\u{01F0}", "\u{0131}", "\u{03A3}", "\u{03C2}", 'I', 'i', '中', "\u{1F600}"];
        $cases = [];
        foreach ($chars as $a) {
            foreach ($chars as $b) {
                $candidate = $a . $b . 'x' . "\u{0130}" . 'YZ İstanbul test';
                foreach ([$a . 'x', $b . 'İst', 'test', "\u{0130}ST", $a . $b] as $i => $query) {
                    $cases[bin2hex($a) . '-' . bin2hex($b) . "-$i"] = ['query' => $query, 'candidate' => $candidate];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('expansionMatrix')]
    public function testEveryReportedIndexNamesAGenuinelyMatchedOriginalChar(string $query, string $candidate): void
    {
        $orig = mb_str_split($candidate);
        $queryFolds = array_flip(CharFold::foldSplit($query));

        foreach ([$this->sw, $this->sm] as $matcher) {
            $result = $matcher->match($query, $candidate);
            if ($result === null) {
                continue;
            }

            $this->assertGreaterThan(0, $result->score);
            foreach ($result->indices() as $index) {
                $this->assertGreaterThanOrEqual(0, $index, 'negative index escaped the matcher');
                $this->assertLessThan(count($orig), $index, "index $index out of range for " . json_encode($candidate));
                $this->assertArrayHasKey(
                    CharFold::fold($orig[$index]),
                    $queryFolds,
                    'index ' . json_encode($orig[$index]) . ' names a char the query never asked for (' . get_class($matcher) . ')'
                );
            }

            // Sahilm matches every query char exactly once, in order.
            if ($matcher === $this->sm) {
                $this->assertCount(mb_strlen($query, 'UTF-8'), $result->indices());
                $foldedQuery = CharFold::foldSplit($query);
                foreach ($result->indices() as $k => $index) {
                    $this->assertSame($foldedQuery[$k], CharFold::fold($orig[$index]), 'Sahilm index/order misaligned with query char');
                }
            }
        }
    }
}
