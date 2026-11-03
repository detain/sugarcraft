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
 * Other ANSI sequences (CSI cursor moves, OSC, etc.) pass through
 * silently — they have no visible effect in a static SVG.
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
     * Parse one line of ANSI text into a list of styled segments.
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
            $segments[] = new Segment(
                text:      $textBuf,
                fg:        $state->fg,
                bold:      $state->bold,
                italic:    $state->italic,
                underline: $state->underline,
                bg:        $state->bg,
            );
            $textBuf = '';
        };

        $handler = new SgrStateHandler($state, $textBuf, $flush, $segments);

        // Feed a UTF-8-clean line so the strict parser replaces malformed
        // bytes instead of forwarding them verbatim into the document (M12).
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
        }

        $parser = new Parser($handler, true);
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

    public static function xterm256ToHex(int $i): string
    {
        if ($i < 16) {
            return self::ANSI16[$i] ?? '#ffffff';
        }
        if ($i >= 232) {
            $g = 8 + ($i - 232) * 10;
            return sprintf('#%02x%02x%02x', $g, $g, $g);
        }
        $idx = $i - 16;
        $levels = [0, 95, 135, 175, 215, 255];
        return sprintf(
            '#%02x%02x%02x',
            $levels[intdiv($idx, 36)],
            $levels[intdiv($idx, 6) % 6],
            $levels[$idx % 6],
        );
    }
}
