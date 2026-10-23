<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Table;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;

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
     * Style tracking via SGR parsing maps inline ANSI styles to cells
     * so style-only diffs are visible in the diff output.
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

            // Build a map from stripped-character offset to active SGR style.
            // We scan the original (ANSI) line, tracking both the original byte
            // offset and the stripped-character position — so each grapheme's
            // position in the stripped string maps back to the style that was
            // active at the corresponding position in the original string.
            $strippedPosToStyle = [];
            $strippedPos = 0;
            $len = \strlen($line);
            $currentStyle = null;

            for ($i = 0; $i < $len; $i++) {
                if ($line[$i] === "\x1b" && ($line[$i + 1] ?? '') === '[') {
                    // CSI escape sequence — scan to the end.
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
                    }
                    // Mark no stripped position for escape sequence bytes.
                    $i = $j;
                    continue;
                }

                // Normal byte — it corresponds to one position in the stripped string.
                $strippedPosToStyle[$strippedPos] = $currentStyle;
                $strippedPos++;
            }

            $strippedPos = 0;

            foreach ($graphemes as $grapheme) {
                if ($col >= $width) {
                    break;
                }

                // Skip zero-width combining characters.
                $gw = self::graphemeWidthInternal($grapheme);
                if ($gw === 0) {
                    $strippedPos++;
                    continue;
                }

                // Look up the style that was active at this grapheme's position
                // in the original ANSI string.
                $sgr = $strippedPosToStyle[$strippedPos] ?? null;
                $cellStyle = self::sgrToStyle($sgr);

                if ($gw === 2 && $col + 1 < $width) {
                    // Wide character: occupies two columns.
                    $grid[] = Cell::new($grapheme, $cellStyle, null, 2);
                    $col += 2;
                    $grid[] = Cell::continuation();
                } else {
                    $grid[] = Cell::new($grapheme, $cellStyle, null, 1);
                    $col++;
                }

                $strippedPos++;
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
     * The canonical xterm-256 palette as 0xRRGGBB: the 16 system colors
     * (used directly for SGR 30-37/90-97/40-47/100-107 and as the head of
     * the 256-entry ramp; 256-color and RGB colors map to a Style color so
     * the cell buffer diff can SEE color-only changes — before this they
     * were silently dropped to null, making 38;5/38;2 a dead knob in delta
     * mode (docs-fix-1023). Re-emission goes through SgrEmitter as
     * truecolor: visually equal to the original, byte-equal only when the
     * original was already 38;2.
     */
    private const BASIC_ANSI_RGB = [
        0x000000, 0x800000, 0x008000, 0x808000,
        0x000080, 0x800080, 0x008080, 0xc0c0c0,
        0x808080, 0xff0000, 0x00ff00, 0xffff00,
        0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
    ];

    /**
     * Convert an SGR parameter string (e.g. "7", "1;32", "38;5;196") into a
     * Style object.
     *
     * Parses SGR codes and builds a Style:
     *   - Attribute codes 1-9 map to ATTR_* constants (bold, faint, italic, etc.)
     *   - 38;5;N / 38;2;R;G;B set the foreground, 48;* the background
     *   - 39/49 reset foreground/background
     *   - Malformed extended-color sequences fall through (the stray codes are
     *     ignored, never guessed) — fail quiet at this display-only boundary
     *
     * @param string|null $sgr SGR parameter string or null for default style
     * @return \SugarCraft\CellBuffer\Style|null
     */
    private static function sgrToStyle(?string $sgr): ?\SugarCraft\CellBuffer\Style
    {
        if ($sgr === null || $sgr === '' || $sgr === '0') {
            return null;
        }

        $attrs = 0;
        $fg = null;
        $bg = null;

        $codes = \array_map('intval', \explode(';', $sgr));
        $count = \count($codes);

        for ($i = 0; $i < $count; $i++) {
            $code = $codes[$i];

            if ($code === 38 || $code === 48) {
                $kind = $codes[$i + 1] ?? null;
                if ($kind === 5 && isset($codes[$i + 2])) {
                    if ($code === 38) {
                        $fg = self::xterm256ToRgb($codes[$i + 2]);
                    } else {
                        $bg = self::xterm256ToRgb($codes[$i + 2]);
                    }
                    $i += 2;
                    continue;
                }
                if ($kind === 2 && isset($codes[$i + 4])) {
                    $rgb = ($codes[$i + 2] << 16) | ($codes[$i + 3] << 8) | $codes[$i + 4];
                    if ($code === 38) {
                        $fg = $rgb;
                    } else {
                        $bg = $rgb;
                    }
                    $i += 4;
                    continue;
                }
                // Malformed extended-color tail: ignore the 38/48 and keep
                // walking so later valid codes still apply.
                continue;
            }

            switch ($code) {
                case 1:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_BOLD;       break;
                case 2:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_FAINT;      break;
                case 3:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_ITALIC;     break;
                case 4:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_UNDERLINE;  break;
                case 5:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_BLINK;      break;
                case 7:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_REVERSE;    break;
                case 8:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_INVISIBLE;  break;
                case 9:  $attrs |= \SugarCraft\CellBuffer\Style::ATTR_STRIKE;     break;
                case 22: $attrs &= ~(\SugarCraft\CellBuffer\Style::ATTR_BOLD | \SugarCraft\CellBuffer\Style::ATTR_FAINT); break;
                case 23: $attrs &= ~\SugarCraft\CellBuffer\Style::ATTR_ITALIC;    break;
                case 24: $attrs &= ~\SugarCraft\CellBuffer\Style::ATTR_UNDERLINE; break;
                case 25: $attrs &= ~\SugarCraft\CellBuffer\Style::ATTR_BLINK;     break;
                case 27: $attrs &= ~\SugarCraft\CellBuffer\Style::ATTR_REVERSE;   break;
                case 29: $attrs &= ~\SugarCraft\CellBuffer\Style::ATTR_STRIKE;    break;
                case 39: $fg = null; break;
                case 49: $bg = null; break;
                default:
                    if ($code >= 30 && $code <= 37) {
                        $fg = self::BASIC_ANSI_RGB[$code - 30];
                    } elseif ($code >= 90 && $code <= 97) {
                        $fg = self::BASIC_ANSI_RGB[$code - 82];
                    } elseif ($code >= 40 && $code <= 47) {
                        $bg = self::BASIC_ANSI_RGB[$code - 40];
                    } elseif ($code >= 100 && $code <= 107) {
                        $bg = self::BASIC_ANSI_RGB[$code - 92];
                    }
            }
        }

        return \SugarCraft\CellBuffer\Style::new($fg, $bg, $attrs);
    }

    /**
     * xterm-256 color index → 0xRRGGBB via the canonical palette
     * (16 system colors, 6×6×6 cube, 24-step grey ramp).
     */
    private static function xterm256ToRgb(int $index): int
    {
        $index = \max(0, \min(255, $index));

        if ($index < 16) {
            return self::BASIC_ANSI_RGB[$index];
        }

        if ($index >= 232) {
            $v = 8 + ($index - 232) * 10;
            return ($v << 16) | ($v << 8) | $v;
        }

        $steps = [0, 95, 135, 175, 215, 255];
        $i = $index - 16;
        $r = $steps[intdiv($i, 36) % 6];
        $g = $steps[intdiv($i, 6) % 6];
        $b = $steps[$i % 6];

        return ($r << 16) | ($g << 8) | $b;
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
