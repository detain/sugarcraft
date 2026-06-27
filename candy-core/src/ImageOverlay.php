<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Out-of-band image compositor for pixel-graphics protocols (sixel / kitty /
 * iTerm2) inside a text-cell TUI.
 *
 * The frame {@see Renderer} is a line/cell diff engine: it splits the frame on
 * "\n" and reasons about visible cell widths, so a graphics blob — one opaque
 * DCS/escape sequence with no per-row structure — cannot live inside the frame
 * string (stitching it beside other cells shreds it). Instead a widget leaves a
 * one-cell **marker** (a Private-Use-Area codepoint, width 1) at the top-left of
 * the box it wants an image in, and registers the blob under the same id.
 *
 * After the text frame is composed, {@see resolve()} walks it, converts each
 * marker into a `(row, col)` paint instruction and blanks the marker cell (so
 * the diff engine sees an ordinary space). The {@see Program} then paints the
 * blobs on top of the rendered text by moving the cursor to each position and
 * emitting the bytes — an additive layer the diff never has to understand.
 *
 * Markers occupy the PUA range U+E000…(U+E000 + {@see MAX_IMAGES} − 1); the
 * surrounding cells of the image box are ordinary spaces the widget emits
 * itself, so the box reserves the right area in the text layout.
 *
 * @internal
 */
final class ImageOverlay
{
    /** First Private-Use-Area codepoint used as an image marker. */
    private const MARKER_BASE = 0xE000;

    /**
     * Number of distinct image markers — the whole BMP Private-Use-Area block
     * U+E000…U+F8FF. A caller can therefore use a stable per-item index as the
     * image id without an allocator; ids past this range simply get no marker.
     */
    public const MAX_IMAGES = 6400;

    /**
     * The marker cell for image $id — a single width-1 codepoint a widget drops
     * at the top-left of the box it wants the image painted in.
     */
    public static function marker(int $id): string
    {
        if ($id < 0 || $id >= self::MAX_IMAGES) {
            throw new \InvalidArgumentException("image id {$id} out of range 0.." . (self::MAX_IMAGES - 1));
        }

        return self::encode(self::MARKER_BASE + $id);
    }

    /**
     * Build a $width × $height cell block reserving an image box: a one-cell
     * {@see marker()} at the top-left and blank spaces everywhere else. Place
     * this in the text frame where the image should appear; the surrounding cells
     * keep the layout honest and {@see resolve()} turns the marker into a paint.
     */
    public static function markerBlock(int $id, int $width, int $height): string
    {
        $width = max(1, $width);
        $height = max(1, $height);

        $rows = [self::marker($id) . str_repeat(' ', $width - 1)];
        for ($i = 1; $i < $height; $i++) {
            $rows[] = str_repeat(' ', $width);
        }

        return implode("\n", $rows);
    }

    /**
     * Resolve markers in a composed frame against their blobs.
     *
     * Returns the cleaned body (markers replaced by spaces, safe to hand to the
     * diff renderer) and the paint list — one `(row, col, bytes)` per marker
     * that had a registered blob. Rows and cols are 1-based for {@see Ansi::cursorTo()}.
     * Markers with no blob are still blanked (so a stale marker never shows as
     * tofu) but produce no paint.
     *
     * @param array<int, string> $blobs  image id → raw protocol bytes
     * @return array{0: string, 1: list<array{row: int, col: int, bytes: string}>}
     */
    public static function resolve(string $frame, array $blobs): array
    {
        // Fast path: no images registered and no stray marker to blank.
        if ($blobs === [] && !self::hasAnyMarker($frame)) {
            return [$frame, []];
        }

        $lines = explode("\n", $frame);
        $paints = [];
        foreach ($lines as $row => $line) {
            $lines[$row] = self::resolveLine($line, $row + 1, $blobs, $paints);
        }

        return [implode("\n", $lines), $paints];
    }

    /**
     * Walk one line, counting visible columns past ANSI escapes, turning any
     * marker into a paint (when a blob exists) and blanking its cell.
     *
     * @param array<int, string>                                  $blobs
     * @param list<array{row: int, col: int, bytes: string}>      $paints  appended to
     */
    private static function resolveLine(string $line, int $row, array $blobs, array &$paints): string
    {
        $len = strlen($line);
        $col = 0;
        $out = '';
        $i = 0;

        while ($i < $len) {
            if ($line[$i] === "\x1b") {
                $adv = self::escapeLength($line, $i);
                $out .= substr($line, $i, $adv);
                $i += $adv;
                continue;
            }

            $bytes = self::codepointLength($line[$i]);
            $chunk = substr($line, $i, $bytes);
            $cp = self::decode($chunk);
            $i += $bytes;

            $id = $cp - self::MARKER_BASE;
            if ($id >= 0 && $id < self::MAX_IMAGES) {
                if (isset($blobs[$id])) {
                    $paints[] = ['row' => $row, 'col' => $col + 1, 'bytes' => $blobs[$id]];
                }
                $out .= ' '; // occupy the cell with a real space
                $col += 1;
                continue;
            }

            $out .= $chunk;
            $col += Width::string($chunk);
        }

        return $out;
    }

    /**
     * Emit the cursor-positioned bytes for a paint list. The cursor is parked
     * out of the way afterwards so a subsequent text diff doesn't append at an
     * image's origin.
     *
     * @param list<array{row: int, col: int, bytes: string}> $paints
     */
    public static function paint(array $paints): string
    {
        if ($paints === []) {
            return '';
        }

        $out = Ansi::cursorSave();
        foreach ($paints as $p) {
            $out .= Ansi::cursorTo($p['row'], $p['col']) . $p['bytes'];
        }

        return $out . Ansi::cursorRestore();
    }

    /**
     * A stable signature of a paint list — same positions + same blobs → same
     * string. Lets a caller skip re-emitting identical images frame to frame.
     *
     * @param list<array{row: int, col: int, bytes: string}> $paints
     */
    public static function signature(array $paints): string
    {
        $parts = [];
        foreach ($paints as $p) {
            $parts[] = $p['row'] . ':' . $p['col'] . ':' . crc32($p['bytes']);
        }

        return implode('|', $parts);
    }

    private static function hasAnyMarker(string $frame): bool
    {
        // Any codepoint in the PUA marker window encodes to a 3-byte sequence
        // starting 0xEE 0x80.. ; a cheap prefilter before the full walk.
        return (bool) preg_match('/[\x{E000}-\x{F8FF}]/u', $frame);
    }

    /** Byte length of the escape sequence starting at $i (CSI / OSC / DCS / simple). */
    private static function escapeLength(string $s, int $i): int
    {
        $len = strlen($s);
        if ($i + 1 >= $len) {
            return 1;
        }

        $next = $s[$i + 1];

        // CSI: ESC [ params/intermediates, final byte 0x40-0x7E.
        if ($next === '[') {
            $j = $i + 2;
            while ($j < $len) {
                $o = ord($s[$j]);
                $j++;
                if ($o >= 0x40 && $o <= 0x7E) {
                    break;
                }
            }
            return $j - $i;
        }

        // String sequences (OSC ], DCS P, SOS X, PM ^, APC _) end at BEL or ST (ESC \).
        if (in_array($next, [']', 'P', 'X', '^', '_'], true)) {
            $j = $i + 2;
            while ($j < $len) {
                if ($s[$j] === "\x07") {
                    return $j - $i + 1;
                }
                if ($s[$j] === "\x1b" && $j + 1 < $len && $s[$j + 1] === '\\') {
                    return $j - $i + 2;
                }
                $j++;
            }
            return $j - $i;
        }

        // Simple two-byte escape (ESC + one byte).
        return 2;
    }

    /** UTF-8 byte length implied by a lead byte. */
    private static function codepointLength(string $lead): int
    {
        $b = ord($lead);
        return match (true) {
            ($b & 0x80) === 0x00 => 1,
            ($b & 0xE0) === 0xC0 => 2,
            ($b & 0xF0) === 0xE0 => 3,
            ($b & 0xF8) === 0xF0 => 4,
            default              => 1,
        };
    }

    private static function decode(string $chunk): int
    {
        $cp = mb_ord($chunk, 'UTF-8');
        return $cp === false ? 0 : $cp;
    }

    private static function encode(int $cp): string
    {
        $ch = mb_chr($cp, 'UTF-8');
        return $ch === false ? '' : $ch;
    }
}
