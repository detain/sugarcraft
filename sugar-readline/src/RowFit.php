<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Core\Util\Width;

/**
 * Cell-aware fitting of rendered rows to a prompt's display bound.
 *
 * Every shipped prompt draws one or more rows per repaint; a row wider
 * than the terminal wraps and desyncs the cursor-up erase maths in
 * {@see Readline::repaint()} — the repaint staircase. Fitting happens at
 * the segment level so styling survives: a segment that no longer fits
 * completely is clipped with the shared `…` budget (one cell) and the
 * rest of the row is dropped rather than pushed past the bound.
 *
 * All text handed to this class must be ANSI-free (pass it through
 * {@see Ansi::sanitize()} first); styles are re-applied around each
 * segment, so the cell maths never walks inside an escape sequence.
 */
final class RowFit
{
    /**
     * Compose one row from [plainText, styleCodesOrNull] segments,
     * clipped to $cells. A null or non-positive bound renders the row
     * unbounded (legacy shape, byte-for-byte).
     *
     * @param list<array{0:string,1:string|null}> $segments
     */
    public static function row(array $segments, ?int $cells): string
    {
        if ($cells === null || $cells < 1) {
            $out = '';
            foreach ($segments as [$text, $style]) {
                $out .= $style === null ? $text : Ansi::wrap($text, $style);
            }
            return $out;
        }

        $out  = '';
        $used = 0;
        foreach ($segments as [$text, $style]) {
            $width = Width::of($text);
            if ($used + $width <= $cells) {
                $out .= $style === null ? $text : Ansi::wrap($text, $style);
                $used += $width;
                continue;
            }
            $room = $cells - $used;
            if ($room >= 1) {
                $clip = self::clip($text, $room);
                $out .= $style === null ? $clip : Ansi::wrap($clip, $style);
            }
            // The budget ended mid-segment; later segments are dropped.
            return $out;
        }

        return $out;
    }

    /**
     * Clip plain (ANSI-free) text to $cells columns, spending one column
     * on the `…` marker when anything is cut — the same budget law the
     * TextPrompt buffer window and its auxiliary rows use.
     */
    public static function clip(string $text, int $cells): string
    {
        if ($cells < 1) {
            return '';
        }
        if (Width::of($text) <= $cells) {
            return $text;
        }
        $out  = '';
        $used = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            $w = max(1, Width::of($ch));
            if ($used + $w > $cells - 1) {
                break;
            }
            $out .= $ch;
            $used += $w;
        }
        return $out . '…';
    }

    private function __construct()
    {
    }
}
