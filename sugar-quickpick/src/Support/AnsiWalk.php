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
 * from the lead byte. Stray continuation bytes (invalid UTF-8) are emitted
 * one byte at a time — render ingress already repairs them via
 * {@see UntrustedText::guard()}, so they are inert here by construction.
 */
final class AnsiWalk
{
    /**
     * Split a string into escape pieces and single-codepoint text pieces.
     *
     * @return list<array{escape: bool, text: string}>
     */
    public static function pieces(string $text): array
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
            $size = self::codepointSize(\ord($text[$i]));
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

    /** Expected UTF-8 sequence length from its lead byte (1 for stray bytes). */
    private static function codepointSize(int $lead): int
    {
        if ($lead < 0x80) {
            return 1;
        }
        if ($lead >= 0xC2 && $lead <= 0xDF) {
            return 2;
        }
        if ($lead >= 0xE0 && $lead <= 0xEF) {
            return 3;
        }
        if ($lead >= 0xF0 && $lead <= 0xF4) {
            return 4;
        }
        return 1;
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
