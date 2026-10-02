<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Audit 15b-13: the raw token proxy counted codepoints/4, so a CJK or emoji
 * session estimated at a third to a sixth of what the provider counts, and
 * the E17 calibration (clamped to at most 3.0) could not close the gap — the
 * 85%/95% tiers fired after the provider had already overflowed.
 *
 * Driven through {@see Chat::contextTokens()} — the same estimate every tier,
 * the status bar and the dispatch-time E17 record read — so a regression in
 * the wiring, not only in {@see TokenEstimate}, goes red. Every expectation
 * adds Chat's 10-token per-message role overhead.
 */
final class TokenProxyScriptWeightTest extends TestCase
{
    private static function tokensFor(string $content): int
    {
        return (new Chat(history: [Message::user($content)]))->contextTokens();
    }

    public function testTenThousandCjkCharactersEstimateToAtLeastEightThousandTokens(): void
    {
        $tokens = self::tokensFor(str_repeat('漢字', 5_000));

        // Codepoints/4 read this as 2,510 — the bug.
        $this->assertGreaterThanOrEqual(8_000, $tokens);
        $this->assertSame(10_010, $tokens, 'one token per ideograph plus the role overhead');
    }

    public function testKanaAndHangulWeighLikeIdeographs(): void
    {
        $this->assertSame(1_010, self::tokensFor(str_repeat('こ', 500) . str_repeat('한', 500)));
    }

    public function testAsciiEstimatesAreExactlyTheOldFormula(): void
    {
        // ceil(4000 / 4) + 10 — the figure every ASCII-pinned suite relies on.
        $this->assertSame(1_010, self::tokensFor(str_repeat('a', 4_000)));
        $this->assertSame(12, self::tokensFor('hello'));
    }

    /** @return iterable<string, array{string}> */
    public static function latinText(): iterable
    {
        yield 'accented latin' => ['café naïve façade Ångström Łódź'];
        yield 'typographic punctuation' => ['it’s — “quoted” … done'];
        yield 'decomposed accent (NFD)' => ["cafe\u{0301} re\u{0301}sume\u{0301}"];
        yield 'box drawing' => ['┌──┐│  │└──┘'];
        yield 'code' => ['function f(array $x): int { return count($x) * 4; }'];
    }

    #[DataProvider('latinText')]
    public function testLatinAndPunctuationAreNotReweighted(string $text): void
    {
        $this->assertSame((int) ceil(mb_strlen($text) / 4) + 10, self::tokensFor($text));
    }

    public function testEmojiAreNotUnderCounted(): void
    {
        $tokens = self::tokensFor(str_repeat('😀', 1_000));

        // Codepoints/4 read this as 260; real vocabularies spend 1-3 apiece.
        $this->assertGreaterThanOrEqual(1_000 + 10, $tokens);
        $this->assertSame(2_010, $tokens, 'two tokens per supplementary-plane emoji');
    }

    public function testBmpPictographsAndEmojiGlueCountAsOneTokenEach(): void
    {
        // ✓ (Dingbats) and ⚠ (Misc Symbols) + VS16: 3 heavy codepoints, 1 space.
        $this->assertSame(4, TokenEstimate::ofText("✓ ⚠\u{FE0F}"));
    }

    public function testOtherAlphabeticScriptsWeighHalfATokenPerLetter(): void
    {
        // 200 Cyrillic letters at ½ — 100, not the old 50.
        $this->assertSame(110, self::tokensFor(str_repeat('ж', 200)));
    }

    public function testMixedTextSumsItsParts(): void
    {
        // "Hello " (6×¼) + 世界 (2×1) + " " (¼) + 😀 (2) = 5.75 → 6.
        $this->assertSame(6, TokenEstimate::ofText('Hello 世界 😀'));
        $this->assertSame(16, self::tokensFor('Hello 世界 😀'));

        $english = str_repeat('word ', 400);
        $chinese = str_repeat('中文', 200);
        $this->assertSame(
            TokenEstimate::ofText($english) + TokenEstimate::ofText($chinese),
            TokenEstimate::ofText($english . $chinese),
            'weights are per codepoint, so concatenation adds exactly',
        );
    }

    public function testInvalidUtf8FallsBackToBytesOverThreeNeverBelowTheOldFormula(): void
    {
        $text = "\xFF\xFE" . str_repeat('a', 100);

        $this->assertSame((int) ceil(strlen($text) / 3), TokenEstimate::ofText($text));
        $this->assertGreaterThanOrEqual((int) ceil(mb_strlen($text) / 4), TokenEstimate::ofText($text));
    }

    public function testEmptyTextIsZero(): void
    {
        $this->assertSame(0, TokenEstimate::ofText(''));
    }

    public function testUiOnlyCjkRowsStillOccupyNoneOfTheWindow(): void
    {
        $visible = new Chat(history: [Message::user('hi')]);
        $padded = new Chat(history: [
            Message::user('hi'),
            Message::assistant(str_repeat('漢', 40_000))->withUiOnly(),
            Message::notice(str_repeat('😀', 40_000)),
        ]);

        $this->assertSame($visible->contextTokens(), $padded->contextTokens());
    }

    public function testCalibrationPairsAgainstTheScriptWeightedRawProxy(): void
    {
        // Dispatch over 4,000 ideographs: raw 4,010. A provider count of 8,020
        // is exactly a 2.0 factor ONLY if the dispatch record used the same
        // weighted proxy; paired against codepoints/4 (1,010) the ratio is
        // 7.9 and the factor clamps to 3.0.
        $chat = new Chat(history: [Message::user(str_repeat('漢', 4_000))], backend: new EchoBackend());
        [$chat] = $chat->update(new KeyMsg(KeyType::Char, 'x'));
        [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        [$chat] = $chat->update(new AssistantMsg(
            Message::assistant('done')->withUsage(Usage::new(8_020, 0.001)),
        ));

        // (4,010 + 'x' 11 + 'done' 11) × 2.0
        $this->assertSame(8_064, $chat->contextTokens());
    }
}
