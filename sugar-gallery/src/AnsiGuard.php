<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use InvalidArgumentException;

/**
 * The one place that decides which escape sequences a widget will echo verbatim.
 *
 * sugar-gallery tiles are renderer-agnostic: a poster and a styled title are
 * *already-rendered* bytes handed in by the caller and stitched straight into
 * the frame. That is a trust boundary — bytes from an untrusted source can move
 * the cursor, erase the screen, or smuggle an OSC 8 hyperlink into a title a
 * server returned. The plain-title path neutralises that by running the same
 * scanner over the title ({@see stripControls()}, from
 * {@see PosterCard::render()}); a styled title cannot do that, because removing
 * escapes would destroy the very SGR sequences it exists to carry. This class is
 * the other half of that contract: it admits *styling* and nothing else.
 *
 * What passes: SGR sequences (`ESC [ <digits ; : > m`, the styling sequences
 * candy-sprinkles emits) and printable text. What does not: every other escape
 * form (CSI cursor/erase/mode, OSC/DCS/APC payloads, charset designators, Fe/Fs
 * pairs), a malformed or truncated sequence, a bare C0 control, DEL, and an 8-bit
 * C1 control in EITHER wire form — the raw byte (`0x9B`) or its UTF-8 re-encoding
 * (`C2 9B` = U+009B), which mainstream terminals act on as if the `ESC` had been
 * spelled out.
 *
 * Two shapes of use, for the two kinds of call site:
 *
 *  - {@see assertSafe()} — fail fast, for a caller that *claims* the bytes are
 *    self-produced (a highlight built by its own code) and wants the lie caught
 *    in development rather than on the user's terminal.
 *  - {@see sanitize()} — coerce, for a caller holding bytes it cannot vouch for
 *    and would rather lose a colour than risk a cursor move.
 *
 * @see PosterCard::withStyledTitle() for the guard option on the widget side
 */
final class AnsiGuard
{
    /** Run kinds produced by {@see runs()}. */
    private const TEXT = 'text';

    private const STYLE = 'style';

    private const UNSAFE = 'unsafe';

    /** Byte that introduces every escape sequence. */
    private const ESC = "\x1b";

    /**
     * Every byte that can begin something a terminal acts on: C0, DEL, and the whole
     * C1 band. The band does double duty, holding both the raw 8-bit controls and the
     * second byte of each one re-encoded in UTF-8, which is what makes it a complete
     * pre-filter: a string with none of these bytes cannot hide an escape sequence.
     */
    private const ESCAPABLE_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f"
        . "\x7f\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f"
        . "\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f";

    private function __construct()
    {
    }

    /**
     * Return $ansi untouched when it carries nothing but SGR styling and
     * printable text; throw when it carries anything else.
     *
     * The return value makes it composable at a call site
     * (`$card->withStyledTitle(AnsiGuard::assertSafe($s))`) so the guard reads
     * as what it is: a parse step on the way in.
     *
     * @throws InvalidArgumentException when an unsafe byte or sequence is present
     */
    public static function assertSafe(string $ansi): string
    {
        // Fast path: the overwhelming majority of styled titles are SGR + text,
        // and neither can appear without an ESC or a control byte in the string.
        if (!self::carriesAnythingEscapable($ansi)) {
            return $ansi;
        }

        $offset = 0;
        foreach (self::runs($ansi) as [$kind, $bytes]) {
            if ($kind === self::UNSAFE) {
                throw new InvalidArgumentException(sprintf(
                    'Unsafe escape sequence at offset %d: %s. Only SGR styling (ESC [ <params> m) '
                    . 'may be embedded in a styled title; use AnsiGuard::sanitize() to drop everything else.',
                    $offset,
                    self::describe($bytes),
                ));
            }
            $offset += strlen($bytes);
        }

        return $ansi;
    }

    /** Whether {@see assertSafe()} would accept $ansi. */
    public static function isSafe(string $ansi): bool
    {
        if (!self::carriesAnythingEscapable($ansi)) {
            return true;
        }

        foreach (self::runs($ansi) as [$kind, $bytes]) {
            if ($kind === self::UNSAFE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop every unsafe run from $ansi, keeping its SGR styling and text.
     *
     * Byte-preserving for safe input, so `sanitize()` on a title that already
     * passed `assertSafe()` is a no-op. An unterminated escape swallows the rest
     * of the sequence (not the rest of the string): a hostile payload gets
     * removed, not reinterpreted.
     */
    public static function sanitize(string $ansi): string
    {
        if (!self::carriesAnythingEscapable($ansi)) {
            return $ansi;
        }

        $kept = '';
        foreach (self::runs($ansi) as [$kind, $bytes]) {
            if ($kind !== self::UNSAFE) {
                $kept .= $bytes;
            }
        }

        return $kept;
    }

    /**
     * Reduce $text to what a *plain* (unstyled) title may contain: keep the text
     * runs and drop every escape the scanner sees, SGR included.
     *
     * This is {@see PosterCard::render()}'s sanitising path, and it is the same
     * scanner as {@see sanitize()} so the two boundaries cannot drift about what a
     * control byte is. Unlike {@see sanitize()} there is nothing to preserve: a
     * plain title carries no styling by contract, so a title that arrives with
     * escapes in it is either hostile or double-encoded, and in both cases the
     * escapes are what should go. That includes TAB / CR / LF, which would
     * otherwise break the fixed-height cell the grid reserved for the card.
     */
    public static function stripControls(string $text): string
    {
        if (!self::carriesAnythingEscapable($text)) {
            return $text;
        }

        $plain = '';
        foreach (self::runs($text) as [$kind, $bytes]) {
            if ($kind === self::TEXT) {
                $plain .= $bytes;
            }
        }

        return $plain;
    }

    // ---- internals -----------------------------------------------------

    /**
     * Cheap pre-filter: does $ansi contain any byte the scanner could flag?
     *
     * Plain ASCII and well-formed multi-byte text outside the C1 range — the
     * overwhelming majority of titles — cannot reach an unsafe branch at all:
     * every unsafe run starts with ESC, a C0/DEL byte, or a byte in 0x80–0x9F.
     * That last range is deliberately in the test because it covers BOTH forms an
     * 8-bit control takes on the wire: the raw C1 byte itself, and the second
     * byte of its UTF-8 re-encoding (`C2 9B` = U+009B = 8-bit CSI). Well-formed
     * CJK text does contain continuation bytes in that band (果 is `E6 9E 9C`), so
     * this filter only decides whether to run the scanner — {@see textRunEnd()}
     * is what keeps such sequences in the text run.
     *
     * A byte-set lookup rather than `preg_match()` on purpose: PCRE answers `false`
     * on an internal failure, and against a `=== 1` test that reads as "nothing here
     * needs escaping" — waving the input through unchecked. Same fail-open shape the
     * lib's learnings warn about, bought for no benefit.
     */
    private static function carriesAnythingEscapable(string $ansi): bool
    {
        return strpbrk($ansi, self::ESCAPABLE_BYTES) !== false;
    }

    /**
     * Split $ansi into runs of text / SGR style / unsafe bytes.
     *
     * The single scanner behind {@see assertSafe()}, {@see isSafe()},
     * {@see sanitize()} and {@see stripControls()}, so the four can never disagree
     * about what is safe.
     * Every run is non-empty and the runs concatenate back to the input exactly.
     *
     * @return list<array{0:self::TEXT|self::STYLE|self::UNSAFE, 1:string}>
     */
    private static function runs(string $ansi): array
    {
        $runs = [];
        $length = strlen($ansi);
        $i = 0;

        while ($i < $length) {
            if ($ansi[$i] === self::ESC) {
                $styleEnd = self::styleSequenceEnd($ansi, $i);
                if ($styleEnd !== null) {
                    $runs[] = [self::STYLE, substr($ansi, $i, $styleEnd - $i)];
                    $i = $styleEnd;
                    continue;
                }

                $end = self::escapeSequenceEnd($ansi, $i);
                $runs[] = [self::UNSAFE, substr($ansi, $i, $end - $i)];
                $i = $end;
                continue;
            }

            if (self::isControl($ansi[$i])) {
                $runs[] = [self::UNSAFE, $ansi[$i]];
                $i++;
                continue;
            }

            $end = self::textRunEnd($ansi, $i, $length);
            if ($end === $i) {
                // An 8-bit C1 control: either the raw byte (0x9B = 8-bit CSI) or a
                // codepoint that decodes to one (U+009B = `C2 9B`). Both are
                // introducers a terminal acts on exactly as if `ESC` had been
                // spelled out, so the parameters that belong to them go too —
                // otherwise `C2 9B 32 4A` would shed its CSI and keep "2J" as text.
                [$size, $code] = self::classifyC1($ansi, $i);
                $end = self::c1SequenceEnd($ansi, $i + $size, $code);
                $runs[] = [self::UNSAFE, substr($ansi, $i, $end - $i)];
                $i = $end;
                continue;
            }

            $runs[] = [self::TEXT, substr($ansi, $i, $end - $i)];
            $i = $end;
        }

        return $runs;
    }

    /**
     * End of the text run at $start: every byte up to the next ESC, C0/DEL or C1
     * control — where a well-formed UTF-8 sequence counts as one indivisible
     * unit. Consuming sequences whole is what stops the continuation bytes of
     * legitimate CJK text (果 = `E6 9E 9C`) from being mistaken for an 8-bit C1
     * control, while a *stray* byte in that band still ends the run.
     *
     * Returns $start itself when the byte there is a C1 control, so the caller
     * classifies it as an unsafe run.
     */
    private static function textRunEnd(string $ansi, int $start, int $length): int
    {
        $i = $start;

        while ($i < $length) {
            $byte = ord($ansi[$i]);

            if ($byte === 0x1b || self::isControl(chr($byte)) || self::isC1At($ansi, $i, $length)) {
                break;
            }

            $i += max(1, self::utf8SequenceLength($ansi, $i, $length));
        }

        return $i;
    }

    /**
     * Whether the (sub-)sequence at $start is an 8-bit control character: a raw
     * byte in 0x80–0x9F that no well-formed sequence claimed, or its UTF-8
     * re-encoding `C2 80`–`C2 9F` (= U+0080–U+009F). Real glyphs in that block
     * start at U+00A0 (`C2 A0`), so the encoded form has no false positives.
     */
    private static function isC1At(string $ansi, int $start, int $length): bool
    {
        $byte = ord($ansi[$start]);

        if ($byte === 0xc2) {
            $next = $start + 1 < $length ? ord($ansi[$start + 1]) : -1;

            return $next >= 0x80 && $next <= 0x9f;
        }

        return $byte >= 0x80 && $byte <= 0x9f;
    }

    /**
     * The C1 control at $start as `[byteLength, controlCode]`: a raw byte is one
     * byte long and carries its own value, its UTF-8 re-encoding is two bytes and
     * decodes to the second. Only call this where {@see isC1At()} already said yes,
     * which is what makes reading $start + 1 safe for the `C2` form — a lone `C2`
     * at end of input is not a C1 and never reaches here.
     *
     * @return array{0:int, 1:int}
     */
    private static function classifyC1(string $ansi, int $start): array
    {
        return ord($ansi[$start]) === 0xc2 ? [2, ord($ansi[$start + 1])] : [1, ord($ansi[$start])];
    }

    /**
     * Exclusive end of an unsafe sequence introduced by the 8-bit C1 control of
     * code $code at $after — the same forms {@see escapeSequenceEnd()} knows, with
     * the one-byte introducer already consumed. The mapping is the ECMA-48
     * `ESC F` → `F + 0x40` pairing, so the string introducers are 0x90/0x98/0x9E/0x9F
     * for DCS/SOS/PM/APC, beside 0x9B (CSI) and 0x9D (OSC). Every other C1 — the Fe
     * controls, 0x99 SGCI, 0x9A SCI, a stray 0x9C ST — is a single byte: removed, but
     * not allowed to swallow the text behind it. Nothing here admits 8-bit *styling*:
     * `9B … m` is rejected even though a terminal would honour it, because every
     * renderer in this ecosystem emits the 7-bit form.
     */
    private static function c1SequenceEnd(string $ansi, int $after, int $code): int
    {
        return match ($code) {
            0x9b => self::csiSequenceEnd($ansi, $after),
            0x9d => self::stringSequenceEnd($ansi, $after, true),
            0x90, 0x98, 0x9e, 0x9f => self::stringSequenceEnd($ansi, $after, false),
            default => $after,
        };
    }

    /**
     * Length of the well-formed multi-byte UTF-8 sequence starting at $start
     * (2, 3 or 4), or 0 when the byte there is not such a lead. ASCII reports 0 as
     * well and the caller advances it a single byte; do not "fix" this to return 1
     * for ASCII — the RFC 3629 range check below only has meaning for a lead of two
     * bytes or more, and would otherwise judge the byte following a plain letter. The first continuation byte is checked
     * against the RFC 3629 ranges, not merely `80–BF`, because that is what excludes
     * the over-long and surrogate forms: `E0 81 9B` is a lenient decoder's `ESC`, and
     * accepting it as a text sequence would hand an injection straight back to
     * whatever logs or transcodes the title later. A rejected form reports 0 and is
     * then read byte by byte, so its bytes fall to the C1 test individually.
     */
    private static function utf8SequenceLength(string $ansi, int $start, int $length): int
    {
        $lead = ord($ansi[$start]);

        $expected = match (true) {
            $lead < 0x80 => 1,
            $lead >= 0xc2 && $lead <= 0xdf => 2,
            $lead >= 0xe0 && $lead <= 0xef => 3,
            $lead >= 0xf0 && $lead <= 0xf4 => 4,
            default => 0,
        };

        if ($expected < 2 || $start + $expected > $length) {
            return 0;
        }

        $first = ord($ansi[$start + 1]);
        [$lo, $hi] = match ($lead) {
            0xe0 => [0xa0, 0xbf],   // no over-long 3-byte forms (U+0800+)
            0xed => [0x80, 0x9f],   // no surrogates (U+D800–U+DFFF)
            0xf0 => [0x90, 0xbf],   // no over-long 4-byte forms (U+10000+)
            0xf4 => [0x80, 0x8f],   // nothing past U+10FFFF
            default => [0x80, 0xbf],
        };
        if ($first < $lo || $first > $hi) {
            return 0;
        }

        for ($i = $start + 2; $i < $start + $expected; $i++) {
            $continuation = ord($ansi[$i]);
            if ($continuation < 0x80 || $continuation > 0xbf) {
                return 0;
            }
        }

        return $expected;
    }

    /**
     * Exclusive end of an SGR sequence at $start — `ESC [ <digits/:/;>* m` — or
     * null when the bytes there are a CSI with any other final byte (`A` cursor
     * up, `J` erase…) and therefore not styling.
     */
    private static function styleSequenceEnd(string $ansi, int $start): ?int
    {
        if (($ansi[$start + 1] ?? '') !== '[') {
            return null;
        }

        $length = strlen($ansi);
        $i = $start + 2;
        while ($i < $length && str_contains('0123456789;:', $ansi[$i])) {
            $i++;
        }

        return ($ansi[$i] ?? '') === 'm' ? $i + 1 : null;
    }

    /**
     * Exclusive end of a CSI sequence whose introducer consumed up to $after:
     * parameter bytes (0x30–0x3F), one optional intermediate (0x20–0x2F), then a
     * single final byte (0x40–0x7E). A truncated CSI ends at the byte that proves
     * it stopped being one instead of eating the rest of the string, so a
     * following well-formed sequence is still classified on its own terms.
     */
    private static function csiSequenceEnd(string $ansi, int $after): int
    {
        $length = strlen($ansi);
        $i = $after;

        while ($i < $length && ord($ansi[$i]) >= 0x20 && ord($ansi[$i]) <= 0x3f) {
            $i++;
        }
        if ($i < $length && ord($ansi[$i]) >= 0x40 && ord($ansi[$i]) <= 0x7e) {
            return $i + 1;
        }

        return $i;
    }

    /**
     * Exclusive end of the (unsafe) escape sequence at $start, scanned by
     * ECMA-48 form so no hostile byte can hide past our reading:
     *
     *  - CSI  `ESC [` parameter bytes (0x30–0x3F) + intermediates (0x20–0x2F) +
     *    one final byte (0x40–0x7E). A truncated CSI ends at the offending byte
     *    rather than eating the rest of the string, so a following well-formed
     *    sequence is still classified on its own terms.
     *  - OSC  `ESC ]` up to BEL or ST (`ESC \`).
     *  - DCS / SOS / PM / APC (`ESC P X ^ _`) up to ST (`ESC \`).
     *  - Charset designators (`ESC ( B`): three bytes.
     *  - Anything else: two bytes.
     *
     * A truncated sequence ends where it provably stops being one (the next ESC,
     * or the end of the input) — never by swallowing the rest of the string.
     *
     * Always returns a value greater than $start, so {@see runs()} cannot stall.
     */
    private static function escapeSequenceEnd(string $ansi, int $start): int
    {
        $length = strlen($ansi);
        $i = $start + 1;
        if ($i >= $length) {
            return $length;
        }

        $lead = $ansi[$i];

        if ($lead === '[') {
            return self::csiSequenceEnd($ansi, $i + 1);
        }

        if ($lead === ']') {
            return self::stringSequenceEnd($ansi, $i + 1, true);
        }

        if (str_contains('PX^_', $lead)) {
            return self::stringSequenceEnd($ansi, $i + 1, false);
        }

        if (str_contains('()*+', $lead)) {
            return min($start + 3, $length);
        }

        return min($i + 1, $length);
    }

    /**
     * End of a string parameter sequence (OSC / DCS / SOS / PM / APC) started at
     * $from: terminated by ST (`ESC \`), and by BEL too when $belTerminates.
     *
     * ST arrives in three forms and all three terminate it — `ESC \`, the raw byte
     * 0x9C, and its UTF-8 re-encoding `C2 9C` — because a terminal in UTF-8 mode
     * decodes the last two to the same control.
     *
     * Any other ESC ends the run rather than being swallowed by it: an
     * unterminated payload is dropped, but the next sequence is still classified
     * on its own terms, so one truncated OSC cannot quietly delete the rest of a
     * title. Only a payload that runs to the end of the input consumes everything.
     */
    private static function stringSequenceEnd(string $ansi, int $from, bool $belTerminates): int
    {
        $length = strlen($ansi);
        for ($i = $from; $i < $length; $i++) {
            if ($belTerminates && $ansi[$i] === "\x07") {
                return $i + 1;
            }

            if ($ansi[$i] === self::ESC) {
                return ($ansi[$i + 1] ?? '') === '\\' ? $i + 2 : $i;
            }

            if ($ansi[$i] === "\x9c") {
                return $i + 1;
            }

            if ($ansi[$i] === "\xc2" && ($ansi[$i + 1] ?? '') === "\x9c") {
                return $i + 2;
            }
        }

        return $length;
    }

    /** Any C0 control (ESC excepted — the caller routes it) or DEL. */
    private static function isControl(string $byte): bool
    {
        $ord = ord($byte);

        return ($ord < 0x20 && $byte !== self::ESC) || $ord === 0x7f;
    }

    /** A byte-hex rendering of an offending run, bounded so the message stays readable. */
    private static function describe(string $bytes): string
    {
        $shown = substr($bytes, 0, 16);
        $parts = [];
        for ($i = 0; $i < strlen($shown); $i++) {
            $parts[] = sprintf('%02X', ord($shown[$i]));
        }
        $suffix = strlen($bytes) > strlen($shown) ? sprintf(' (+%d more bytes)', strlen($bytes) - strlen($shown)) : '';

        return '[' . implode(' ', $parts) . ']' . $suffix;
    }
}
