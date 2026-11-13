<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;

/**
 * Cell-accurate measurement and clipping for frames that carry zone markers.
 *
 * The compositor clips, pads and anchors overlay rows in DISPLAY CELLS, but
 * sugar-mouse markers (U+E000 id U+E001 envelope pairs) and OSC/CSI escapes
 * cost zero columns on screen and in {@see \SugarCraft\Mouse\Scanner} — while
 * the raw bytes are printable ASCII (the id rides between the sentinels in
 * plain text). Measuring such a line with a byte-walking width function
 * charges the envelope and every id byte as cells, so anchors slide right,
 * padding miscounts, and clipping lands past the intended column — the
 * defect class this class exists to kill.
 *
 * Every walk here is BYTE-PARITY with {@see Veil::paintRow()} (the diff
 * model's own tokenizer, which is itself the emulator's): the two must agree
 * on where each visible cell lands or the diff and the hit-zones drift apart.
 *
 * Zone-aware clipping additionally keeps envelopes BALANCED across a cut:
 * a truncated tail that stops inside a zone gets a synthesized close
 * envelope, a dropped head that started inside one gets its open envelope
 * replayed, and an open OSC 8 link is closed rather than left to swallow the
 * rest of the spliced row.
 *
 * @internal Compositor plumbing; the public surface is Veil.
 */
final class MarkedFrame
{
    /** OSC 8 terminator this class synthesizes when a cut must close a link. */
    private const LINK_CLOSE = "\x1b]8;;\x07";

    private function __construct()
    {
    }

    /**
     * Display cells $line occupies — zero for marker envelopes and escapes.
     *
     * SO-04 (sugar-overlay-029): width-only byte walk, no segment list.
     * The old body materialized the full segments() output — a 4-key array
     * per cluster — just to sum the width column, over every background and
     * foreground line per composite and (via Slide) per line per tick. The
     * arms below mirror segments() control-for-control (every skipped arm
     * charges zero cells); tests pin the two paths to agree on a hostile
     * corpus, so the walker can never silently drift from the tokenizer.
     */
    public static function measure(string $line): int
    {
        $width = 0;
        $len = \strlen($line);
        $i = 0;

        while ($i < $len) {
            $byte = $line[$i];

            if ($byte === "\e" && $i + 1 < $len) {
                $intro = $line[$i + 1];

                if ($intro === '[') {
                    // CSI: params are bytes < 0x40, one final byte closes.
                    $p = $i + 2;
                    while ($p < $len && \ord($line[$p]) < 0x40) {
                        $p++;
                    }
                    $i = $p < $len ? $p + 1 : $len;
                    continue;
                }

                if ($intro === ']') {
                    // OSC: BEL swallows its byte; a real ST (ESC \) swallows
                    // both; a bare ESC ends it AT the introducer, which the
                    // next round re-reads as its own escape.
                    $tail = $i + 2 + \strcspn($line, "\x07\x1b", $i + 2);
                    if ($tail < $len && $line[$tail] === "\x1b" && ($line[$tail + 1] ?? '') === '\\') {
                        $i = $tail + 2;
                    } else {
                        $i = $tail < $len && $line[$tail] === "\x07" ? $tail + 1 : $tail;
                    }
                    continue;
                }

                // Any other two-byte ESC sequence: zero columns.
                $i += 2;
                continue;
            }

            if ($byte === "\xEE" && $i + 2 < $len && $line[$i + 1] === "\x80") {
                if ($line[$i + 2] === "\x80") { // U+E000 OPEN — id rides to CLOSE
                    $close = \strpos($line, Sanitize::ZONE_SENTINEL_CLOSE, $i + 3);
                    // Unmatched open swallows the tail (paintRow parity);
                    // a matched pair consumes through the CLOSE sentinel.
                    $i = $close === false ? $len : $close + 3;
                    continue;
                }
                if ($line[$i + 2] === "\x81") { // U+E001 CLOSE without an OPEN
                    $i += 3;
                    continue;
                }
            }

            $cluster = Cluster::next($line, $i);
            $width += Width::of($cluster);
            $i += \strlen($cluster);
        }

        return $width;
    }

    /**
     * {@see measure()} over an already-tokenized row — the shared walk the
     * compositor carries (SO-03: tokenize each row ONCE per composite).
     *
     * @param list<array{kind:string, bytes:string, width:int, id:string}> $segments
     */
    public static function measureSegments(array $segments): int
    {
        $width = 0;
        foreach ($segments as $seg) {
            $width += $seg['width'];
        }

        return $width;
    }

    /**
     * The first $max visible cells of $line, marker- and link-balanced.
     *
     * Escapes (SGR and otherwise) after the cut are harvested exactly as
     * {@see Width::truncateAnsi()} does, so a reset is never lost; OSC 8
     * sequences are the exception — an opener whose link text was cut away
     * is NOT re-emitted (it would leave the spliced row inside a phantom
     * link), while the closer of a link still active at the cut is kept.
     * A zone cut open at the end of the output gets a synthesized close
     * envelope so the scanner cannot bleed the zone across the rest of the
     * row.
     */
    public static function take(string $line, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        return self::takeSegments(self::segments($line), $max)[0];
    }

    /**
     * {@see take()} over an already-tokenized row.
     *
     * Returns the cut bytes, their display width (so callers never re-walk
     * the output to measure it), and the segments that were EMITTED — that
     * list re-tokenizes as itself, so carrying it forward (a take of a
     * drop, a pen-fold over a dim wrap) is exactly a second walk of the
     * bytes, at zero tokenizer cost.
     *
     * @param list<array{kind:string, bytes:string, width:int, id:string}> $segments
     * @return array{0:string, 1:int, 2:list<array{kind:string, bytes:string, width:int, id:string}>}
     */
    public static function takeSegments(array $segments, int $max): array
    {
        if ($max <= 0) {
            return ['', 0, []];
        }

        $out = '';
        $width = 0;
        $pastBudget = false;
        /** @var string|null id of the zone open at (or after) the cut */
        $zone = null;
        $linkActive = false;
        /** @var list<array{kind:string, bytes:string, width:int, id:string}> $emitted */
        $emitted = [];

        foreach ($segments as $seg) {
            switch ($seg['kind']) {
                case 'text':
                    if ($pastBudget) {
                        break;
                    }
                    if ($width + $seg['width'] > $max) {
                        // Wide glyph straddling the budget is never split;
                        // everything past it is cut (Width::truncateAnsi parity).
                        $pastBudget = true;
                        break;
                    }
                    $out .= $seg['bytes'];
                    $width += $seg['width'];
                    $emitted[] = $seg;
                    break;

                case 'zone-open':
                    if (!$pastBudget && $width < $max) {
                        $out .= $seg['bytes'];
                        $zone = $seg['id'];
                        $emitted[] = $seg;
                    }
                    // Past the budget (or with the budget exactly spent — no
                    // content of this zone can survive) a whole new zone is
                    // opening whose content was just dropped; its envelope
                    // is meaningless here.
                    break;

                case 'zone-close':
                    if (!$pastBudget && $width < $max) {
                        $out .= $seg['bytes'];
                        if ($zone === $seg['id']) {
                            $zone = null;
                        }
                        $emitted[] = $seg;
                    } elseif ($zone !== null && $zone === $seg['id']) {
                        // The zone opened before the cut and its real close
                        // arrived after it — keeping the original bytes is
                        // byte-faithful balance; the synth at the end skips it.
                        $out .= $seg['bytes'];
                        $zone = null;
                        $emitted[] = $seg;
                    }
                    break;

                case 'osc8':
                    if (!$pastBudget && $width < $max) {
                        $out .= $seg['bytes'];
                        $linkActive = $seg['id'] !== '';
                        $emitted[] = $seg;
                    } elseif ($seg['id'] === '' && $linkActive) {
                        $out .= $seg['bytes'];
                        $linkActive = false;
                        $emitted[] = $seg;
                    }
                    break;

                default: // 'sgr', 'esc'
                    $out .= $seg['bytes'];
                    $emitted[] = $seg;
            }
        }

        // An unmatched forged zone-open tokenizes to id ''; that claim is
        // sentinel garbage, not a zone — synthesizing its closing half
        // would complete an empty-id envelope onto the wire (sugar-overlay-035).
        if ($zone !== null && $zone !== '') {
            $out .= self::envelope('/' . $zone);
        }
        if ($linkActive) {
            $out .= self::LINK_CLOSE;
        }

        return [$out, $width, $emitted];
    }

    /**
     * $line without its first $skip visible cells, marker- and link-balanced.
     *
     * Complement of {@see take()}. Escapes from the dropped head are replayed
     * at the start of the tail (Width::dropAnsi parity) so the remainder
     * keeps the pen and any link the head opened. A zone whose open envelope
     * sat in the dropped head has that envelope replayed too — otherwise the
     * surviving cells silently lose their hit-zone — while a close envelope
     * whose zone was ENTIRELY dropped with the head is removed, so the tail
     * cannot carry a stray close.
     */
    public static function drop(string $line, int $skip): string
    {
        if ($skip <= 0) {
            return $line;
        }

        return self::dropSegments(self::segments($line), $skip)[0];
    }

    /**
     * {@see drop()} over an already-tokenized row, returning the tail bytes,
     * their display width, and the segments they re-tokenize as (head-phase
     * escapes first, then the zone replay if one survives, then the tail) —
     * so a take-of-a-drop chain never re-walks the intermediate string.
     *
     * @param list<array{kind:string, bytes:string, width:int, id:string}> $segments
     * @return array{0:string, 1:int, 2:list<array{kind:string, bytes:string, width:int, id:string}>}
     */
    public static function dropSegments(array $segments, int $skip): array
    {
        if ($skip <= 0) {
            // Nothing to drop: the walk is total, so the segments re-emit
            // the row byte-for-byte.
            return [
                \implode('', \array_column($segments, 'bytes')),
                self::measureSegments($segments),
                $segments,
            ];
        }

        $count = \count($segments);
        $head = '';
        $tail = '';
        $replay = '';
        $width = 0;
        $tailWidth = 0;
        $index = 0;
        $straddle = false;
        /** @var string|null id of the zone currently open in the walk */
        $zone = null;
        /** @var list<array{kind:string, bytes:string, width:int, id:string}> $headSegs */
        $headSegs = [];
        /** @var list<array{kind:string, bytes:string, width:int, id:string}> $tailSegs */
        $tailSegs = [];

        // Head phase: walk until $skip visible cells have been dropped.
        while ($index < $count) {
            $seg = $segments[$index];
            if ($seg['kind'] === 'text') {
                if ($width + $seg['width'] > $skip) {
                    $straddle = true; // this cluster is eaten by the cut
                    break;
                }
                $width += $seg['width'];
                $index++;
                if ($width === $skip) {
                    break; // boundary falls exactly here
                }
                continue;
            }
            if ($seg['kind'] === 'zone-open') {
                $zone = $seg['id'];
            } elseif ($seg['kind'] === 'zone-close') {
                if ($zone === $seg['id']) {
                    $zone = null;
                }
                // Head envelopes are not replayed wholesale; the cut-time
                // replay below carries only a zone that survives into the tail.
            } else {
                $head .= $seg['bytes'];
                $headSegs[] = $seg;
            }
            $index++;
        }

        if ($index >= $count && !$straddle) {
            // The drop consumed (or overshot) every visible cell.
            return ['', 0, []];
        }

        // Belt for segment lists that never came from segments(): an
        // empty-id open is a forged claim, never replayed as an envelope
        // (sugar-overlay-035, the drop() head-replay leg).
        if ($zone !== null && $zone !== '') {
            $replay = self::envelope($zone);
        }
        // Captured NOW: the tail phase reassigns $zone as envelopes pair off.
        $replayZone = $zone;
        if ($straddle) {
            $index++; // the straddling cluster is consumed whole, never emitted
        }

        // Tail phase: everything from the cut to the end of the row.
        $seenText = false;
        for (; $index < $count; $index++) {
            $seg = $segments[$index];
            if ($seg['kind'] === 'zone-open') {
                $tail .= $seg['bytes'];
                $tailSegs[] = $seg;
                $zone = $seg['id'];
                continue;
            }
            if ($seg['kind'] === 'zone-close') {
                if ($zone !== $seg['id']) {
                    // Its open rode in the fully-dropped head — a stray close
                    // would desync the scanner.
                    continue;
                }
                if (!$seenText && $replay !== '') {
                    // Degenerate: the replayed zone holds no visible cells in
                    // this tail — carry neither envelope.
                    $replay = '';
                    $zone = null;
                    continue;
                }
                $tail .= $seg['bytes'];
                $tailSegs[] = $seg;
                $zone = null;
                continue;
            }
            if ($seg['width'] > 0) {
                $seenText = true;
            }
            $tail .= $seg['bytes'];
            $tailWidth += $seg['width'];
            $tailSegs[] = $seg;
        }

        // The degenerate case can clear $replay AFTER the head phase set it;
        // build the carried segment list from the surviving bytes' sources.
        $segs = $headSegs;
        if ($replay !== '') {
            $segs[] = ['kind' => 'zone-open', 'bytes' => $replay, 'width' => 0, 'id' => (string) $zone];
        }
        foreach ($tailSegs as $seg) {
            $segs[] = $seg;
        }

        return [$head . $replay . $tail, $tailWidth, $segs];
    }

    /**
     * The pen-neutralizer a spliced segment needs at its right edge.
     *
     * A row assembled from three independently truncated segments can leak
     * the LEFT segment's color pen into the middle or right one when the
     * left segment ends without an explicit reset (crop, provider-styled
     * line, marker envelope wrapping a styled tail). Returns the default
     * fg/bg restoration only when the walk actually ends on a non-default
     * pen — the plain path stays byte-identical.
     *
     * The walk IS the pen: it folds through {@see SgrWalker} exactly like
     * the compositor's own pen merge, so the neutralizer can never arm a
     * reset for a color the diff model never holds (sugar-overlay-071: a
     * line ending `\e[38m` — an incomplete extended-color leg is a no-op
     * for BOTH sides now, no phantom 39/49). Colour only: attributes
     * (bold/underline/reverse…) deliberately RIDE ACROSS segment
     * boundaries — widening the neutralizer to `0` would clobber link pens
     * and chrome alike (sugar-overlay-063, pinned byte-exact in
     * SgrWalkerTest and LinkDegradationTest).
     */
    public static function isolation(string $line): string
    {
        return self::isolationSegments(self::segments($line));
    }

    /**
     * {@see isolation()} over an already-tokenized row.
     *
     * @param list<array{kind:string, bytes:string, width:int, id:string}> $segments
     */
    public static function isolationSegments(array $segments): string
    {
        $pen = ['fg' => null, 'bg' => null, 'attrs' => 0];

        foreach ($segments as $seg) {
            if ($seg['kind'] === 'sgr') {
                $pen = SgrWalker::fold($seg['id'], $pen);
            }
        }

        return $pen['fg'] === null && $pen['bg'] === null
            ? ''
            : "\e[39m\e[49m";
    }

    /**
     * One marker envelope (open or close) for $id, sentinel-wrapped.
     */
    private static function envelope(string $id): string
    {
        return Sanitize::ZONE_SENTINEL_OPEN . $id . Sanitize::ZONE_SENTINEL_CLOSE;
    }

    /**
     * Tokenize one row into zero- and one/two-cell segments — THE one walk.
     *
     * Every geometry/clipping operation in this class is a fold over this
     * list; the compositor tokenizes each row once per composite and feeds
     * the lists to the `…Segments` variants (sugar-overlay-003: the old hot
     * path re-tokenized every row 7-10 times per frame).
     *
     * Walk parity with Veil::paintRow(): CSI runs to its final byte, OSC
     * terminates at BEL or ST — and an ESC not followed by the ST backslash
     * terminates the OSC AT the ESC (that escape is then re-parsed as its own
     * introducer, never painted as literal text), zone envelopes swallow the
     * id between their sentinels (an unmatched open swallows the tail), and
     * every remaining byte group walks as one grapheme cluster.
     *
     * @return list<array{kind:string, bytes:string, width:int, id:string}>
     *         kind ∈ text | sgr | osc8 | esc | zone-open | zone-close.
     *         id carries SGR params, the OSC 8 URI, or the zone id.
     */
    public static function segments(string $line): array
    {
        /** @var list<array{kind:string, bytes:string, width:int, id:string}> $out */
        $out = [];
        $len = \strlen($line);
        $i = 0;

        while ($i < $len) {
            $byte = $line[$i];

            if ($byte === "\e" && $i + 1 < $len) {
                $intro = $line[$i + 1];

                if ($intro === '[') {
                    $p = $i + 2;
                    while ($p < $len && \ord($line[$p]) < 0x40) {
                        $p++;
                    }
                    $final = $p < $len ? $line[$p] : '';
                    $bytes = \substr($line, $i, $p - $i + ($final === '' ? 0 : 1));
                    $out[] = $final === 'm'
                        ? ['kind' => 'sgr', 'bytes' => $bytes, 'width' => 0, 'id' => \substr($line, $i + 2, $p - $i - 2)]
                        : ['kind' => 'esc', 'bytes' => $bytes, 'width' => 0, 'id' => ''];
                    $i = $p < $len ? $p + 1 : $len;
                    continue;
                }

                if ($intro === ']') {
                    $tail = $i + 2 + \strcspn($line, "\x07\x1b", $i + 2);
                    $body = \substr($line, $i + 2, $tail - $i - 2);
                    if ($tail < $len && $line[$tail] === "\x1b" && ($line[$tail + 1] ?? '') === '\\') {
                        $end = $tail + 2; // ESC \ — the real ST
                    } else {
                        // BEL swallows its byte; a bare ESC (not an ST) ends the
                        // OSC at the ESC itself, which the next round re-reads.
                        $end = $tail < $len && $line[$tail] === "\x07" ? $tail + 1 : $tail;
                    }
                    $bytes = \substr($line, $i, $end - $i);
                    $kind = 'esc';
                    $id = '';
                    if (\str_starts_with($body, '8;')) {
                        $rest = \substr($body, 2);
                        $sep = \strpos($rest, ';');
                        if ($sep !== false) {
                            $kind = 'osc8';
                            $id = \substr($rest, $sep + 1);
                        }
                    }
                    $out[] = ['kind' => $kind, 'bytes' => $bytes, 'width' => 0, 'id' => $id];
                    $i = $end;
                    continue;
                }

                // Any other two-byte ESC sequence: zero columns, no pen.
                $out[] = ['kind' => 'esc', 'bytes' => \substr($line, $i, 2), 'width' => 0, 'id' => ''];
                $i += 2;
                continue;
            }

            if ($byte === "\xEE" && $i + 2 < $len && $line[$i + 1] === "\x80") {
                if ($line[$i + 2] === "\x80") { // U+E000 OPEN — id rides to CLOSE
                    $close = \strpos($line, Sanitize::ZONE_SENTINEL_CLOSE, $i + 3);
                    if ($close === false) {
                        // Unmatched open swallows the tail (paintRow parity).
                        $out[] = ['kind' => 'zone-open', 'bytes' => \substr($line, $i), 'width' => 0, 'id' => ''];
                        $i = $len;
                        continue;
                    }
                    $id = \substr($line, $i + 3, $close - $i - 3);
                    $bytes = \substr($line, $i, $close + 3 - $i);
                    $out[] = \str_starts_with($id, '/')
                        ? ['kind' => 'zone-close', 'bytes' => $bytes, 'width' => 0, 'id' => \substr($id, 1)]
                        : ['kind' => 'zone-open', 'bytes' => $bytes, 'width' => 0, 'id' => $id];
                    $i = $close + 3;
                    continue;
                }
                if ($line[$i + 2] === "\x81") { // U+E001 CLOSE without an OPEN
                    $out[] = ['kind' => 'zone-close', 'bytes' => \substr($line, $i, 3), 'width' => 0, 'id' => ''];
                    $i += 3;
                    continue;
                }
            }

            $cluster = Cluster::next($line, $i);
            $out[] = ['kind' => 'text', 'bytes' => $cluster, 'width' => Width::of($cluster), 'id' => ''];
            $i += \strlen($cluster);
        }

        return $out;
    }
}
