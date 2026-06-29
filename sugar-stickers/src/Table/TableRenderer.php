<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Table;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;

/**
 * Stateful diff renderer for Table.
 *
 * Holds the mutable diff state (previousFrame, prevWidth, prevHeight) and
 * produces either a full frame or a delta-encoded frame depending on
 * whether dimensions have changed since the last render.
 *
 * Construct one instance per logical table and call render() each frame
 * to get bandwidth-efficient delta output for stable dimensions.
 *
 * @see Table::render() for pure snapshot rendering (always full frame)
 */
final class TableRenderer
{
    /** @var Buffer|null Previous rendered frame for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var int|null Previous output width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous output height for resize detection */
    private ?int $prevHeight = null;

    /**
     * Render the table, emitting a full frame or a delta depending on
     * whether dimensions have changed since the last frame.
     *
     * @return string Full frame or delta-encoded ops
     */
    public function render(Table $table): string
    {
        $lines = $table->buildLines();

        $fullOutput = \implode("\n", $lines);

        // Compute output dimensions from the rendered content.
        $height = \count($lines);
        $width = 0;
        foreach ($lines as $line) {
            $w = \SugarCraft\Core\Util\Width::string($line);
            if ($w > $width) {
                $width = $w;
            }
        }

        // Detect dimension change: reset diff state so we emit a full frame.
        if ($this->prevWidth !== null && ($this->prevWidth !== $width || $this->prevHeight !== $height)) {
            $this->previousFrame = null;
        }
        $this->prevWidth = $width;
        $this->prevHeight = $height;

        // First frame or resize: emit full output and store as previousFrame.
        if ($this->previousFrame === null) {
            $this->previousFrame = $this->bufferFromOutput($fullOutput, $width, $height);
            return $fullOutput;
        }

        // Subsequent frames with same dimensions: compute diff and emit delta.
        $currentFrame = $this->bufferFromOutput($fullOutput, $width, $height);
        $ops = $currentFrame->diff($this->previousFrame);
        $this->previousFrame = $currentFrame;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
    }

    /**
     * Reset the diff state, forcing the next render to emit a full frame.
     */
    public function reset(): void
    {
        $this->previousFrame = null;
        $this->prevWidth = null;
        $this->prevHeight = null;
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * Uses bulk fromGrid construction for O(w·h) instead of O((w·h)²).
     * Iterates by display column using grapheme clusters so multibyte
     * separators and arrows (│, ▲, ▼, ─, ┬) land at correct grid columns.
     *
     * NOTE: Style tracking via SGR parsing is not yet implemented (style
     * fidelity limitation documented in the remediation plan). Cells are
     * created with null style.
     *
     * @param string $output Multi-line string from render()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $lines = \explode("\n", $output);
        $grid = [];

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            $col = 0;

            // Strip ANSI to get visual content, then split by grapheme cluster.
            $stripped = \SugarCraft\Core\Util\Ansi::strip($line);
            $graphemes = self::graphemes($stripped);

            // Track active SGR style by scanning the original line byte-by-byte.
            // Then map byte offsets to grapheme positions.
            $styleAtByte = $this->scanStylesByByteOffset($line);

            $byteOffset = 0;
            $strippedByteOffset = 0;

            foreach ($graphemes as $grapheme) {
                if ($col >= $width) {
                    break;
                }

                // Skip zero-width combining characters.
                $gw = self::graphemeWidthInternal($grapheme);
                if ($gw === 0) {
                    // Advance byte offset for stripped content.
                    $strippedByteOffset += \strlen($grapheme);
                    continue;
                }

                if ($gw === 2 && $col + 1 < $width) {
                    // Wide character: occupies two columns.
                    $grid[] = Cell::new($grapheme, null, null, 2);
                    $col += 2;
                    // Continuation cell for the second column.
                    $grid[] = Cell::continuation();
                } elseif ($gw === 1) {
                    $grid[] = Cell::new($grapheme, null, null, 1);
                    $col++;
                }

                // Advance byte offset for stripped content.
                $strippedByteOffset += \strlen($grapheme);
            }

            // Pad to full width with blank cells.
            while ($col < $width) {
                $grid[] = Cell::new(' ', null, null, 1);
                $col++;
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Scan an ANSI string and build a map of byte-offset → active SGR style.
     *
     * @return array<int, string|null> Map of byte offset to active style string (e.g. "1;32")
     */
    private function scanStylesByByteOffset(string $line): array
    {
        $styles = [];
        $currentStyle = null;
        $len = \strlen($line);
        $i = 0;

        while ($i < $len) {
            // Check for CSI escape sequence.
            if ($line[$i] === "\x1b" && ($line[$i + 1] ?? '') === '[') {
                // Found CSI. Scan to find the final byte.
                $j = $i + 2;
                while ($j < $len && !\ctype_alpha($line[$j])) {
                    $j++;
                }
                $seq = \substr($line, $i, $j - $i + 1);

                // Check if it's SGR (Select Graphic Rendition) sequence.
                if (\preg_match('/^\x1b\[([0-9;]*)m$/', $seq, $m)) {
                    $params = $m[1];
                    if ($params === '' || $params === '0') {
                        $currentStyle = null;
                    } else {
                        $currentStyle = \rtrim($params, 'm');
                    }
                    // Mark all positions in this sequence as having no visible character.
                    for ($k = $i; $k <= $j; $k++) {
                        $styles[$k] = null; // No visible char at escape sequence bytes.
                    }
                }
                $i = $j + 1;
                continue;
            }

            $styles[$i] = $currentStyle;
            $i++;
        }

        return $styles;
    }

    /**
     * Split a string into grapheme clusters.
     *
     * @return list<string>
     */
    private static function graphemes(string $s): array
    {
        if (\function_exists('grapheme_str_split')) {
            $g = grapheme_str_split($s);
            if (\is_array($g)) {
                return $g;
            }
        }
        if (\function_exists('mb_str_split')) {
            return mb_str_split($s, 1, 'UTF-8');
        }
        return \preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Visual width of a single grapheme cluster.
     */
    private static function graphemeWidthInternal(string $g): int
    {
        if ($g === '') {
            return 0;
        }
        $cp = self::firstCodepoint($g);
        if ($cp === 0) {
            return 0;
        }
        if (self::isZeroWidth($cp)) {
            return 0;
        }
        if (self::isWide($cp)) {
            return 2;
        }
        return 1;
    }

    private static function firstCodepoint(string $g): int
    {
        if (\function_exists('mb_ord')) {
            $cp = mb_ord($g, 'UTF-8');
            return $cp === false ? 0 : $cp;
        }
        $b1 = \ord($g[0]);
        if ($b1 < 0x80) {
            return $b1;
        }
        if (($b1 & 0xe0) === 0xc0 && \strlen($g) >= 2) {
            return (($b1 & 0x1f) << 6) | (\ord($g[1]) & 0x3f);
        }
        if (($b1 & 0xf0) === 0xe0 && \strlen($g) >= 3) {
            return (($b1 & 0x0f) << 12) | ((\ord($g[1]) & 0x3f) << 6) | (\ord($g[2]) & 0x3f);
        }
        if (($b1 & 0xf8) === 0xf0 && \strlen($g) >= 4) {
            return (($b1 & 0x07) << 18) | ((\ord($g[1]) & 0x3f) << 12)
                 | ((\ord($g[2]) & 0x3f) << 6) | (\ord($g[3]) & 0x3f);
        }
        return 0;
    }

    private static function isZeroWidth(int $cp): bool
    {
        if ($cp < 0x20) {
            return true;
        }
        if ($cp >= 0x7f && $cp < 0xa0) {
            return true;
        }
        if ($cp === 0x200b || $cp === 0x200c || $cp === 0x200d || $cp === 0xfeff) {
            return true;
        }
        if ($cp >= 0x0300 && $cp <= 0x036f) {
            return true;
        }
        if ($cp >= 0x1ab0 && $cp <= 0x1aff) {
            return true;
        }
        if ($cp >= 0x1dc0 && $cp <= 0x1dff) {
            return true;
        }
        if ($cp >= 0x20d0 && $cp <= 0x20ff) {
            return true;
        }
        if ($cp >= 0xfe00 && $cp <= 0xfe0f) {
            return true;
        }
        if ($cp >= 0xfe20 && $cp <= 0xfe2f) {
            return true;
        }
        return false;
    }

    private static function isWide(int $cp): bool
    {
        if ($cp < 0x1100) {
            return false;
        }
        return ($cp <= 0x115f)
            || ($cp >= 0x2e80 && $cp <= 0x303e)
            || ($cp >= 0x3041 && $cp <= 0x33ff)
            || ($cp >= 0x3400 && $cp <= 0x4dbf)
            || ($cp >= 0x4e00 && $cp <= 0x9fff)
            || ($cp >= 0xa000 && $cp <= 0xa4cf)
            || ($cp >= 0xac00 && $cp <= 0xd7a3)
            || ($cp >= 0xf900 && $cp <= 0xfaff)
            || ($cp >= 0xfe30 && $cp <= 0xfe4f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0xffe0 && $cp <= 0xffe6)
            || ($cp >= 0x1f300 && $cp <= 0x1f64f)
            || ($cp >= 0x1f680 && $cp <= 0x1f6ff)
            || ($cp >= 0x1f900 && $cp <= 0x1f9ff)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)
            || ($cp >= 0x30000 && $cp <= 0x3fffd);
    }
}
