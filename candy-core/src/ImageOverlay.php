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
 * A marker is two parts that only ever travel together: an authenticating
 * escape sequence carrying the id ({@see MARKER_OSC}, zero cells wide) followed
 * by one Private-Use-Area cell, U+E002 + id (one cell wide). The escape is the
 * part untrusted text cannot carry: every sink that paints model, tool or
 * pasted text into a frame runs {@see Util\Sanitize::untrusted()} (or a
 * policy built on it), which deletes ESC — and a frame that let a raw ESC
 * through would already hand the terminal to the attacker, a strictly worse
 * bug. A bare Private-Use codepoint, by contrast, is ordinary printable text
 * that the sanitizers pass on purpose (Powerline U+E0A0…, Nerd Font
 * devicons/Pomicons U+E000…, icon fonts). When a bare codepoint was the whole
 * marker, any assistant or tool row containing U+E002 + n painted a second
 * copy of on-screen image n wherever the text put it, and {@see resolve()}
 * blanked every glyph in the window — every Powerline separator in `eza
 * --icons` or `git log` output turned into a space (audit 15b-17). Now
 * {@see resolve()} acts only on the escape-plus-cell pair and leaves every bare
 * Private-Use codepoint exactly as it found it.
 *
 * The cell keeps U+E002 + id rather than a plain space so the pair is bound to
 * one id: a layout pass that re-emits the escapes of a region it cut away
 * (Width::dropAnsi() does, to carry SGR state across the cut) leaves the
 * escape in front of some unrelated cell, and that orphan must not paint. The
 * window starts at U+E002 because U+E000/U+E001 are the candy-mouse click/frame
 * zone sentinels (Sanitize::ZONE_SENTINEL_*). The surrounding cells of the
 * image box are ordinary spaces the widget emits itself, so the box reserves
 * the right area in the text layout.
 *
 * @internal
 */
final class ImageOverlay
{
    /**
     * First Private-Use-Area codepoint used as an image marker. U+E000/U+E001
     * are deliberately skipped: they are the candy-mouse zone sentinels and
     * Sanitize owns the reservation (a32c4faae ruling, follow-up 2 of 2).
     */
    private const MARKER_BASE = 0xE002;

    /**
     * Introducer of the authenticating escape: `ESC ] candy-image ; <id> ESC \`.
     *
     * An OSC because every ANSI-aware width and cut helper in the stack
     * ({@see Width::string()}, {@see Width::truncateAnsi()},
     * {@see Width::wrapAnsi()}, candy-mouse's zone scanner) already treats an
     * OSC as zero cells and never splits one, so a marker row lays out exactly
     * as the one-codepoint marker did. The non-numeric command keeps it inert
     * if a frame ever reaches a terminal unresolved (terminals ignore an OSC
     * whose command is not a number), though {@see resolve()} strips it from
     * every frame {@see Program} paints.
     */
    private const MARKER_OSC = "\x1b]candy-image;";

    /** String Terminator closing {@see MARKER_OSC}. */
    private const MARKER_ST = "\x1b\\";

    /**
     * Number of distinct image markers — the BMP Private-Use-Area block
     * U+E000…U+F8FF minus the two sentinel codepoints at its head, so the
     * arena tops out exactly at U+F8FF (U+F900+ is the CJK Compatibility
     * Ideographs block and must never be spilled into). A caller can
     * therefore use a stable per-item index as the image id without an
     * allocator; ids past this range simply get no marker.
     */
    public const MAX_IMAGES = 6398;

    /**
     * The marker for image $id — one cell wide — that a widget drops at the
     * top-left of the box it wants the image painted in: the zero-width
     * authenticating escape followed by the U+E002 + id cell. Treat it as an
     * opaque unit; {@see resolve()} paints only the two parts adjacent.
     */
    public static function marker(int $id): string
    {
        if ($id < 0 || $id >= self::MAX_IMAGES) {
            throw new \InvalidArgumentException("image id {$id} out of range 0.." . (self::MAX_IMAGES - 1));
        }

        return self::MARKER_OSC . $id . self::MARKER_ST . self::encode(self::MARKER_BASE + $id);
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
     * Resolve markers in a composed frame against their placements.
     *
     * Returns the cleaned body (markers replaced by spaces, safe to hand to the
     * diff renderer) and the paint list — one entry per marker that had a
     * registered image, carrying the screen position (1-based, for
     * {@see Ansi::cursorTo()}) plus the image bytes and its cell footprint (so
     * the runtime can clear the cells it covers). Markers with no placement are
     * still blanked (so a stale marker never shows as tofu) but produce no paint.
     *
     * Only a whole {@see marker()} counts. A bare Private-Use codepoint — a
     * forged U+E002 + n in model text, a Powerline or Nerd Font glyph — is
     * copied through untouched, and an authenticating escape with no matching
     * cell right after it (its cell was cut away by a layout pass) is dropped
     * without a paint. So the body is safe to resolve whether or not any image
     * is registered: with none, the only change is that stray markers vanish.
     *
     * @param array<int, ImagePlacement> $images  image id → placement
     * @return array{0: string, 1: list<array{row: int, col: int, bytes: string, w: int, h: int}>}
     */
    public static function resolve(string $frame, array $images): array
    {
        // Fast path: no images registered and no stray marker to blank.
        if ($images === [] && !self::hasAnyMarker($frame)) {
            return [$frame, []];
        }

        $lines = explode("\n", $frame);
        $paints = [];
        foreach ($lines as $row => $line) {
            $lines[$row] = self::resolveLine($line, $row + 1, $images, $paints);
        }

        return [implode("\n", $lines), $paints];
    }

    /**
     * Walk one line, counting visible columns past ANSI escapes, turning any
     * marker into a paint (when an image exists) and blanking its cell.
     *
     * @param array<int, ImagePlacement>                                          $images
     * @param list<array{row: int, col: int, bytes: string, w: int, h: int}>      $paints  appended to
     */
    private static function resolveLine(string $line, int $row, array $images, array &$paints): string
    {
        $len = strlen($line);
        $col = 0;
        $out = '';
        $i = 0;

        while ($i < $len) {
            if ($line[$i] === "\x1b") {
                $adv = self::escapeLength($line, $i);
                $escape = substr($line, $i, $adv);
                $i += $adv;

                $id = self::markerEscapeId($escape);
                if ($id === null) {
                    $out .= $escape;
                    continue;
                }

                // An authenticating escape is never output: either it and its
                // cell become one space (+ a paint), or it is an orphan whose
                // cell a layout pass cut off, and it is dropped on its own.
                $cell = self::encode(self::MARKER_BASE + $id);
                if (substr($line, $i, strlen($cell)) !== $cell) {
                    continue;
                }
                $i += strlen($cell);

                if (isset($images[$id])) {
                    $placement = $images[$id];
                    $paints[] = [
                        'row' => $row,
                        'col' => $col + 1,
                        'bytes' => $placement->bytes,
                        'w' => $placement->widthCells,
                        'h' => $placement->heightCells,
                    ];
                }
                $out .= ' '; // occupy the cell with a real space
                $col += 1;
                continue;
            }

            $bytes = self::codepointLength($line[$i]);
            $chunk = substr($line, $i, $bytes);
            $i += $bytes;

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
     * @param list<array{row: int, col: int, bytes: string, w: int, h: int}> $paints
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
     * A stable signature of a paint list — same positions + footprints + blobs →
     * same string. Lets a caller skip re-emitting identical images frame to frame.
     *
     * @param list<array{row: int, col: int, bytes: string, w: int, h: int}> $paints
     */
    public static function signature(array $paints): string
    {
        $parts = [];
        foreach ($paints as $p) {
            $parts[] = $p['row'] . ':' . $p['col'] . ':' . $p['w'] . ':' . $p['h'] . ':' . crc32($p['bytes']);
        }

        return implode('|', $parts);
    }

    /**
     * The set of 1-based screen rows covered by a paint list (each image spans
     * its top row down through its cell height). Used to clear an image's cells
     * when it moves or disappears.
     *
     * @param list<array{row: int, col: int, bytes: string, w: int, h: int}> $paints
     * @return array<int, true>  row index → true
     */
    public static function coveredRows(array $paints): array
    {
        $rows = [];
        foreach ($paints as $p) {
            $top = $p['row'];
            $bottom = $top + max(1, $p['h']);
            for ($r = $top; $r < $bottom; $r++) {
                $rows[$r] = true;
            }
        }

        return $rows;
    }

    private static function hasAnyMarker(string $frame): bool
    {
        // Only the authenticating escape makes a marker, so it alone decides
        // whether the walk can change anything; bare Private-Use text cannot.
        return str_contains($frame, self::MARKER_OSC);
    }

    /**
     * The image id an escape sequence authenticates, or null when $escape is
     * any other sequence (SGR, a hyperlink, …) or a malformed marker escape —
     * an id out of range, non-digits, or a BEL/unterminated ending that
     * {@see marker()} never emits.
     */
    private static function markerEscapeId(string $escape): ?int
    {
        if (!str_starts_with($escape, self::MARKER_OSC) || !str_ends_with($escape, self::MARKER_ST)) {
            return null;
        }

        $digits = substr($escape, strlen(self::MARKER_OSC), -strlen(self::MARKER_ST));
        if ($digits === '' || strlen($digits) > 4 || !ctype_digit($digits)) {
            return null;
        }

        $id = (int) $digits;

        return $id < self::MAX_IMAGES ? $id : null;
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

    private static function encode(int $cp): string
    {
        $ch = mb_chr($cp, 'UTF-8');
        return $ch === false ? '' : $ch;
    }
}
