<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

/**
 * Per-codepoint lowercase folding that stays 1:1 with the original string.
 *
 * Both matchers compare case-insensitively, but the contract is that
 * `matchedIndices` are code-point indices into the ORIGINAL haystack
 * (MatchResult docblock, CALIBER_LEARNINGS.md). Lowercasing the whole string
 * and splitting afterwards breaks that contract: `mb_strtolower` EXPANDS some
 * code points (measured PHP 8.3 across BMP+SMP the only expansion is
 * U+0130 İ → U+0069 U+0307), so the lowercased split is longer than the
 * original and every index at or after an expansion names the wrong char.
 *
 * This helper splits once and lowercases each code point individually, so the
 * result always holds exactly one element per original code point. An element
 * may itself be multi-code-point (folded İ); equality is then whole-element,
 * which is what keeps indices truthful.
 *
 * Two deliberate deviations from whole-string `mb_strtolower`, both accepted
 * for index correctness:
 *  - a query 'i' no longer matches 'İ' (whole-string folding used to match
 *    only İ's first expanded code point and desync every later index);
 *  - Greek final sigma: whole-string folding maps a word-final Σ to ς,
 *    per-char folding always yields σ (simple fold, no context).
 *
 * Malformed UTF-8 (audit 2026-10-07, documented not fixed): mb_strtolower
 * folds a lone invalid byte to the literal '?' (0x3F) and mb_str_split emits
 * exactly one element per invalid byte — so foldSplit stays 1:1 with the
 * original even for corrupt input, and a bad byte can never desync indices:
 * it only makes its own element compare as '?' (and lets a literal '?' query
 * match it). Downstream, Highlighter's mb_substr renders that byte as its
 * substitution char too, so styled output of corrupt input is '?'-rewritten
 * and not byte-faithful — alignment is preserved, fidelity is not. Pinned
 * by CodePointExpansionTest::testMalformedByteFoldsToQuestionMarkWithoutDesync.
 *
 * Pure memoized folding — same input, same output; the process cache is an
 * optimization with a hard size cap and is never observable from outside.
 */
final class CharFold
{
    /** Distinct-code-point cap for the process memo; beyond it folding stays correct, just uncached. */
    private const MEMO_LIMIT = 8192;

    /** @var array<string, string> code point => its lowercase (1..n code points) */
    private static array $memo = [];

    /**
     * Split a UTF-8 string and lowercase each code point individually.
     *
     * @return list<string> exactly one folded element per code point of $string
     */
    public static function foldSplit(string $string): array
    {
        // ASCII fast path: byte lowercasing is 1:1 there, so the per-code-point
        // contract holds without a fold() call per char (hot on every keystroke
        // of a filter over many labels).
        if ($string === '') {
            return [];
        }
        if (!preg_match('/[\x80-\xFF]/', $string)) {
            return str_split(strtolower($string));
        }

        $folded = [];
        foreach (mb_str_split($string, 1, 'UTF-8') as $char) {
            $folded[] = self::fold($char);
        }

        return $folded;
    }

    /**
     * Lowercase a single code point. The result may be multi-code-point
     * (U+0130 İ → i + U+0307) — callers must compare whole elements, never
     * re-split the fold.
     */
    public static function fold(string $char): string
    {
        $cached = self::$memo[$char] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $folded = mb_strtolower($char, 'UTF-8');
        if (count(self::$memo) < self::MEMO_LIMIT) {
            self::$memo[$char] = $folded;
        }

        return $folded;
    }
}
