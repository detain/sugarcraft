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
 * Stateless and reentrant. {@see parse()} keeps every piece of scan state in
 * locals and is itself static — `Scan::parse($frame, $width)` — so no
 * instance state exists to corrupt, share, or re-enter.
 *
 * ## Markup grammar (the decode side of {@see Mark::wrap()})
 *
 * A *tag* is `U+E000 [/] <id> U+E001` where `<id>` passes
 * {@see Mark::isValidId()} — exactly what {@see Mark} emits, and exactly what
 * a consumer's marker stripper removes before the frame reaches the
 * terminal. Every byte of a tag is zero-width. A tag with an EMPTY id
 * (`U+E000 U+E001`, `U+E000 / U+E001`) is also consumed as zero-width markup
 * but never opens or closes a zone — Mark cannot emit one, so it is foreign
 * PUA that happens to look like a tag (a Nerd Font / powerline glyph run, or
 * forged markup). Image-overlay markers cannot form such a pair: sugar-core
 * moved them to U+E002+, disjoint from this arena by construction.
 *
 * Decoding is bounded by construction: the walk is single-pass and every
 * malformed shape costs O(1), so there is no decode-side size cap of its own.
 * The emit-side caps ({@see Mark::MAX_ID_BYTES}, {@see Mark::MAX_CONTENT_BYTES})
 * bound what Mark writes, not what parse reads — an unterminated CSI or OSC
 * run swallows the rest of its frame by design (it may still be streaming
 * in), so feed parse() frames you expect to be terminal-sized. Vetting
 * untrusted megabyte blobs is the caller's job — the same duty as
 * sweeping untrusted text before marking it.
 *
 * A sentinel that does NOT begin a valid tag — a bare U+E000 whose would-be
 * id contains a space, an escape, a newline, a non-ASCII byte or more than
 * {@see Mark::MAX_ID_BYTES} bytes, or a bare U+E001 — is a *lone sentinel*.
 * Only its own 3 bytes are skipped (zero-width, matching
 * {@see \SugarCraft\Core\Util\Sanitize::stripZoneSentinels()}), and whatever
 * follows is measured as ordinary visible text. A lone sentinel can never
 * swallow a span of text, so column and row tracking stay exact.
 *
 * ## Malformed-markup matrix
 *
 * | Input                                   | Behavior                                 |
 * |-----------------------------------------|------------------------------------------|
 * | duplicate id (re-opened while open, or  | throws \InvalidArgumentException — the   |
 * | re-opened after it already closed)      | id can no longer address one zone        |
 * | orphan close (no matching open)         | tag skipped, no zone                     |
 * | unclosed open (no close before EOS)     | tag skipped, no zone                     |
 * | zone whose content paints no cell       | no zone (it has no cell to hit)          |
 * | clamped zone left painting no cell      | dropped too — never an edge sliver       |
 * |                                         | (viewport clamp, see `parse()` width)    |
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
 * returns the cursor to column 1 on the same row, as a terminal does. Zones
 * still open keep the extent they had already painted, never the cell they
 * opened on unless they painted it. A zone that paints again after the
 * `\r` widens its bbox to start at column 1. The same painted-cells-only
 * rule governs rows. A `\n` that ends a row holding no cell of the zone
 * extends nothing, so a box opened around 'abc' followed by two newlines
 * and its close tag stays a one-row box over 'abc'. The trailing blank
 * lines inside the tags are content the zone owns but never drew, and a
 * bbox may not claim undrawn rows.
 *
 * A zone still open at a `\n` that has painted nothing yet — its content
 * starts with `\n`, possibly behind SGR — owns no cell of the row being
 * ended. It is re-anchored at column 1 of the next row: the bbox it
 * would get had its zero-width open tag sat there.  This applies whatever
 * column the zone opened on, column 1 included: `'123456789' .
 * zone("\nabc")` and a bare `zone("\nabc")` are both cols 1-3 of row 2.
 * It deliberately departs from upstream bubblezone, which keeps the open
 * position (column and row) as Start (measured against bubblezone v2
 * 9615dc0, 0-based coordinates):
 *
 * | Input                          | bubblezone                 | port            |
 * |--------------------------------|----------------------------|-----------------|
 * | `'123456789' . zone("\nabc")`  | Start(9,0) End(2,1): StartX > EndX, so InBounds() never matches | cols 1-3, row 2 |
 * | `'12' . zone("\nabcdef")`      | Start(2,0) End(5,1): claims unpainted cols 3-6 of row 1, misses painted cols 1-2 of row 2 | cols 1-6, row 2 |
 * | `zone("\nabc")` (opened at column 1) | Start(0,0) End(2,1): claims unpainted cols 1-3 of row 1 | cols 1-3, row 2 |
 *
 * Copying any of these upstream shapes would emit a zone that can never be
 * hit or that claims cells it never painted (a neighbour's, or blank ones).
 * The port reports the cells the zone actually painted instead (the same
 * rule as the leading lone `\r`).
 *
 * ## Stripping
 *
 * {@see strip()} removes the tags from a frame without computing bounds —
 * it applies exactly the tag rule {@see parse()} decodes, so the cleaned
 * display string and the scanned bounds stay in lock-step. The one
 * documented divergence: strip's walk is byte-wise, so a sentinel-shaped
 * run hidden INSIDE an OSC/DCS payload is cut there while parse skips the
 * sequence whole — no zone shifts (payload is invisible markup either
 * way), but the surviving sequence's payload bytes come out mangled.
 * Sweep untrusted content before it reaches either side.
 *
 * Scope note — parsing is single-shot: one complete buffer in, every zone
 * it contains out. Chunked or incremental parsing is deliberately not
 * built here; a renderer that streams would add an iterator beside this
 * class rather than widening it.
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
     *                         the visible area. A zone that would paint no
     *                         cell after clamping — one opening fully past
     *                         the right edge, or any viewport narrower than
     *                         one column — is dropped, not collapsed onto
     *                         the edge column (an edge sliver would steal
     *                         clicks from the real content there).
     *                         null = no clamp (default).
     * @param LineWidthCache|null $cache When provided, the painted width of
     *                         every inert line (no zone markup, no lone CR, no
     *                         DCS/SOS/PM/APC string) is read from or stored in
     *                         it, so re-scanning unchanged lines skips the
     *                         per-cluster walk. The result is identical with
     *                         or without it. One parse() is one frame: the
     *                         cache's {@see LineWidthCache::beginFrame()} runs
     *                         first.
     *
     * @return array<string, Zone> id => Zone, in close order
     *
     * @throws \InvalidArgumentException When the same zone id is opened twice
     *         in one render (see the class docblock's malformed-markup matrix).
     */
    public static function parse(string $rendered, ?int $width = null, ?LineWidthCache $cache = null): array
    {
        /**
         * Zones whose open tag has been seen and whose close has not.
         *
         * startCol/startRow: the cell the open tag sat on.
         * maxCol/maxRow: furthest extent reached on earlier rows that painted / before a CR.
         * painted: the $painted counter at open — unchanged at close means
         *          the content occupied no cell.
         * segStart: $painted when the current run began — at open, then at
         *          each lone CR — so a CR can tell whether the run it ends
         *          actually painted.
         * afterCr: the current run began at a lone CR (it starts at column 1).
         * fromCol1: some run that began at a lone CR painted a cell, so the
         *          bbox starts at column 1.
         * rowPainted: $painted when the current row began — at open, then at
         *          each \n — so a row ending on \n can tell whether it holds
         *          any cell of this zone (rows that painted nothing extend
         *          neither maxCol nor maxRow).
         *
         * @var array<string, array{startCol:int,startRow:int,maxCol:int,maxRow:int,painted:int,segStart:int,afterCr:bool,fromCol1:bool,rowPainted:int}> $open
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
        $atLineStart = true;

        $cache?->beginFrame();

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
            if ($atLineStart === true && $cache !== null) {
                $atLineStart = false;
                $end = self::inertLineEnd($rendered, $i, $len);
                if ($end > $i) {
                    $line = substr($rendered, $i, $end - $i);
                    $w = $cache->lookup($line);
                    if ($w === null) {
                        $w = self::inertWidth($rendered, $i, $end, $len);
                        $cache->store($line, $w);
                    }
                    $col     += $w;
                    $painted += $w;
                    $i        = $end;
                    continue;
                }
            }

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
                            Lang::t('scan.duplicate_id', ['id' => Mark::escapeForMessage($id)])
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
                        'rowPainted' => $painted,
                    ];
                    continue;
                }
            }

            // CSI sequence — pass through with no width.  Following ctlseqs/
            // xterm, a bare ESC before the final byte CANCELS the sequence:
            // the walk stops at the ESC so the dispatcher re-enters and reads
            // it as the next introducer.  A CR/LF ends the sequence and is
            // distributed: the walk stops before it so the newline branches
            // below move the cursor exactly as for ordinary bytes.  Without
            // these breaks a truncated CSI swallowed the cancel byte and
            // whatever followed — text mis-measured, or a whole zone lost.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '[') {
                $i = self::skipCsi($rendered, $i, $len);
                continue;
            }

            // OSC sequence — pass through with no width.  A bare ESC that does
            // not introduce an ST cancels the OSC (ctlseqs/xterm): stop at the
            // ESC so the dispatcher re-enters.  A CR/LF ends the string and is
            // distributed, letting the newline branch move the cursor.  A
            // swallowed ESC here would eat the next sequence's introducer and
            // mis-measure everything after it.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === ']') {
                $i = self::skipOsc($rendered, $i, $len);
                continue;
            }

            // DCS / SOS / PM / APC strings — pass through with no width, the
            // same posture as OSC.  All four are ESC-introduced strings ended
            // by ST (ESC \); an unterminated one runs to end of render, since
            // the ST is their only resync point.  Without this branch their
            // bytes were measured as text and every zone below them started
            // columns too far right (sixel/kitty graphics hosts emit these).
            // A bare ESC that does not introduce an ST cancels the string
            // (ctlseqs/xterm): stop at the ESC so the dispatcher re-enters.
            // Unlike OSC, CR/LF are not breaks here — they are legitimate
            // payload bytes inside sixel streams, and ending the walk on one
            // would measure the rest of the graphics payload as text, the
            // exact drift this branch exists to prevent.
            if ($b === "\x1b" && (($rendered[$i + 1] ?? '') === 'P' || ($rendered[$i + 1] ?? '') === 'X' || ($rendered[$i + 1] ?? '') === '^' || ($rendered[$i + 1] ?? '') === '_')) {
                $j = $i + 2;
                while ($j < $len) {
                    if ($j + 1 < $len && $rendered[$j] === "\x1b" && $rendered[$j + 1] === '\\') {
                        $j += 2;
                        break;
                    }
                    if ($rendered[$j] === "\x1b") {
                        break;
                    }
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
                    if ($painted === $bounds['painted']) {
                        // Nothing painted yet: the row being ended holds no
                        // cell of this zone, and its open column is no
                        // evidence of where the content lands (the same reason
                        // a leading lone CR drops it).  Re-anchor the zone at
                        // column 1 of the next row, exactly as if its zero-width
                        // open tag sat there — what bubblezone itself reports
                        // for that placement, instead of the inverted (never
                        // hittable) rectangle it reports for this one:
                        // `'123456789' . zone("\nabc")` paints cols 1-3 of
                        // row 2, not a sliver at col 10 of rows 1-2.
                        $open[$id] = [
                            'startCol' => 1,
                            'startRow' => $row + 1,
                            'maxCol'   => 0,
                            'maxRow'   => $row + 1,
                            'painted'  => $painted,
                            'segStart' => $painted,
                            'afterCr'  => false,
                            'fromCol1' => false,
                            'rowPainted' => $painted,
                        ];
                        continue;
                    }
                    if ($painted > $bounds['rowPainted']) {
                        // The row being ended holds at least one cell this
                        // zone painted — the union rectangle extends over it.
                        // A row holding none of the zone's own cells claims
                        // nothing ('abc\n\n' before its close tag stays a
                        // one-row zone): the same painted-cells-only rule
                        // that drops a zero-paint zone entirely.
                        $bounds['maxCol'] = max($bounds['maxCol'], $colAtEol);
                        $bounds['maxRow'] = max($bounds['maxRow'], $row);
                    }
                    $bounds['rowPainted'] = $painted;
                    $open[$id] = $bounds;
                }
                $row++;
                $col = 1;
                $i++;
                $atLineStart = true;
                continue;
            }

            // Plain visible text — measure display width and advance.
            [$bytes, $w] = self::visible($rendered, $i, $len);
            $col     += $w;
            $painted += $w;
            $i       += $bytes;
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
     * @param array{startCol:int,startRow:int,maxCol:int,maxRow:int,painted:int,segStart:int,afterCr:bool,fromCol1:bool,rowPainted:int} $bounds
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
            // the zone really ends on its last PAINTED row — not merely the
            // row before the close tag, because trailing blank lines inside
            // the tags own no cell of theirs.
            $endCol = $maxCol;
            $endRow = max($bounds['maxRow'], $startRow);
        } else {
            $endCol = max($endCol, $maxCol);
            $endRow = max($endRow, $bounds['maxRow']);
        }
        if ($width !== null) {
            if ($width < 1) {
                // A viewport with fewer than one column owns no cell —
                // nothing can paint inside it, so nothing may claim it.
                return null;
            }
            if ($startCol > $width) {
                // The zone opens fully past the right edge. Clamping its
                // ends into [1, width] would collapse it onto the edge
                // column — a sliver that steals clicks from whatever the
                // terminal really painted there. No visible cell of the
                // frame belongs to the zone, so per the paints-no-cell /
                // claims-nothing rule it is dropped, not clamped.
                return null;
            }
            // Clamp BOTH ends into the viewport.  Clamping both sides
            // monotonically leaves in-viewport zones untouched; a residual
            // inverted span (endCol < startCol after clamping) paints no
            // cell either and is dropped below rather than kept as a
            // negative-width bbox.
            $startCol = max(1, min($startCol, $width));
            $endCol   = max(1, min($endCol, $width));
            if ($endCol < $startCol) {
                return null;
            }
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
     * Remove this package's PUA sentinel tags from a rendered frame, leaving
     * the visible content byte-for-byte intact.
     *
     * Applies exactly the tag rule {@see parse()} decodes, so the cleaned
     * display string and the scanned bounds stay in lock-step:
     *
     * - A *tag* is `U+E000 [/] <id> U+E001`, where `<id>` runs to the first
     *   U+E001 after the sentinel and passes
     *   {@see Mark::isValidId()} — the same predicate Mark
     *   enforces when encoding. The whole tag is removed; the content BETWEEN
     *   an open and its close is preserved.
     * - An empty id (`U+E000 U+E001`, `U+E000 / U+E001`) is consumed whole too.
     *   Parse treats it as zero-width foreign markup, so it must not reach
     *   the terminal either.
     * - Any other U+E000 — no U+E001 ahead, or an id with a space, newline,
     *   CR, escape, non-ASCII byte, or more than
     *   {@see Mark::MAX_ID_BYTES} bytes — is a *lone
     *   sentinel*. Only its own 3 bytes are dropped and the text after it is
     *   kept, because parse measures that text as visible cells. Swallowing it
     *   (the old "everything up to the next U+E001" rule) shifted every zone
     *   to its right or below it off the cells it actually painted.
     * - A bare U+E001 is likewise a lone sentinel (3 bytes dropped).
     *
     * Orphan closes and unclosed opens with a VALID id are still whole tags
     * and are removed whole, matching parse's lenient rows for viewport-clipped
     * zones.
     *
     * Lock-step caveat: the tag rule here is byte-wise, while parse walks the
     * cell grid and passes ANSI sequences through zero-width. The two
     * diverge only when a well-formed tag hides inside an OSC/CSI payload
     * (id charsets forbid ESC and control bytes, so an escape byte always
     * breaks the tag shape). Only hostile input is affected. {@see strip()}
     * removes bytes that were never tags for parse, so deleting markup parse
     * treated as invisible
     * markup cannot shift any zone the consumer will
     * act on. It does mangle
     * the surviving sequence's payload (a window title or DCS graphic loses
     * the cut bytes) — a documented consequence of the fail-safe byte-wise
     * posture (gate G11 default: document, do not re-shape the walk).
     * Sweeping untrusted content before it reaches either side stays the
     * consumer's duty (see {@see Sentinel}).
     */
    public static function strip(string $rendered): string
    {
        // Fast path — no sentinels present means nothing to strip.
        if (!str_contains($rendered, Sentinel::OPEN) && !str_contains($rendered, Sentinel::CLOSE)) {
            return $rendered;
        }

        $out       = '';
        $len       = strlen($rendered);
        $i         = 0;
        $runStart  = 0;
        // Cached offset of the next U+E001 at or after the current id start.
        // Offsets only grow during the walk, so the cache is refreshed only
        // once it falls behind — O(n) overall even for a frame full of
        // unmatched U+E000s (a fresh strpos per sentinel would be O(n^2) on
        // attacker-influenced text).
        $nextClose = -1;
        while ($i < $len) {
            if ($rendered[$i] === "\xEE" && $i + 2 < $len && $rendered[$i + 1] === "\x80") {
                $third = $rendered[$i + 2];

                // Bare U+E001 (EE 80 81) not consumed as a tag terminator.
                if ($third === "\x81") {
                    $out .= substr($rendered, $runStart, $i - $runStart);
                    $i += 3;
                    $runStart = $i;
                    continue;
                }

                // U+E000 (EE 80 80) — a tag only if a valid id runs from here
                // to the next U+E001.
                if ($third === "\x80") {
                    $out    .= substr($rendered, $runStart, $i - $runStart);
                    $idStart = ($rendered[$i + 3] ?? '') === '/' ? $i + 4 : $i + 3;
                    if ($nextClose !== false && $nextClose < $idStart) {
                        $nextClose = strpos($rendered, Sentinel::CLOSE, $idStart);
                    }
                    $i = ($nextClose !== false && self::isTagIdField($rendered, $idStart, $nextClose))
                        ? $nextClose + 3   // whole tag
                        : $i + 3;          // lone sentinel — its own bytes only
                    $runStart = $i;
                    continue;
                }
            }
            $i++;
        }
        $out .= substr($rendered, $runStart);
        return $out;
    }

    /**
     * Whether the id field [$idStart, $idEnd) makes the preceding U+E000 a
     * tag: empty (inert foreign markup, consumed whole) or a valid zone id.
     *
     * Mirrors {@see tagId()} through the public
     * {@see Mark::isValidId()} predicate. The length is checked before any
     * substr so an attacker-sized span between a stray U+E000 and a distant
     * U+E001 costs O(1) to reject.
     */
    private static function isTagIdField(string $rendered, int $idStart, int $idEnd): bool
    {
        $idLen = $idEnd - $idStart;
        if ($idLen === 0) {
            return true;
        }
        if ($idLen > Mark::MAX_ID_BYTES) {
            return false;
        }
        return Mark::isValidId(substr($rendered, $idStart, $idLen));
    }

    /**
     * Offset where the inert line starting at $i ends, or $i itself when the
     * line is not inert (or empty) and must be walked byte by byte.
     *
     * Inert means the walk's state can change only by the width it paints:
     * no sentinel byte pair (a tag, or the lead of one), no lone CR (it moves
     * the column back and touches open zones), and no DCS / SOS / PM / APC
     * introducer (those strings do not stop at LF, so they can run past the
     * line). A trailing CR of a CRLF is left outside the line: the main walk
     * still turns it into the newline.
     */
    private static function inertLineEnd(string $rendered, int $i, int $len): int
    {
        $nl  = strpos($rendered, "\n", $i);
        $end = $nl === false ? $len : $nl;
        if ($end > $i && $rendered[$end - 1] === "\r") {
            $end--;
        }
        if ($end <= $i) {
            return $i;
        }
        $line = substr($rendered, $i, $end - $i);
        if (str_contains($line, "\xEE\x80") || str_contains($line, "\r")
            || str_contains($line, "\x1bP") || str_contains($line, "\x1bX")
            || str_contains($line, "\x1b^") || str_contains($line, "\x1b_")
        ) {
            return $i;
        }
        return $end;
    }

    /**
     * Painted width of the inert run [$i, $end): the exact subset of the
     * main walk an inert line can reach (CSI, OSC, visible clusters), so a
     * cached width always equals what the walk would have measured.
     */
    private static function inertWidth(string $rendered, int $i, int $end, int $len): int
    {
        $width = 0;
        while ($i < $end) {
            if ($rendered[$i] === "\x1b" && ($rendered[$i + 1] ?? '') === '[') {
                $i = self::skipCsi($rendered, $i, $len);
                continue;
            }
            if ($rendered[$i] === "\x1b" && ($rendered[$i + 1] ?? '') === ']') {
                $i = self::skipOsc($rendered, $i, $len);
                continue;
            }
            [$bytes, $w] = self::visible($rendered, $i, $len);
            $width += $w;
            $i     += $bytes;
        }
        return $width;
    }

    /**
     * Offset just past the CSI sequence introduced at $i (ESC [). Stops
     * BEFORE a bare ESC (it cancels the sequence and introduces the next
     * one) and before a CR/LF (the newline branches must still see it).
     */
    private static function skipCsi(string $rendered, int $i, int $len): int
    {
        $j = $i + 2;
        while ($j < $len) {
            $c = ord($rendered[$j]);
            if ($c === 0x1b || $c === 0x0d || $c === 0x0a) {
                break;
            }
            $j++;
            if ($c >= 0x40 && $c <= 0x7e) {
                break;
            }
        }
        return $j;
    }

    /**
     * Offset just past the OSC string introduced at $i (ESC ]): through its
     * BEL or ST, or up to (not over) a cancelling bare ESC or a CR/LF.
     */
    private static function skipOsc(string $rendered, int $i, int $len): int
    {
        $j = $i + 2;
        while ($j < $len) {
            if ($rendered[$j] === "\x07") {
                $j++;
                break;
            }
            if ($j + 1 < $len && $rendered[$j] === "\x1b" && $rendered[$j + 1] === '\\') {
                $j += 2;
                break;
            }
            if ($rendered[$j] === "\x1b") {
                break;
            }
            if ($rendered[$j] === "\x0d" || $rendered[$j] === "\x0a") {
                break;
            }
            $j++;
        }
        return $j;
    }

    /**
     * Bytes consumed and cells painted by the visible text at $i: one
     * grapheme cluster ({@see Grapheme::next()}), or a whole run of printable
     * ASCII at once.
     *
     * The run is safe to take in one step because an ASCII byte never extends
     * a cluster (UAX #29 GB999) and each printable ASCII byte is one cell. Its
     * LAST byte is left to the cluster path when non-ASCII follows, since a
     * combining mark there joins it ("e" + U+0301 is one cell, not two).
     *
     * Known divergence class (portability): with ext-intl the cluster
     * boundary is the host ICU's UAX #29 answer; without it, PCRE's (see
     * {@see Grapheme}). The two agree on ZWJ emoji, flags and combining runs
     * but track different Unicode versions, so a code point newer than one of
     * them can still cluster differently host to host. The README's Scan rules
     * carry the consumer-facing version of this note.
     *
     * @return array{0:int,1:int} [bytes, cells]
     */
    private static function visible(string $rendered, int $i, int $len): array
    {
        static $printable = null;
        $printable ??= implode('', array_map('chr', range(0x20, 0x7e)));

        $run = strspn($rendered, $printable, $i);
        if ($run > 0 && $i + $run < $len && ord($rendered[$i + $run]) >= 0x80) {
            $run--;
        }
        if ($run > 0) {
            return [$run, $run];
        }

        $cluster = Grapheme::next($rendered, $i);
        return [strlen($cluster), Width::string($cluster)];
    }
}
