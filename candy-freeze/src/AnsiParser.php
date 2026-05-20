<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Splits a single line of ANSI-styled text into typed {@see Segment}s
 * for {@see SvgRenderer}.
 *
 * Handles SGR foreground and background colours (16-color / 256-color
 * / 24-bit RGB) and the standard attribute flags (bold, italic,
 * underline). Background colours are passed through to segments for
 * per-segment rendering.
 *
 * Other ANSI sequences (CSI cursor moves, OSC, etc.) pass through
 * silently — they have no visible effect in a static SVG.
 */
final class AnsiParser
{
    /** xterm 16-color palette as hex strings, used for `\x1b[3{0-7}m`. */
    private const ANSI16 = [
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
    public static function parse(string $line): array
    {
        $segments = [];
        $current  = new Segment('', null, false, false, false, null);
        $len = strlen($line);
        $i = 0;
        $textBuffer = '';
        $flush = function () use (&$segments, &$current, &$textBuffer): void {
            if ($textBuffer === '') {
                return;
            }
            $segments[] = new Segment(
                text:      $textBuffer,
                fg:        $current->fg,
                bold:      $current->bold,
                italic:    $current->italic,
                underline: $current->underline,
                bg:        $current->bg,
            );
            $textBuffer = '';
        };

        while ($i < $len) {
            $b = $line[$i];
            if ($b === "\x1b" && ($line[$i + 1] ?? '') === '[') {
                // CSI; only SGR (final byte 'm') affects styling.
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($line[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $body = substr($line, $i + 2, $j - $i - 3);
                $final = $line[$j - 1] ?? '';
                if ($final === 'm') {
                    $flush();
                    $current = self::applySgr($body, $current);
                }
                $i = $j;
                continue;
            }
            if ($b === "\x1b" && ($line[$i + 1] ?? '') === ']') {
                // OSC — skip through ST or BEL.
                $j = $i + 2;
                while ($j < $len) {
                    if ($line[$j] === "\x07") { $j++; break; }
                    if ($line[$j] === "\x1b" && ($line[$j + 1] ?? '') === '\\') { $j += 2; break; }
                    $j++;
                }
                $i = $j;
                continue;
            }
            $textBuffer .= $b;
            $i++;
        }
        $flush();
        return $segments;
    }

    /** Apply one CSI ... m parameter list to the running segment state. */
    private static function applySgr(string $body, Segment $cur): Segment
    {
        if ($body === '') {
            $params = [0];
        } else {
            $params = array_map('intval', explode(';', $body));
        }
        $fg        = $cur->fg;
        $bg        = $cur->bg;
        $bold      = $cur->bold;
        $italic    = $cur->italic;
        $underline = $cur->underline;

        $count = count($params);
        for ($i = 0; $i < $count; $i++) {
            $p = $params[$i];
            if ($p === 0) {
                $fg = null; $bg = null; $bold = false; $italic = false; $underline = false;
                continue;
            }
            if ($p === 1) { $bold = true; continue; }
            if ($p === 3) { $italic = true; continue; }
            if ($p === 4) { $underline = true; continue; }
            if ($p === 22) { $bold = false; continue; }
            if ($p === 23) { $italic = false; continue; }
            if ($p === 24) { $underline = false; continue; }
            if ($p === 39) { $fg = null; continue; }
            if ($p === 49) { $bg = null; continue; }
            if ($p >= 30 && $p <= 37) {
                $fg = self::ANSI16[$p - 30] ?? null;
                continue;
            }
            if ($p >= 90 && $p <= 97) {
                $fg = self::ANSI16[$p - 90 + 8] ?? null;
                continue;
            }
            if ($p >= 40 && $p <= 47) {
                $bg = self::ANSI16[$p - 40] ?? null;
                continue;
            }
            if ($p >= 100 && $p <= 107) {
                $bg = self::ANSI16[$p - 100 + 8] ?? null;
                continue;
            }
            if ($p === 38 && isset($params[$i + 1])) {
                $mode = $params[$i + 1];
                if ($mode === 5 && isset($params[$i + 2])) {
                    $fg = self::xterm256ToHex($params[$i + 2]);
                    $i += 2;
                    continue;
                }
                if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                    $fg = sprintf('#%02x%02x%02x', $params[$i + 2], $params[$i + 3], $params[$i + 4]);
                    $i += 4;
                    continue;
                }
            }
            if ($p === 48 && isset($params[$i + 1])) {
                $mode = $params[$i + 1];
                if ($mode === 5 && isset($params[$i + 2])) {
                    $bg = self::xterm256ToHex($params[$i + 2]);
                    $i += 2;
                    continue;
                }
                if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                    $bg = sprintf('#%02x%02x%02x', $params[$i + 2], $params[$i + 3], $params[$i + 4]);
                    $i += 4;
                    continue;
                }
            }
        }
        return new Segment('', $fg, $bold, $italic, $underline, $bg);
    }

    private static function xterm256ToHex(int $i): string
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
