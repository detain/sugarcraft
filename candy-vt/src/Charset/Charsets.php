<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Charset;

/**
 * Character-set designation tables (SCS) and the GL/GR translation.
 *
 * A terminal holds four designatable sets G0–G3. ESC ( / ) / * / +
 * designate into G0 / G1 / G2 / G3 respectively; SO (0x0E, LS1) shifts
 * G1 into GL and SI (0x0F, LS0) shifts G0 back; SS2/SS3 (C1 0x8E/0x8F)
 * select G2/G3 for the next graphic character only.
 *
 * Only GL-reachable designations translate 7-bit runes: a multi-byte
 * UTF-8 rune is already Unicode and passes through untranslated, which
 * is exactly how upstream `x/vt utf8.go handleGrapheme` restricts the
 * lookup to single-byte content (GL for <0x80, GR otherwise — GR has no
 * reachable single bytes in a UTF-8-fed parser).
 *
 * Mirrors charmbracelet/x/vt CharSet / SpecialDrawing / UK (charset.go)
 * and charmbracelet/x/ansi SelectCharacterSet (charset.go).
 *
 * @see ECMA-48 §25 "Character set designation" (ESC (, ), *, +)
 * @see https://vt100.net/docs/vt500-ram/chapter4.html#S4-3
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (SCS + DEC Special Graphics)
 */
final class Charsets
{
    /** US ASCII — the identity charset. */
    public const ASCII = 'B';

    /** DEC Special Graphics (line-drawing) — `ESC ( 0`. */
    public const DEC_SPECIAL = '0';

    /** UK Latin-1 variant (`0x23` = £) — `ESC ( A`. */
    public const UK = 'A';

    /** ISO Latin-1 "No-Break Space" — `ESC ( U`; 0xA0 renders as space. */
    public const NO_BREAK_SPACE = 'U';

    /**
     * DEC Special Graphics: GL byte → replacement rune.
     *
     * Table reproduces `charmbracelet/x/vt` `SpecialDrawing` (charset.go)
     * verbatim — `ESC ( 0` followed by `lqqxk` draws ┌──┐ style frames,
     * the classic box-drawing idiom this charset exists for. Unlisted
     * bytes (including 0x5F `_`) pass through, exactly as upstream
     * leaves them unmapped.
     *
     * @var array<string, string>
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (DEC Special Graphics for GL)
     * @see https://vt100.net/docs/vt220-rm/chapter2.html#SEC2.6 (VT220 ASCII and DEC Special Graphics)
     */
    private const DEC_SPECIAL_MAP = [
        '`' => '◆', // U+25C6 black diamond
        'a' => '▒', // U+2592 medium shade
        'b' => '␉', // U+2409 HT symbol
        'c' => '␌', // U+240C FF symbol
        'd' => '␍', // U+240D CR symbol
        'e' => '␊', // U+240A LF symbol
        'f' => '°', // U+00B0 degree sign
        'g' => '±', // U+00B1 plus-minus sign
        'h' => '␤', // U+2424 NL symbol
        'i' => '␋', // U+240B VT symbol
        'j' => '┘', // U+2518 box drawings light up and left
        'k' => '┐', // U+2510 box drawings light down and left
        'l' => '┌', // U+250C box drawings light down and right
        'm' => '└', // U+2514 box drawings light up and right
        'n' => '┼', // U+253C box drawings light vertical and horizontal
        'o' => '⎺', // U+23BA horizontal scan line-1
        'p' => '⎻', // U+23BB horizontal scan line-2
        'q' => '─', // U+2500 box drawings light horizontal
        'r' => '⎼', // U+23BC horizontal scan line-3
        's' => '⎽', // U+23BD horizontal scan line-4
        't' => '├', // U+251C box drawings light vertical and right
        'u' => '┤', // U+2524 box drawings light vertical and left
        'v' => '┴', // U+2534 box drawings light up and horizontal
        'w' => '┬', // U+252C box drawings light down and horizontal
        'x' => '│', // U+2502 box drawings light vertical
        'y' => '⩽', // U+2A7D slanted equal to or less-than
        'z' => '⩾', // U+2A7E slanted equal to or greater-than
        '{' => 'π', // U+03C0 greek small letter pi
        '|' => '≠', // U+2260 not equal to
        '}' => '£', // U+00A3 pound sign
        '~' => '·', // U+00B7 middle dot
    ];

    /** UK set (ESC ( A): the single divergence from ASCII is 0x23 → £. */
    private const UK_MAP = [
        '#' => '£', // U+00A3
    ];

    /**
     * Translate one rune through a designated charset.
     *
     * Unrecognised designation bytes (anything outside the SCS finals
     * we model — e.g. vendor sets like `ESC ( K` German) fall back to
     * identity, mirroring xterm's handling of unknown GL designations
     * when it was compiled without that particular resource.
     */
    public static function translate(string $charset, string $rune): string
    {
        // Only single-byte ASCII GL runes (0x20–0x7E) are remapped;
        // UTF-8 multi-byte runes are Unicode already and pass through.
        if (strlen($rune) !== 1) {
            return $rune;
        }

        return match ($charset) {
            self::DEC_SPECIAL => self::DEC_SPECIAL_MAP[$rune] ?? $rune,
            self::UK => self::UK_MAP[$rune] ?? $rune,
            self::NO_BREAK_SPACE => $rune === "\xA0" ? ' ' : $rune,
            default => $rune,
        };
    }
}
