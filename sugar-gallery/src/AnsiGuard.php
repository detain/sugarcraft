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
 * server returned. The plain-title path neutralises that by stripping C0 in
 * {@see PosterCard::render()}; a styled title cannot, because stripping C0 would
 * destroy the very SGR escapes it exists to carry. This class is the other half
 * of that contract: it admits *styling* and nothing else.
 *
 * What passes: SGR sequences (`ESC [ <digits ; : > m` — exactly what
 * candy-sprinkles emits) and printable text. What does not: every other escape
 * form (CSI cursor/erase/mode, OSC, DCS/SOS/PM/APC, charset designators, Fe/Fs
 * pairs), a malformed or truncated sequence, a bare C0 control, DEL.
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

    // ---- internals -----------------------------------------------------

    /**
     * Cheap pre-filter: does $ansi contain any byte the scanner could flag?
     *
     * Plain text and multi-byte UTF-8 titles are the common case and neither can
     * reach the scanner's unsafe branches: every unsafe run starts with ESC or a
     * C0/DEL control byte, so a string without one is safe by construction.
     */
    private static function carriesAnythingEscapable(string $ansi): bool
    {
        return preg_match('/[\x00-\x1f\x7f]/', $ansi) === 1;
    }

    /**
     * Split $ansi into runs of text / SGR style / unsafe bytes.
     *
     * The single scanner behind {@see assertSafe()}, {@see isSafe()} and
     * {@see sanitize()}, so the three can never disagree about what is safe.
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

            $end = $i;
            while ($end < $length && $ansi[$end] !== self::ESC && !self::isControl($ansi[$end])) {
                $end++;
            }
            $runs[] = [self::TEXT, substr($ansi, $i, $end - $i)];
            $i = $end;
        }

        return $runs;
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
            $i++;
            while ($i < $length && ord($ansi[$i]) >= 0x20 && ord($ansi[$i]) <= 0x3f) {
                $i++;
            }
            if ($i < $length && ord($ansi[$i]) >= 0x40 && ord($ansi[$i]) <= 0x7e) {
                return $i + 1;
            }

            return $i;
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
     * Unterminated payloads run to the end of the input — the whole smuggled
     * payload is dropped, never emitted.
     */
    private static function stringSequenceEnd(string $ansi, int $from, bool $belTerminates): int
    {
        $length = strlen($ansi);
        for ($i = $from; $i < $length; $i++) {
            if ($belTerminates && $ansi[$i] === "\x07") {
                return $i + 1;
            }
            if ($ansi[$i] === self::ESC && ($ansi[$i + 1] ?? '') === '\\') {
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
