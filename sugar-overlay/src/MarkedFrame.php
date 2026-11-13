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
     */
    public static function measure(string $line): int
    {
        $width = 0;
        foreach (self::segments($line) as $seg) {
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

        $out = '';
        $width = 0;
        $pastBudget = false;
        /** @var string|null id of the zone open at (or after) the cut */
        $zone = null;
        $linkActive = false;

        foreach (self::segments($line) as $seg) {
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
                    break;

                case 'zone-open':
                    if (!$pastBudget && $width < $max) {
                        $out .= $seg['bytes'];
                        $zone = $seg['id'];
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
                    } elseif ($zone !== null && $zone === $seg['id']) {
                        // The zone opened before the cut and its real close
                        // arrived after it — keeping the original bytes is
                        // byte-faithful balance; the synth at the end skips it.
                        $out .= $seg['bytes'];
                        $zone = null;
                    }
                    break;

                case 'osc8':
                    if (!$pastBudget && $width < $max) {
                        $out .= $seg['bytes'];
                        $linkActive = $seg['id'] !== '';
                    } elseif ($seg['id'] === '' && $linkActive) {
                        $out .= $seg['bytes'];
                        $linkActive = false;
                    }
                    break;

                default: // 'sgr', 'esc'
                    $out .= $seg['bytes'];
            }
        }

        if ($zone !== null) {
            $out .= self::envelope('/' . $zone);
        }
        if ($linkActive) {
            $out .= self::LINK_CLOSE;
        }

        return $out;
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

        $segments = self::segments($line);
        $count = \count($segments);
        $head = '';
        $tail = '';
        $replay = '';
        $width = 0;
        $index = 0;
        $straddle = false;
        /** @var string|null id of the zone currently open in the walk */
        $zone = null;

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
            }
            $index++;
        }

        if ($index >= $count && !$straddle) {
            // The drop consumed (or overshot) every visible cell.
            return '';
        }

        if ($zone !== null) {
            $replay = self::envelope($zone);
        }
        if ($straddle) {
            $index++; // the straddling cluster is consumed whole, never emitted
        }

        // Tail phase: everything from the cut to the end of the row.
        $seenText = false;
        for (; $index < $count; $index++) {
            $seg = $segments[$index];
            if ($seg['kind'] === 'zone-open') {
                $tail .= $seg['bytes'];
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
                $zone = null;
                continue;
            }
            if ($seg['width'] > 0) {
                $seenText = true;
            }
            $tail .= $seg['bytes'];
        }

        return $head . $replay . $tail;
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
     */
    public static function isolation(string $line): string
    {
        $fg = false;
        $bg = false;

        foreach (self::segments($line) as $seg) {
            if ($seg['kind'] !== 'sgr') {
                continue;
            }
            $codes = \array_map('intval', \explode(';', $seg['id']));
            $count = \count($codes);
            for ($i = 0; $i < $count; $i++) {
                $p = $codes[$i];
                if ($p === 0) {
                    $fg = false;
                    $bg = false;
                    continue;
                }
                if ($p === 39) {
                    $fg = false;
                    continue;
                }
                if ($p === 49) {
                    $bg = false;
                    continue;
                }
                if (($p >= 30 && $p <= 37) || ($p >= 90 && $p <= 97)) {
                    $fg = true;
                    continue;
                }
                if (($p >= 40 && $p <= 47) || ($p >= 100 && $p <= 107)) {
                    $bg = true;
                    continue;
                }
                if ($p === 38 || $p === 48) {
                    if ($p === 38) {
                        $fg = true;
                    } else {
                        $bg = true;
                    }
                    // 38;5;n / 38;2;r;g;b — consume the extended color leg.
                    $i += (($codes[$i + 1] ?? null) === 2) ? 4 : 2;
                }
            }
        }

        if ($fg === false && $bg === false) {
            return '';
        }

        return "\e[39m\e[49m";
    }

    /**
     * One marker envelope (open or close) for $id, sentinel-wrapped.
     */
    private static function envelope(string $id): string
    {
        return Sanitize::ZONE_SENTINEL_OPEN . $id . Sanitize::ZONE_SENTINEL_CLOSE;
    }

    /**
     * Tokenize one row into zero- and one/two-cell segments.
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
    private static function segments(string $line): array
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
