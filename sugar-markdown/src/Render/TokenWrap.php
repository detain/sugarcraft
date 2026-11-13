<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Render;

use SugarCraft\Core\Util\Width;

/**
 * Word-wrapping with a fast path for overlong tokens (SM-S22 mitigation).
 *
 * `Width::wrapAnsi()` walks every input one grapheme cluster at a time,
 * which costs a few microseconds per byte: a paragraph holding a single
 * 400 KB token (a base64 blob, a pasted hash wall) spends seconds inside
 * the wrap even though its hard-break rows are pure repetition. The full
 * cure is cursor-based hard-splitting in sugar-core's Width (cross-target
 * route); until it lands, this class takes the same tokens out of the
 * per-cluster loop on the Markdown side.
 *
 * Strategy: find whitespace-free runs longer than four times the wrap
 * width in BYTES, pack each of them greedily into at most `$max`-cell
 * rows without a per-cluster walk (byte `str_split` when the run is
 * printable ASCII — one byte is one cell there), and let `wrapAnsi()`
 * handle everything else. Run boundaries and row carry-over reproduce
 * `wrapAnsi()`'s hard-break semantics exactly — the first packed row
 * starts after the pending line flushes, the last row merges back into
 * the following text — so the output is byte-identical to the plain
 * `Width::wrapAnsi()` call for every input, including the runs the
 * mitigation declines to touch (control bytes, ESC sequences, tokens
 * that turn out to fit after all).
 */
final class TokenWrap
{
    /**
     * Byte-length multiple of the wrap width above which a run is worth
     * packing outside `wrapAnsi()`. Below it, the per-cluster walk is
     * cheaper than the split, and behaviour is identical anyway.
     */
    private const GIANT_FACTOR = 4;

    /**
     * Wrap `$s` at `$max` cells, byte-identical to `Width::wrapAnsi($s, $max)`.
     */
    public static function wrap(string $s, int $max): string
    {
        // Mirrors wrapAnsi()'s guard: nothing to do, input passes through.
        if ($max <= 0 || $s === '') {
            return $s;
        }

        // Byte-level split on the ASCII whitespace set — UTF-8 continuation
        // bytes are >= 0x80, so no sequence can ever be cut. Odd indices are
        // the captured whitespace runs (the delimiters themselves).
        $parts = preg_split('/([ \t\n]+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return Width::wrapAnsi($s, $max);
        }

        $threshold = self::GIANT_FACTOR * $max;
        $hasGiant = false;
        foreach ($parts as $i => $part) {
            if ((($i & 1) === 0) && strlen($part) > $threshold) {
                $hasGiant = true;
                break;
            }
        }
        if (!$hasGiant) {
            // The overwhelmingly common case: hand the whole body to the
            // untouched native path.
            return Width::wrapAnsi($s, $max);
        }

        $rows = [];
        // $carry holds the last packed row of the previous overlong run; it
        // must merge with the following text exactly as wrapAnsi() would
        // commit it onto the current line. $gap accumulates everything
        // between giant runs, in order.
        $carry = '';
        $gap = '';
        foreach ($parts as $i => $part) {
            if (($i & 1) === 1) {
                $gap .= $part;
                continue;
            }
            if (
                strlen($part) <= $threshold
                // Control bytes (including ESC — the ANSI passes wrapAnsi()
                // attaches to the running word) and DEL: count the run,
                // leave its packing to the native path.
                || preg_match('/[\x00-\x1f\x7f]/', $part) === 1
            ) {
                $gap .= $part;
                continue;
            }
            $packed = self::packRows($part, $max);
            if (count($packed) < 2) {
                // The run fits on one row after all (heavy combining, for
                // instance). Native packing keeps it glued to its line, so
                // feed it through untouched as ordinary gap text.
                $gap .= $part;
                continue;
            }
            $blob = self::emit($carry . $gap, $max);
            if ($blob !== null) {
                $rows[] = $blob;
            }
            // The first flush of a giant run pushes wrapAnsi()'s pending
            // line (already emitted above as the blob's last row) and then
            // each full row of the run; only the final row stays pending.
            $rows[] = implode("\n", array_slice($packed, 0, -1));
            $carry = (string) $packed[count($packed) - 1];
            $gap = '';
        }
        $tail = self::emit($carry . $gap, $max);
        if ($tail !== null) {
            $rows[] = $tail;
        }

        return implode("\n", $rows);
    }

    /**
     * Run the gap text (with any carried-over packed row prefixed) through
     * the native wrapper, or answer null when there is nothing pending —
     * wrapAnsi() only flushes a non-empty line, and an empty gap must not
     * fabricate the blank row that `implode([""])` would encode.
     */
    private static function emit(string $text, int $max): ?string
    {
        return $text === '' ? null : Width::wrapAnsi($text, $max);
    }

    /**
     * Greedily split one whitespace-free run into rows of at most `$max`
     * cells, reproducing wrapAnsi()'s word-overflow packing.
     *
     * @return non-empty-list<string>
     */
    private static function packRows(string $run, int $max): array
    {
        // Printable ASCII: cells equal bytes, and one str_split replaces
        // the whole per-cluster walk. Range starts at 0x21 because tabs,
        // spaces and newlines delimit runs by construction.
        if (preg_match('/^[\x21-\x7e]+$/', $run) === 1) {
            return str_split($run, $max);
        }

        $rows = [];
        $row = '';
        $rowWidth = 0;
        $len = strlen($run);
        $i = 0;
        while ($i < $len) {
            $cluster = Width::nextCluster($run, $i);
            $cw = Width::string($cluster);
            if ($cw > $max) {
                // A single cluster wider than the budget rides alone, as
                // wrapAnsi()'s over-wide branch does.
                if ($row !== '') {
                    $rows[] = $row;
                    $row = '';
                    $rowWidth = 0;
                }
                $rows[] = $cluster;
                $i += strlen($cluster);
                continue;
            }
            if ($rowWidth + $cw > $max) {
                $rows[] = $row;
                $row = $cluster;
                $rowWidth = $cw;
            } else {
                $row .= $cluster;
                $rowWidth += $cw;
            }
            $i += strlen($cluster);
        }
        if ($row !== '') {
            $rows[] = $row;
        }

        return $rows === [] ? [$run] : $rows;
    }
}
