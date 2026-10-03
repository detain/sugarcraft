<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Util\Width;

/**
 * Parse zone sentinels from a rendered string and compute bounding boxes
 * for each discovered zone.
 *
 * Mirrors bubblezone's Scan function — computes bounding boxes by walking
 * the terminal cell grid and honouring CJK wide-char column accounting
 * via {@see Width::string()}.
 *
 * Stateless and reentrant: {@see parse()} keeps every piece of scan state in
 * locals, so one instance may be reused, shared, or re-entered freely.
 *
 * ## Markup grammar (the decode side of {@see Mark::wrap()})
 *
 * A *tag* is `U+E000 [/] <id> U+E001` where `<id>` passes
 * {@see Mark::isValidId()} — exactly what {@see Mark} emits, and exactly what
 * a consumer's marker stripper removes before the frame reaches the
 * terminal. Every byte of a tag is zero-width. A tag with an EMPTY id
 * (`U+E000 U+E001`, `U+E000 / U+E001`) is also consumed as zero-width markup
 * but never opens or closes a zone — Mark cannot emit one, so it is foreign
 * PUA (an image-marker pair, a Nerd Font glyph run) that happens to look like
 * a tag.
 *
 * A sentinel that does NOT begin a valid tag — a bare U+E000 whose would-be
 * id contains a space, an escape, a newline, a non-ASCII byte or more than
 * {@see Mark::MAX_ID_BYTES} bytes, or a bare U+E001 — is a *lone sentinel*:
 * only its own 3 bytes are skipped (zero-width, matching
 * {@see \SugarCraft\Core\Util\Sanitize::stripZoneSentinels()}), and whatever
 * follows is measured as ordinary visible text. A lone sentinel can never
 * swallow a span of text, so column and row tracking stay exact.
 *
 * ## Malformed-markup matrix
 *
 * | Input                                   | Behaviour                                |
 * |-----------------------------------------|------------------------------------------|
 * | duplicate id (re-opened while open, or  | throws \InvalidArgumentException — the   |
 * | re-opened after it already closed)      | id can no longer address one zone        |
 * | orphan close (no matching open)         | tag skipped, no zone                     |
 * | unclosed open (no close before EOS)     | tag skipped, no zone                     |
 * | zone whose content paints no cell       | no zone (it has no cell to hit)          |
 * | lone sentinel / invalid id              | 3 sentinel bytes skipped, text measured  |
 *
 * The orphan-close and unclosed-open rows are deliberately lenient: a frame
 * clipped to the viewport's height legitimately cuts a zone's open tag off
 * the top (scrolled away) or its close tag off the bottom. Throwing there
 * would make every partially-visible zone take the whole frame's registry
 * down with it. A duplicate id has no such innocent source — two zones that
 * share an id are a caller bug — so it is the one case that throws.
 *
 * ## Line endings
 *
 * `\n` and `\r\n` both end a row (column back to 1, row + 1). A lone `\r`
 * returns the cursor to column 1 on the SAME row, as a terminal does; zones
 * still open keep the extent they had already painted — never the cell they
 * opened on unless they painted it — and a zone that paints again after the
 * `\r` widens its bbox to start at column 1.
 *
 * Design note — streaming/chunked parsing: for large terminals or
 * streaming renderers, a ScanIterator (implementing IteratorAggregate)
 * could yield zones incrementally as each chunk is scanned, avoiding
 * a full in-memory parse of the entire buffer.  The current Scan class
 * is suitable for single-shot scans; factor chunk support out if needed.
 */
final class Scan
{
    /**
     * Feed a rendered string through the scanner and return all discovered
     * zones.
     *
     * @param string $rendered The rendered string containing zone sentinels.
     * @param int|null $width  When provided, both startCol and endCol are
     *                         clamped into [1, width].  Useful when rendering
     *                         in a known viewport: zones cannot extend past
     *                         the visible area, and a zone that opens beyond
     *                         the right edge collapses to a degenerate
     *                         column at the edge instead of an inverted bbox.
     *                         null = no clamp (default).
     *
     * @return array<string, Zone> id => Zone, in close order
     *
     * @throws \InvalidArgumentException When the same zone id is opened twice
     *         in one render (see the class docblock's malformed-markup matrix).
     */
    public function parse(string $rendered, ?int $width = null): array
    {
        /**
         * Zones whose open tag has been seen and whose close has not.
         *
         * startCol/startRow: the cell the open tag sat on.
         * maxCol/maxRow: furthest extent reached on earlier rows / before a CR.
         * painted: the $painted counter at open — unchanged at close means
         *          the content occupied no cell.
         * segStart: $painted when the current run began — at open, then at
         *          each lone CR — so a CR can tell whether the run it ends
         *          actually painted.
         * afterCr: the current run began at a lone CR (it starts at column 1).
         * fromCol1: some run that began at a lone CR painted a cell, so the
         *          bbox starts at column 1.
         *
         * @var array<string, array{startCol:int,startRow:int,maxCol:int,maxRow:int,painted:int,segStart:int,afterCr:bool,fromCol1:bool}> $open
         */
        $open = [];
        /** @var array<string, Zone> $zones */
        $zones = [];
        /** @var array<string, true> $closed every id already closed (emitted or dropped) */
        $closed = [];

        $row     = 1;
        $col     = 1;
        $painted = 0; // total display cells painted so far
        $len     = strlen($rendered);
        $i       = 0;

        // Precompute every close-sentinel byte offset in one O(n) pass so the
        // per-open-sentinel id-terminator lookup below is an O(log n) binary
        // search instead of a fresh forward strpos rescan.  A render with many
        // unmatched open sentinels would otherwise rescan to EOS for each one —
        // O(n^2), a mild DoS on reflected/attacker-influenced text.
        $closeAt = [];
        $p = 0;
        while (($p = strpos($rendered, Sentinel::CLOSE, $p)) !== false) {
            $closeAt[] = $p;
            $p += 3;
        }

        while ($i < $len) {
            $b = $rendered[$i];

            if ($b === "\xEE" && $i + 2 < $len && $rendered[$i + 1] === "\x80") {
                $byte3 = $rendered[$i + 2];

                // Lone U+E001 (EE 80 81) — not consumed as a tag terminator,
                // so it is a stray: zero-width, skip its 3 bytes only.
                if ($byte3 === "\x81") {
                    $i += 3;
                    continue;
                }

                // U+E000 (EE 80 80) — a tag only if a valid id runs from here
                // to the next U+E001.
                if ($byte3 === "\x80") {
                    $isClose = ($i + 3 < $len) && ($rendered[$i + 3] === '/');
                    $idStart = $isClose ? $i + 4 : $i + 3;
                    $idEnd   = self::firstCloseAtOrAfter($closeAt, $idStart);
                    $id      = self::tagId($rendered, $idStart, $idEnd);

                    if ($id === null) {
                        // Lone sentinel: never let it swallow the text up to
                        // some later U+E001 — skip its own bytes and measure
                        // what follows as visible cells.
                        $i += 3;
                        continue;
                    }

                    $i = $idEnd + 3;

                    if ($id === '') {
                        // Well-formed-looking but unemittable: zero-width
                        // foreign PUA, never a zone.
                        continue;
                    }

                    if ($isClose) {
                        if (isset($open[$id]) === true) {
                            $zone = self::closeZone($id, $open[$id], $col, $row, $painted, $width);
                            unset($open[$id]);
                            $closed[$id] = true;
                            if ($zone !== null) {
                                $zones[$id] = $zone;
                            }
                        }
                        continue;
                    }

                    // Opening tag.  A duplicate id — one still open (a second
                    // open would clobber the first zone's start, merging two
                    // zones into one wrong bounding box) or one already closed
                    // earlier in this same render (silent last-write-wins) — is
                    // a caller bug: the id can no longer address a single zone.
                    // Reject it rather than emit a corrupt bbox, mirroring
                    // Mark::wrap()'s reject-on-invalid contract.
                    if ((isset($open[$id]) || isset($closed[$id])) === true) {
                        throw new \InvalidArgumentException(
                            Lang::t('scan.duplicate_id', ['id' => var_export($id, true)])
                        );
                    }
                    $open[$id] = [
                        'startCol' => $col,
                        'startRow' => $row,
                        'maxCol'   => $col,
                        'maxRow'   => $row,
                        'painted'  => $painted,
                        'segStart' => $painted,
                        'afterCr'  => false,
                        'fromCol1' => false,
                    ];
                    continue;
                }
            }

            // CSI sequence — pass through with no width.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($rendered[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $i = $j;
                continue;
            }

            // OSC sequence — pass through with no width.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($rendered[$j] === "\x07") { $j++; break; }
                    if ($j + 1 < $len && $rendered[$j] === "\x1b" && $rendered[$j + 1] === '\\') { $j += 2; break; }
                    $j++;
                }
                $i = $j;
                continue;
            }

            // Carriage return.  Handled byte-wise BEFORE the grapheme path:
            // grapheme_extract() returns "\r\n" as ONE zero-width cluster, so
            // without this branch a CRLF advanced neither row nor column and
            // every zone below it registered a row too high.
            if ($b === "\r") {
                $colAtEol = $width !== null ? min($col - 1, $width) : $col - 1;
                if (($rendered[$i + 1] ?? '') === "\n") {
                    // CRLF — a newline; the "\n" branch below finishes it.
                    $i++;
                    continue;
                }
                // Lone CR: back to column 1 on the same row.  Each open zone
                // keeps only the extent it really painted: the run the CR ends
                // contributes its end column only when it painted a cell, and
                // a zone that has painted nothing at all drops the startCol
                // seed in maxCol — the cell it opened on was never its own
                // (`'123456789' . zone("\rabc")` paints cols 1-3, not 1-10).
                foreach ($open as $id => $bounds) {
                    if ($painted > $bounds['segStart']) {
                        $bounds['maxCol'] = max($bounds['maxCol'], $colAtEol);
                        if ($bounds['afterCr'] === true) {
                            $bounds['fromCol1'] = true;
                        }
                    } elseif ($painted === $bounds['painted']) {
                        $bounds['maxCol'] = 0;
                    }
                    $bounds['segStart'] = $painted;
                    $bounds['afterCr']  = true;
                    $open[$id] = $bounds;
                }
                $col = 1;
                $i++;
                continue;
            }

            // Newline — advance row, reset column; extend open zones to
            // the end of the current line before moving on.
            if ($b === "\n") {
                $colAtEol = $width !== null ? min($col - 1, $width) : $col - 1;
                foreach ($open as $id => $bounds) {
                    $bounds['maxCol'] = max($bounds['maxCol'], $colAtEol);
                    $bounds['maxRow'] = max($bounds['maxRow'], $row);
                    $open[$id] = $bounds;
                }
                $row++;
                $col = 1;
                $i++;
                continue;
            }

            // Plain visible character — measure display width and advance.
            $cluster  = self::nextGrapheme($rendered, $i);
            $w        = Width::string($cluster);
            $col     += $w;
            $painted += $w;
            $i       += strlen($cluster);
        }

        return $zones;
    }

    /**
     * The id of the tag whose id field spans [$idStart, $idEnd), or null when
     * the sentinel at $idStart - 3 (or - 4) does not begin a valid tag.
     *
     * Returns '' for an empty id field, which the caller consumes as inert
     * markup.  The length is checked before any substr so an attacker-sized
     * span between a stray U+E000 and a far U+E001 costs O(1) to reject.
     */
    private static function tagId(string $rendered, int $idStart, int $idEnd): ?string
    {
        if ($idEnd === -1) {
            return null; // no terminator anywhere ahead
        }
        $idLen = $idEnd - $idStart;
        if ($idLen === 0) {
            return '';
        }
        if ($idLen > Mark::MAX_ID_BYTES) {
            return null;
        }
        $id = substr($rendered, $idStart, $idLen);
        return Mark::isValidId($id) ? $id : null;
    }

    /**
     * Build the bounding box for a zone whose close tag sits at ($col, $row),
     * or null when its content painted no cell at all.
     *
     * An empty-content zone used to back its end column off to its start
     * column and claim the cell of whatever painted next — a phantom 1-cell
     * zone stealing its neighbour's clicks.  With nothing painted there is no
     * cell to hit, so no zone is emitted (the id still counts as used for the
     * duplicate guard).
     *
     * A zone that painted after a lone CR starts at column 1 — whichever CR
     * it was, not only the last one (`"AB\rCD\r"` painted cols 1-2 even
     * though nothing follows the final CR).
     *
     * @param array{startCol:int,startRow:int,maxCol:int,maxRow:int,painted:int,segStart:int,afterCr:bool,fromCol1:bool} $bounds
     */
    private static function closeZone(string $id, array $bounds, int $col, int $row, int $painted, ?int $width): ?Zone
    {
        if ($painted === $bounds['painted']) {
            return null;
        }

        $startRow = $bounds['startRow'];
        $maxCol   = $bounds['maxCol'];
        $startCol = $bounds['startCol'];
        $fromCol1 = $bounds['fromCol1']
            || ($bounds['afterCr'] === true && $painted > $bounds['segStart']);
        if ($fromCol1 === true) {
            // Content painted after a lone CR landed from column 1.
            $startCol = 1;
        }

        // End marker sits after the last visible cell; back the end up by one
        // column.
        $endCol = max($startCol, $col - 1);
        $endRow = $row;
        if ($endRow > $startRow && $col === 1) {
            // Closed at the start of a later row (content ended with "\n"):
            // the zone really ends on the previous row.
            $endCol = $maxCol;
            $endRow = $row - 1;
        } else {
            $endCol = max($endCol, $maxCol);
            $endRow = max($endRow, $bounds['maxRow']);
        }
        if ($width !== null) {
            // Clamp BOTH ends into the viewport.  A zone that opens past the
            // right edge would otherwise keep an unclamped startCol above a
            // clamped endCol — an inverted bbox with a negative width().
            // Clamping both sides monotonically leaves in-viewport zones
            // untouched and collapses fully-offscreen ones to a degenerate
            // column at the edge.
            $startCol = max(1, min($startCol, $width));
            $endCol   = max(1, min($endCol, $width));
        }
        return new Zone($id, $startCol, $startRow, $endCol, $endRow);
    }

    /**
     * Return the first close-sentinel offset in the ascending-sorted
     * $positions that is >= $from, or -1 when none remain.
     *
     * The offsets are precomputed once per {@see parse()} call, so this
     * binary search replaces the O(n) forward strpos rescan that ran for
     * every open sentinel — bounding a many-unmatched-open render at
     * O(n log n) instead of O(n^2).
     *
     * @param list<int> $positions
     */
    private static function firstCloseAtOrAfter(array $positions, int $from): int
    {
        $lo = 0;
        $hi = count($positions);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($positions[$mid] < $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo < count($positions) ? $positions[$lo] : -1;
    }

    /**
     * Return the next grapheme cluster starting at byte offset $i.
     *
     * Edge case: grapheme_extract() can return an empty string '' when
     * the offset $i lands mid-grapheme (e.g., in the middle of a combine
     * sequence).  The fallback UTF-8 byte scan handles this by returning
     * the next valid multi-byte sequence starting at $i.  This can produce
     * a grapheme that is technically a continuation of a sequence started
     * before $i, but in terminal output this is unlikely to cause issues
     * since combining characters are normally printed after their base.
     */
    private static function nextGrapheme(string $s, int $i): string
    {
        if (function_exists('grapheme_extract') === true) {
            $next = 0;
            $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            if (is_string($cluster) === true && $cluster !== '') {
                return $cluster;
            }
        }
        $b = ord($s[$i]);
        $bytes = match (true) {
            ($b & 0x80) === 0    => 1,
            ($b & 0xe0) === 0xc0 => 2,
            ($b & 0xf0) === 0xe0 => 3,
            ($b & 0xf8) === 0xf0 => 4,
            default              => 1,
        };
        return substr($s, $i, $bytes);
    }
}
