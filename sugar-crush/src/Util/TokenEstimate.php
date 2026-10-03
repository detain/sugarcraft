<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

/**
 * Script-weighted token estimate for one piece of message text — the
 * per-message content half of {@see \SugarCraft\Crush\Chat}'s raw token proxy
 * (Chat adds its own per-message role overhead on top).
 *
 * WHY NOT PLAIN CHARS/4: "one token ≈ four characters" is an English/code
 * figure. BPE vocabularies spend roughly one token per CJK ideograph, kana or
 * Hangul syllable and two or more per emoji, so a codepoints/4 proxy
 * underestimated a CJK or emoji session 3-6× (audit 15b-13), and the E17
 * calibration factor is clamped to at most 3.0, so even a calibrated session
 * crossed the 85%/95% tiers far too late and overflowed at the provider.
 *
 * THE WEIGHTS, in quarter-tokens per codepoint so the arithmetic stays
 * integral and the ASCII case is literally the old formula:
 *
 *  - 1 (¼ token): everything not listed below — ASCII, Latin letters with
 *    accents, punctuation such as `—` and `…`, box drawing. Text made only of
 *    these estimates EXACTLY as `ceil(mb_strlen / 4)` did, so every figure
 *    pinned against ASCII fixtures (the 53-token context reminder, the
 *    calibration suite's `hello` = 12) is unchanged.
 *  - 2 (½ token): letters and marks of other alphabetic scripts — Cyrillic,
 *    Greek, Arabic, Hebrew, Thai, Devanagari… These run ~2 characters per
 *    token on common vocabularies; ½ is a middle figure that removes most of
 *    the under-count without pretending to be a tokenizer. Combining marks of
 *    the Inherited script (a decomposed `é`) stay at ¼ so Latin text written
 *    in NFD does not drift.
 *  - 4 (1 token): CJK ideographs, kana, Hangul, Bopomofo, CJK punctuation,
 *    full/half-width forms, and the BMP pictograph blocks (Misc Technical,
 *    Misc Symbols, Dingbats, Misc Symbols and Arrows) plus ZWJ / VS16, the
 *    glue of emoji sequences.
 *  - 8 (2 tokens): every supplementary-plane codepoint — in practice emoji
 *    (and the rare CJK Extension B ideograph, which costs at least as much).
 *
 * WHY THIS OVER THE AUDIT'S `bytes/3` ALTERNATIVE: bytes/3 would move every
 * ASCII estimate up by a third (4,000 ASCII chars: 1,000 → 1,334), shifting
 * every pinned figure and making the idle/70% nudges fire noticeably early
 * for the common case; it survives here only as the fallback for text that is
 * not valid UTF-8, where no script can be read and over-estimating is the safe
 * direction against an overflow.
 *
 * COST: this runs over the whole history on every tier check, so it never
 * loops per character in PHP. Pure-ASCII text (the overwhelming case) exits
 * after one byte-class scan; anything else costs three PCRE counting passes
 * that build no match arrays.
 *
 * {@see \SugarCraft\Crush\Context\ContextCompactor::countTokens()} uses it too
 * (audit 15b-13-rem), so the compactor's tiers and Chat's estimate agree on
 * non-Latin text.
 */
final class TokenEstimate
{
    /** Any byte ≥ 0x80 — its absence means the text is pure ASCII. */
    private const NON_ASCII = '/[\x80-\xFF]/';

    /** Supplementary-plane codepoints: weight 8. */
    private const ASTRAL = '/[\x{10000}-\x{10FFFF}]/u';

    /**
     * BMP wide/ideographic/pictographic codepoints: weight 4. The lookahead
     * keeps astral Han out of this class so it is weighted once, by ASTRAL.
     */
    private const WIDE = '/(?![\x{10000}-\x{10FFFF}])[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Bopomofo}'
        . '\x{3000}-\x{30FF}\x{3100}-\x{31FF}\x{FF00}-\x{FFEF}'
        . '\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{200D}\x{FE0F}]/u';

    /**
     * Letters and marks of non-Latin, non-CJK scripts: weight 2. Excludes
     * everything WIDE or ASTRAL already weights, Latin itself, and Inherited
     * combining marks.
     */
    private const OTHER_SCRIPT = '/(?![\p{Latin}\p{Inherited}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Bopomofo}'
        . '\x{3000}-\x{30FF}\x{3100}-\x{31FF}\x{FF00}-\x{FFEF}\x{10000}-\x{10FFFF}])[\p{L}\p{M}]/u';

    private function __construct()
    {
    }

    /** Estimated tokens for $text, excluding any per-message overhead. */
    public static function ofText(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if (preg_match(self::NON_ASCII, $text) !== 1) {
            return (int) ceil(strlen($text) / 4);
        }

        $astral = preg_match_all(self::ASTRAL, $text);
        $wide = preg_match_all(self::WIDE, $text);
        $other = preg_match_all(self::OTHER_SCRIPT, $text);

        // `/u` refuses an invalid-UTF-8 subject outright (false), so no script
        // can be read; bytes/3 is ≥ the old codepoints/4 and ~1 token per CJK
        // character, i.e. never lower than either reading.
        if ($astral === false || $wide === false || $other === false) {
            return (int) ceil(strlen($text) / 3);
        }

        $quarters = mb_strlen($text, 'UTF-8') + 7 * $astral + 3 * $wide + 1 * $other;

        return (int) ceil($quarters / 4);
    }

    /**
     * Estimated tokens the tool definitions add to a request (roadmap 2.1).
     *
     * Every provider sends each tool as its name, description and JSON
     * Schema, and the model's window pays for all of them on every step —
     * twenty-odd built-ins plus MCP bridges is thousands of tokens that a
     * history-only estimate never saw. Estimated over the JSON the schema
     * travels as, which is what the provider's tokenizer reads; the exact
     * wrapper differs per provider by a handful of tokens per tool.
     *
     * @param iterable<\SugarCraft\Crush\Tools\Tool> $tools
     */
    public static function ofToolSchemas(iterable $tools): int
    {
        $tokens = 0;
        foreach ($tools as $tool) {
            $json = json_encode(
                ['name' => $tool->name(), 'description' => $tool->description(), 'parameters' => $tool->inputSchema()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $tokens += self::ofText($json === false ? $tool->name() . ' ' . $tool->description() : $json);
        }

        return $tokens;
    }
}
