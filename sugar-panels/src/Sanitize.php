<?php

declare(strict_types=1);

namespace SugarCraft\Panels;

use SugarCraft\Core\Util\Sanitize as CoreSanitize;
use SugarCraft\Core\Util\Width;

/**
 * The paint boundary: what a panel is willing to place into a cell grid.
 *
 * Panels styles text, so an escape-aware renderer cannot simply run the
 * generic untrusted-text scrub over its input — sugar-core's
 * {@see CoreSanitize::cellValue()} replaces every C0 byte (ESC included) with
 * a visible middle dot, which would delete the very sequences the library
 * exists to carry, and {@see CoreSanitize::untrusted()} deliberately spares
 * TAB, LF and CR, so a host that dutifully follows the README's advice still
 * hands the renderer the two bytes that break a grid. What panels needs is the
 * complement: neutralise everything that cannot survive a cell, keep the
 * styling vocabulary intact.
 *
 * Per leg, why it is here:
 *
 * - **Invalid UTF-8** is repaired to U+FFFD before anything else. Every width
 *   decision downstream is taken with {@see Width} over codepoints, while the
 *   bytes themselves reach the terminal untouched; a truncated multi-byte
 *   sequence therefore charges a width the terminal cannot see, and the whole
 *   row after it shifts. Repairing first makes model and paint agree.
 * - **Zone sentinels** (U+E000 / U+E001, sugar-mouse's `Mark::zone` pair) are
 *   stripped. They are invisible to the eye and to any escape-aware sweep, so
 *   text that carries them — a model reply, a pasted label, a log line — would
 *   otherwise be painted verbatim and could forge a mouse zone into whatever
 *   pane the frame is composited on. Other Private Use codepoints (image
 *   overlay markers, Nerd Font glyphs) are kept: they are legitimate payloads
 *   for a host that composites panels inside a marked frame, and only the
 *   sentinel pair carries authority.
 * - **CR and CRLF** collapse to LF. Panels splits content on `"\n"` alone, so
 *   a bare CR would ride inside a cell as an invisible byte that every
 *   terminal obeys — carriage-returning the row, erasing the border painted
 *   left of it, and (in a full-frame diff) leaking the move to sibling panes.
 * - **TAB** is expanded to {@see Width::TAB_WIDTH} spaces. `Width` charges a
 *   tab that wide, but the terminal moves to the next multiple of 8: a modelled
 *   width of 4 and a physical advance of 8 puts every following column — and
 *   the box's right edge — out by four. Expanding at ingest makes the two agree
 *   without panels having to own a tab-stop table.
 * - **The remaining C0 controls and DEL** are removed. None of them is
 *   paintable, and several move the cursor (BS) or start a protocol (XOFF).
 *   Three bytes are spared because they are part of the styling vocabulary,
 *   not exceptions to it: ESC opens the sequences the library renders, LF is
 *   the separator {@see wordWrap()} splits content on, and BEL terminates an
 *   OSC string — deleting it would leave the sequence unterminated, and an
 *   unterminated OSC swallows the rest of the row, so a "harmless" beep byte
 *   removal quietly erases the text that followed it.
 * - **C1 (U+0080-U+009F) is removed too.** It is not spared like its ASCII
 *   relatives, because sparing it would spare the *commands* they introduce:
 *   xterm, VTE and kitty honour 8-bit CSI (U+009B) exactly as `ESC [`, 8-bit
 *   OSC (U+009D) as `ESC ]`, and DCS (U+0090) as `ESC P` — one smuggled
 *   codepoint reaches the host's parser with full escape authority. Even an
 *   C1 that no emulator honours charges a column the painter never advances,
 *   so it lies about width. The core `Sanitize::untrusted()` helper strips C1
 *   for the same reason; this sweep is the floor the README's trust boundary
 *   mandates, so it must not be weaker than the helper it points at.
 *
 * This is a *boundary*, not a policy: an escape sequence the host put in the
 * content on purpose is still an escape sequence by the time it reaches the
 * grid. Hostile text must still be scrubbed with the core helper (the README's
 * trust boundary says which one); what this guarantees is that no smuggled
 * control byte, forged zone marker, or width-lying byte can enter the frame
 * through the public node API at all.
 */
final class Sanitize
{
    /**
     * C0 control bytes with no meaning inside a cell, plus DEL, plus the C1
     * block (U+0080-U+009F).
     *
     * Excluded: ESC (\x1b, opens the styling sequences the library renders),
     * LF (\x0a, the content line separator) and BEL (\x07, the OSC terminator —
     * see the pin that an OSC closed by BEL still reaches the grid whole).
     * The C1 codepoints are the 8-bit twins of that vocabulary's introducers —
     * xterm, VTE and kitty honour U+009B as ESC [ — so they can never be
     * spared; see their bullet in the class docblock.
     * CR and TAB are normalised by their own legs above, before this sweep
     * runs, so they never reach it.
     */
    private const UNPAINTABLE_CONTROLS = '/[\x00-\x06\x08\x0b\x0c\x0e-\x1a\x1c-\x1f\x7f\x{80}-\x{9f}]/u';

    private function __construct()
    {
    }

    /**
     * Normalise text destined for the grid into text the grid can paint.
     *
     * Idempotent: feeding output back in changes nothing, which is what lets a
     * host pre-sanitise and still round-trip a node through `withContent()`.
     */
    public static function painted(string $text): string
    {
        $text = self::repairUtf8($text);
        $text = CoreSanitize::stripZoneSentinels($text);
        $text = \str_replace(["\r\n", "\r"], "\n", $text);
        $text = \str_replace("\t", \str_repeat(' ', Width::TAB_WIDTH), $text);

        return \preg_replace(self::UNPAINTABLE_CONTROLS, '', $text) ?? '';
    }

    /**
     * Normalise a box title.
     *
     * A title is painted onto one row: unlike content, it is never split on
     * line feeds, so the LF that {@see painted()} leaves standing (because the
     * content path needs it as its separator) would here move the cursor down
     * a row mid-border and tear the frame. Flattening it to a space is the only
     * honest reading of a title that contains one.
     */
    public static function paintedTitle(string $title): string
    {
        return \str_replace("\n", ' ', self::painted($title));
    }

    /**
     * Replace ill-formed byte sequences with U+FFFD (sugar-core's own repair).
     *
     * `mb_scrub()` substitutes "?" here and core's helper is private, so the
     * substitution character is pinned explicitly and restored afterwards —
     * the same sequence {@see CoreSanitize} uses, and the reason a truncated
     * emoji charges one honest cell instead of zero.
     */
    private static function repairUtf8(string $text): string
    {
        if (\mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = \mb_substitute_character();
        \mb_substitute_character(0xFFFD);
        $repaired = \mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        \mb_substitute_character($previous);

        // mb_convert_encoding is signed string|false. PHP 8.x throws ValueError
        // (rather than returning false) for bad encoding arguments, so on this
        // UTF-8→UTF-8 path a false never happens at runtime — but the declared
        // `: string` is a contract the signature cannot prove. Fail SOFT: an
        // unrecoverable conversion hands back the empty string (the grid paints
        // an empty cell) rather than leaking a false through the type.
        return \is_string($repaired) ? $repaired : '';
    }
}
