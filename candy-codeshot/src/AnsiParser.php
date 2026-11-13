<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

use SugarCraft\AnsiParser\Parser\Parser;

/**
 * Splits a single line of ANSI-styled text into typed {@see Segment}s
 * for {@see SvgRenderer} and {@see PngRenderer}.
 *
 * Handles SGR foreground and background colours (16-color / 256-color
 * / 24-bit RGB) and the standard attribute flags (bold, italic,
 * underline). Background colours are passed through to segments for
 * per-segment rendering.
 *
 * Both separator spellings are understood for the extended colours — the
 * flat `38;2;R;G;B` and the ECMA-48 §14.1.1 grouped `38:2::R:G:B` form xterm
 * documents — because the colour-space id slot in the grouped form is not a
 * colour component.
 *
 * Other ANSI sequences (CSI cursor moves, OSC, DCS, etc.) are dropped
 * silently — they have no visible effect in a static image.
 *
 * Internally delegates to sugar-ansi-parser's {@see Parser} state machine.
 */
final class AnsiParser
{
    /** xterm 16-color palette as hex strings, used for `\x1b[3{0-7}m`. */
    public const ANSI16 = [
        0  => '#000000', 1  => '#cd0000', 2  => '#00cd00', 3  => '#cdcd00',
        4  => '#0000ee', 5  => '#cd00cd', 6  => '#00cdcd', 7  => '#e5e5e5',
        8  => '#7f7f7f', 9  => '#ff0000', 10 => '#00ff00', 11 => '#ffff00',
        12 => '#5c5cff', 13 => '#ff00ff', 14 => '#00ffff', 15 => '#ffffff',
    ];

    /**
     * The six xterm cube intensities (cs-24, -043). A class constant, not a
     * per-call local: the 6..231 colour band is a hot path and the table is
     * pure data.
     *
     * @var list<int>
     */
    private const XTERM_LEVELS = [0, 95, 135, 175, 215, 255];

    /**
     * Parse one line of ANSI text into a list of styled segments.
     *
     * Adjacent runs that end up carrying the *same* style merge into one
     * segment (cs-25, -047): a sender that re-emits an unchanged colour —
     * `SGR 32` after `SGR 32`, or any no-op parameter in between — used to
     * inflate the markup 5-15x with per-token repaint elements that paint
     * exactly what one element paints. The merge is style-exact (fg, bg,
     * bold, italic, underline all equal), so no consumer can tell the
     * difference beyond element count; text order is untouched.
     *
     * @return list<Segment>
     */
    public static function parse(string $line, ?SgrState &$carry = null): array
    {
        $segments = [];
        $state = $carry ?? new SgrState();
        $textBuf = '';
        $flush = static function () use (&$segments, &$textBuf, &$state): void {
            if ($textBuf === '') {
                return;
            }
            $last = $segments === [] ? null : $segments[count($segments) - 1];
            if ($last !== null
                && $last->fg === $state->fg
                && $last->bg === $state->bg
                && $last->bold === $state->bold
                && $last->italic === $state->italic
                && $last->underline === $state->underline
            ) {
                // Segment is readonly-by-contract; replace the tail entry
                // rather than mutate it (the by-reference array view sees
                // the same slot).
                $segments[count($segments) - 1] = new Segment(
                    text:      $last->text . $textBuf,
                    fg:        $last->fg,
                    bold:      $last->bold,
                    italic:    $last->italic,
                    underline: $last->underline,
                    bg:        $last->bg,
                );
            } else {
                $segments[] = new Segment(
                    text:      $textBuf,
                    fg:        $state->fg,
                    bold:      $state->bold,
                    italic:    $state->italic,
                    underline: $state->underline,
                    bg:        $state->bg,
                );
            }
            $textBuf = '';
        };

        $handler = new SgrStateHandler($state, $textBuf, $flush, $segments);

        // Feed a UTF-8-clean line so the strict parser replaces malformed
        // bytes instead of forwarding them verbatim into the document (M12).
        // The door is the line-level normalise above: once mb_check_encoding
        // passes (or mb_convert_encoding has substituted), every rune in the
        // line is well-formed, so the parser's per-rune re-validation is pure
        // duplicate work — cs-24 (-039) turns it off. U+FFFD tail behaviour is
        // unchanged: the normalise above already owns it.
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
        }

        $parser = new Parser($handler, false);
        // The flattened `list<int>` a handler receives cannot distinguish
        // `38:2::80:160:240` (one parameter group) from `38;2;80;160;240`, so
        // the handler reads the ECMA-48 sub-parameter flags the parser PUSHES
        // it (SgrStateHandler implements SubparamsAwareHandler) before each CSI
        // dispatch. No bind-after-construct dance: the handler no longer holds
        // a reference back to the parser that dispatches it.
        $parser->feed($line);
        $parser->flush();
        $flush();

        $carry = $state;

        return $segments;
    }

    /**
     * The colour at xterm-256 palette index `$i`.
     *
     * The contract is the table itself: indices 0..255, nothing else. An
     * out-of-table index is a CALLER ERROR, not a lookup to be guessed at
     * (cs-23, -032): silently clamping or falling back used to let a
     * renderer-killing `#2b02b02b0` out of a public API, and `-1` a
     * historic white. Dispatch resolves the other half of the same
     * decision (SgrStateHandler::paletteColour ignores out-of-range
     * params exactly like xterm does); this helper owns the door.
     *
     * @throws InvalidArgumentException when $i is outside 0..255
     */
    public static function xterm256ToHex(int $i): string
    {
        if ($i < 0 || $i > 255) {
            throw new \InvalidArgumentException("xterm-256 palette index out of range: {$i} (the table has 256 entries, 0..255)");
        }

        if ($i < 16) {
            return self::ANSI16[$i];
        }
        if ($i >= 232) {
            $g = 8 + ($i - 232) * 10;
            return sprintf('#%02x%02x%02x', $g, $g, $g);
        }
        $idx = $i - 16;
        return sprintf(
            '#%02x%02x%02x',
            self::XTERM_LEVELS[intdiv($idx, 36)],
            self::XTERM_LEVELS[intdiv($idx, 6) % 6],
            self::XTERM_LEVELS[$idx % 6],
        );
    }
}
