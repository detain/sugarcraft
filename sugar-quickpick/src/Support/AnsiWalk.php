<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Support;

use SugarCraft\Core\Util\Ansi;

/**
 * Escape-aware walk over a terminal string.
 *
 * A formatted row mixes two kinds of content: DISPLAY codepoints (what the
 * filter matches against) and ANSI control sequences (what a custom item
 * formatter used to style the row). Matching must index only the former, but
 * re-emission must keep the latter verbatim — the previous highlighters
 * stripped all escapes, rebuilt the row from plain runes, and thereby
 * cannibalised any SGR the formatter had applied.
 *
 * The tokenizer is a linear byte scan; UTF-8 codepoint widths are derived
 * from the lead byte, and a lead byte only CLAIMS bytes that are genuine
 * 0x80..0xBF continuations — when the promised trail is not there, the lead
 * emits as its own piece and the following ASCII survives as the next one
 * (-042: an unvalidated `0xC3` used to swallow the byte behind it, silently
 * shifting every later match index by one). Stray continuation bytes are one
 * byte each. Render ingress repairs invalid UTF-8 via
 * {@see UntrustedText::guard()} beforehand, so these arms are the belt to
 * that suspenders: a host formatter closing over raw disk bytes can still
 * reach this walker unguarded.
 *
 * Piece lists are memoized per string (-070): `paint()` ran once per styled
 * row per frame and each call re-split the whole row into per-codepoint
 * pieces. The cache is bounded with oldest-drop, and every public answer is
 * byte-identical with or without a hit.
 */
final class AnsiWalk
{
    /** Bound on the pieces() memo — oldest entry drops on overflow. */
    private const PIECE_CACHE_LIMIT = 4096;

    /** @var array<string, list<array{escape: bool, text: string}>> */
    private static array $pieceCache = [];

    /**
     * Split a string into escape pieces and single-codepoint text pieces.
     *
     * @return list<array{escape: bool, text: string}>
     */
    public static function pieces(string $text): array
    {
        $cached = self::$pieceCache[$text] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $out = self::split($text);
        if (\count(self::$pieceCache) >= self::PIECE_CACHE_LIMIT) {
            unset(self::$pieceCache[\array_key_first(self::$pieceCache)]);
        }
        self::$pieceCache[$text] = $out;

        return $out;
    }

    /** @return list<array{escape: bool, text: string}> */
    private static function split(string $text): array
    {
        $len = \strlen($text);
        $out = [];
        $i = 0;

        while ($i < $len) {
            if ($text[$i] === "\x1b") {
                $end = self::sequenceEnd($text, $i, $len);
                $out[] = ['escape' => true, 'text' => \substr($text, $i, $end - $i)];
                $i = $end;
                continue;
            }
            $size = self::codepointSize($text, $i, $len);
            $out[] = ['escape' => false, 'text' => \substr($text, $i, $size)];
            $i += $size;
        }

        return $out;
    }

    /**
     * The display codepoints of $text, control sequences excluded.
     *
     * @return list<string>
     */
    public static function chars(string $text): array
    {
        $chars = [];
        foreach (self::pieces($text) as $piece) {
            if ($piece['escape'] === false) {
                $chars[] = $piece['text'];
            }
        }
        return $chars;
    }

    /** The display text of $string with all control sequences stripped. */
    public static function plain(string $text): string
    {
        return \implode('', self::chars($text));
    }

    /**
     * Re-emit $text with $style ... {@see Ansi::reset()} wrapped around the
     * matched display codepoints.
     *
     * Escape pieces pass through verbatim and interrupt a styled run (the
     * run is closed before and re-opened after the escape) so a highlighted
     * row can never clobber the consumer's own SGR by leaving an unterminated
     * sequence open across it.
     *
     * @param array<int, bool> $matchedSet keyed by display-codepoint index
     */
    public static function paint(string $text, array $matchedSet, string $style): string
    {
        $result = '';
        $charIndex = 0;
        $inStyle = false;

        foreach (self::pieces($text) as $piece) {
            if ($piece['escape'] === true) {
                if ($inStyle) {
                    $result .= Ansi::reset();
                    $inStyle = false;
                }
                $result .= $piece['text'];
                continue;
            }
            $isMatched = isset($matchedSet[$charIndex]);
            if ($isMatched && !$inStyle) {
                $result .= $style;
                $inStyle = true;
            } elseif (!$isMatched && $inStyle) {
                $result .= Ansi::reset();
                $inStyle = false;
            }
            $result .= $piece['text'];
            $charIndex++;
        }

        if ($inStyle) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Actual UTF-8 sequence length from the lead byte at $i, validated
     * against the continuation bytes present (1 for stray/invalid leads).
     *
     * -042: the former version returned the EXPECTED width from the lead
     * alone, so a truncated multibyte swallowed the following ASCII. Now
     * each promised continuation is checked for the 0x80..0xBF shape; on a
     * bad byte, the lead emits as its own one-byte piece and the scan
     * resumes at the offending byte.
     */
    private static function codepointSize(string $text, int $i, int $len): int
    {
        $lead = \ord($text[$i]);
        if ($lead < 0x80) {
            return 1;
        }
        if ($lead >= 0xC2 && $lead <= 0xDF) {
            $expected = 2;
        } elseif ($lead >= 0xE0 && $lead <= 0xEF) {
            $expected = 3;
        } elseif ($lead >= 0xF0 && $lead <= 0xF4) {
            $expected = 4;
        } else {
            return 1; // stray continuation or invalid lead (0x80..0xC1, 0xF5..0xFF)
        }

        $size = 1;
        while ($size < $expected) {
            $j      = $i + $size;
            $byte   = $j < $len ? \ord($text[$j]) : -1;
            if ($byte < 0x80 || $byte > 0xBF) {
                break; // bad/truncated continuation — emit the lead alone
            }
            $size++;
        }
        return $size;
    }

    /**
     * End offset (exclusive) of the escape sequence starting at $i.
     *
     * Covers CSI (params + intermediates + final), the string sequences
     * OSC/DCS/SOS/PM/APC (terminated by BEL or ST = ESC-backslash, or the
     * end of input), and plain two-byte Fe/Fs sequences with optional
     * intermediates. Mirrors ECMA-48's class boundaries; a truncated tail
     * consumes to end-of-string so an unterminated prefix can never leak
     * payload characters as display text.
     */
    private static function sequenceEnd(string $t, int $i, int $len): int
    {
        if ($i + 1 >= $len) {
            return $i + 1; // lone ESC at end of input
        }
        $next = $t[$i + 1];

        if ($next === '[') {
            $j = $i + 2;
            while ($j < $len && \ord($t[$j]) >= 0x30 && \ord($t[$j]) <= 0x3F) {
                $j++; // parameter bytes
            }
            while ($j < $len && \ord($t[$j]) >= 0x20 && \ord($t[$j]) <= 0x2F) {
                $j++; // intermediate bytes
            }
            if ($j < $len && \ord($t[$j]) >= 0x40 && \ord($t[$j]) <= 0x7E) {
                $j++; // final byte
            }
            return $j;
        }

        if ($next === ']' || $next === 'P' || $next === 'X' || $next === '^' || $next === '_') {
            $j = $i + 2;
            while ($j < $len) {
                if ($t[$j] === "\x07") {
                    return $j + 1;
                }
                if ($t[$j] === "\x1b" && $j + 1 < $len && $t[$j + 1] === '\\') {
                    return $j + 2;
                }
                $j++;
            }
            return $len;
        }

        // Two-or-more-byte sequence: intermediates then a single final byte.
        $j = $i + 1;
        while ($j < $len && \ord($t[$j]) >= 0x20 && \ord($t[$j]) <= 0x2F) {
            $j++;
        }
        if ($j < $len && \ord($t[$j]) >= 0x30 && \ord($t[$j]) <= 0x7E) {
            $j++;
        }
        return $j;
    }
}
