<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Table;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\Width;

/**
 * Stateful diff renderer for Table.
 *
 * Holds the mutable diff state (previousFrame, prevWidth, prevHeight) and
 * produces either a full frame or a delta-encoded frame. A frame is FULL
 * whenever there is nothing to diff against: the first render after
 * construction, after reset(), or after any change to the emitted width or
 * height (the dimension watch clears the stored buffer). Every same-size
 * later frame goes out delta-encoded.
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
     * A table with no columns (or no lines) has no grid to address: it
     * renders as the empty string and resets the diff state, so an empty
     * mid-edit table cannot fatal the frame loop of the delta use-case it
     * exists for.
     *
     * @return string Full frame or delta-encoded ops
     */
    public function render(Table $table): string
    {
        $lines = $table->buildLines();

        // Output box from the column ARITHMETIC, never from a Width::string
        // scan of just-built lines: header, ruler and every data row pad or
        // clamp to exactly Σ width + sep-cells × joints, and asking the
        // sugar-core memo what a line measures costs more than building it
        // did once the distinct strings pass the memo's 2048-entry cap (the
        // half-slice eviction turns steady-state frames into a 4× penalty —
        // the LRU/cap raise itself is an owed sugar-core closeout row).
        $height = \count($lines);
        $width = $table->computeTotalWidth();

        // A changed size still re-emits, but NEVER drop the old frame bare
        // on a SHRINK (finding 004): cells right of the narrower box and
        // below the shorter one sat outside every new full frame, so stale
        // ghosts painted on screen forever. Diff on a blank canvas sized
        // max(previous, current) instead — cellbuffer turns the out-of-range
        // region into erase runs that actually clear them. Growth has
        // nothing outside the box to clear, so it keeps the plain full frame.
        $previousWidth = $this->prevWidth;
        $previousHeight = $this->prevHeight;
        $dimensionChanged = $previousWidth !== null && ($previousWidth !== $width || $previousHeight !== $height);
        $shrank = $dimensionChanged && ($previousWidth > $width || $previousHeight > $height);
        $this->prevWidth = $width;
        $this->prevHeight = $height;

        // Zero columns (or a headerless empty grid) has no cells to address:
        // render as empty and forget any previous frame.
        if ($width <= 0 || $height <= 0) {
            $this->previousFrame = null;
            return '';
        }

        // First frame — and any frame that only grew: emit full output.
        // The string is imploded only on this path; delta frames never pay
        // for bytes nobody will print (finding 053's copy leg).
        if ($this->previousFrame === null || ($dimensionChanged && !$shrank)) {
            $this->previousFrame = $this->bufferFromOutput($lines, $width, $height);
            return \implode("\n", $lines);
        }

        // Subsequent frames: compute the delta. A shrink diffs both operands
        // padded to the union canvas so erased regions emit; the stored
        // frame returns to logical size because the canvas margin has by
        // now been cleared on screen.
        $currentFrame = $this->bufferFromOutput($lines, $width, $height);
        $ops = $dimensionChanged
            ? $this->paintOnCanvas($currentFrame, max($previousWidth, $width), max($previousHeight, $height))
                ->diff($this->paintOnCanvas($this->previousFrame, max($previousWidth, $width), max($previousHeight, $height)))
            : $currentFrame->diff($this->previousFrame);
        $this->previousFrame = $currentFrame;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
    }

    /**
     * Grow a frame to the canvas both diff operands need.
     *
     * Buffer::diff demands equal dimensions; the union canvas is blank by
     * construction, so anything the new frame does not paint reads as an
     * erase against the grown previous frame.
     */
    private function paintOnCanvas(\SugarCraft\CellBuffer\Buffer $frame, int $canvasWidth, int $canvasHeight): \SugarCraft\CellBuffer\Buffer
    {
        if ($frame->width() === $canvasWidth && $frame->height() === $canvasHeight) {
            return $frame;
        }
        return \SugarCraft\CellBuffer\Buffer::new($canvasWidth, $canvasHeight)
            ->withRegion($frame->region(), $frame);
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
     * Build a Buffer from the rendered lines.
     *
     * Each line is walked once, splitting it into literal runs tagged with
     * the SGR state active at that point (attributes accumulate exactly as
     * a terminal accumulates them, and styles are attributed per GRAPHEME
     * CLUSTER — never per UTF-8 byte, so a multibyte separator such as the
     * default ' │ ' cannot shift style attribution off the cells it belongs
     * to). Width comes from the canonical sugar-core Width table; a wide
     * glyph that would straddle the right edge is clipped there (a
     * continuation pair cannot be forged at the boundary).
     *
     * @param list<string> $lines Rendered lines from Table::buildLines()
     * @param int          $width Buffer width in cells
     * @param int          $height Buffer height in rows
     */
    private function bufferFromOutput(array $lines, int $width, int $height): Buffer
    {
        $grid = [];

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            [$graphemes, $styles] = self::splitStyledLine($line);

            $col = 0;
            $count = \count($graphemes);
            for ($k = 0; $k < $count && $col < $width; $k++) {
                $grapheme = $graphemes[$k];
                $gw = Width::string($grapheme);
                if ($gw === 0) {
                    // Zero-width cluster (lone ZWJ, variation selector):
                    // occupies no column, matches what the terminal paints.
                    continue;
                }

                $cellStyle = $styles[$k];

                if ($gw >= 2) {
                    if ($col + 1 >= $width) {
                        // Wide glyph does not fit in the remaining columns:
                        // clip it (the blank pad below fills the row out).
                        break;
                    }
                    $grid[] = Cell::new($grapheme, $cellStyle, null, 2);
                    $grid[] = Cell::continuation();
                    $col += 2;
                } else {
                    $grid[] = Cell::new($grapheme, $cellStyle, null, 1);
                    $col++;
                }
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
     * Split one rendered line into parallel lists of grapheme clusters and
     * the Style active at each cluster (null = default cells).
     *
     * SGR sequences mutate an accumulating state (a terminal never forgets
     * `\x1b[1m` when `\x1b[31m` arrives; both stay in force until reset),
     * while any number of escape sequences between two characters consume no
     * cluster slots — the previous byte-indexed, last-SGR-wins map shifted
     * styles rightward of every multibyte character.
     *
     * One preg_split over the CSI family replaces the old three-walk loop:
     * even parts are literals (the only runs needing a grapheme split),
     * odd parts are escapes. A part whose final byte is 'm' with an all
     * digits-and-semicolons body is exactly the SGR form applySgr() speaks;
     * every other CSI (cursor moves, erases — and a truncated tail at the
     * line end, which the `[a-zA-Z]?` arm swallows whole, as the old scan
     * did) moves the terminal but paints nothing and is dropped. Bare ESC
     * or non-CSI escape bytes stay in the literal run: Width::string maps
     * them to zero cells, so they never produce a cell here either.
     *
     * @return array{list<string>, list<Style|null>}
     */
    private static function splitStyledLine(string $line): array
    {
        $graphemes = [];
        $styles = [];
        $state = self::freshSgrState();

        $parts = \preg_split(
            '/(\x1b\[[^a-zA-Z]*[a-zA-Z]?)/',
            $line,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE
        );

        foreach ($parts as $index => $part) {
            if (($index & 1) === 0) {
                if ($part === '') {
                    continue;
                }
                $style = self::styleFromState($state);
                foreach (self::graphemes($part) as $grapheme) {
                    $graphemes[] = $grapheme;
                    $styles[]    = $style;
                }
                continue;
            }
            // Consume every CSI-shaped run (the old scan did), but apply
            // state only to the exact SGR form: a body of stray junk never
            // touches the accumulation, it merely stays off the grid.
            if (\preg_match('/^\x1b\[([0-9;]*)m$/', $part, $m) === 1) {
                self::applySgr($m[1], $state);
            }
        }

        return [$graphemes, $styles];
    }

    /** @return array{attrs:int,fg:int|null,bg:int|null} */
    private static function freshSgrState(): array
    {
        return ['attrs' => 0, 'fg' => null, 'bg' => null];
    }

    /**
     * Apply one SGR parameter string to the accumulating state.
     *
     * Attribute codes 1-5 and 7-9 set their ATTR_* bit (6 is unused);
     * the clear door is the exact set 22 (drops bold and faint together),
     * 23, 24, 25, 27 and 29 — there is no 26 arm (undefined in the cell
     * model) and no 28 arm, so an INVISIBLE (8) cell is only ever lifted
     * by a full reset. 38;5;N / 38;2;R;G;B set the foreground and 48;*
     * the background (RGB components clamp to 0-255); 39/49 reset one
     * channel; '0' or an empty parameter string resets everything. Codes
     * with no cell-buffer meaning (overline 53, fonts, hyperlinks) are
     * no-ops — they must not materialize a Style, or every such frame
     * churns the diff with invisible changes.
     *
     * @param array{attrs:int,fg:int|null,bg:int|null} $state
     */
    private static function applySgr(string $params, array &$state): void
    {
        if ($params === '' || $params === '0') {
            $state = self::freshSgrState();
            return;
        }

        $codes = \array_map('intval', \explode(';', $params));
        $count = \count($codes);

        for ($i = 0; $i < $count; $i++) {
            $code = $codes[$i];

            if ($code === 38 || $code === 48) {
                $kind = $codes[$i + 1] ?? null;
                if ($kind === 5 && isset($codes[$i + 2])) {
                    $rgb = self::xterm256ToRgb($codes[$i + 2]);
                    if ($code === 38) {
                        $state['fg'] = $rgb;
                    } else {
                        $state['bg'] = $rgb;
                    }
                    $i += 2;
                    continue;
                }
                if ($kind === 2 && isset($codes[$i + 4])) {
                    $r = \max(0, \min(255, $codes[$i + 2]));
                    $g = \max(0, \min(255, $codes[$i + 3]));
                    $b = \max(0, \min(255, $codes[$i + 4]));
                    $rgb = ($r << 16) | ($g << 8) | $b;
                    if ($code === 38) {
                        $state['fg'] = $rgb;
                    } else {
                        $state['bg'] = $rgb;
                    }
                    $i += 4;
                    continue;
                }
                // Malformed extended-color tail: ignore the 38/48 and keep
                // walking so later valid codes still apply.
                continue;
            }

            switch ($code) {
                case 1:  $state['attrs'] |= Style::ATTR_BOLD;       break;
                case 2:  $state['attrs'] |= Style::ATTR_FAINT;      break;
                case 3:  $state['attrs'] |= Style::ATTR_ITALIC;     break;
                case 4:  $state['attrs'] |= Style::ATTR_UNDERLINE;  break;
                case 5:  $state['attrs'] |= Style::ATTR_BLINK;      break;
                case 7:  $state['attrs'] |= Style::ATTR_REVERSE;    break;
                case 8:  $state['attrs'] |= Style::ATTR_INVISIBLE;  break;
                case 9:  $state['attrs'] |= Style::ATTR_STRIKE;     break;
                case 22: $state['attrs'] &= ~(Style::ATTR_BOLD | Style::ATTR_FAINT); break;
                case 23: $state['attrs'] &= ~Style::ATTR_ITALIC;    break;
                case 24: $state['attrs'] &= ~Style::ATTR_UNDERLINE; break;
                case 25: $state['attrs'] &= ~Style::ATTR_BLINK;     break;
                case 27: $state['attrs'] &= ~Style::ATTR_REVERSE;   break;
                case 29: $state['attrs'] &= ~Style::ATTR_STRIKE;    break;
                case 39: $state['fg'] = null; break;
                case 49: $state['bg'] = null; break;
                default:
                    if ($code >= 30 && $code <= 37) {
                        $state['fg'] = self::BASIC_ANSI_RGB[$code - 30];
                    } elseif ($code >= 90 && $code <= 97) {
                        $state['fg'] = self::BASIC_ANSI_RGB[$code - 82];
                    } elseif ($code >= 40 && $code <= 47) {
                        $state['bg'] = self::BASIC_ANSI_RGB[$code - 40];
                    } elseif ($code >= 100 && $code <= 107) {
                        $state['bg'] = self::BASIC_ANSI_RGB[$code - 92];
                    }
            }
        }
    }

    /**
     * Materialize the accumulated state; a state with no visible change is
     * null (no Style object), so style-less cells diff against style-less
     * cells instead of churning on phantom attributes.
     *
     * @param array{attrs:int,fg:int|null,bg:int|null} $state
     */
    private static function styleFromState(array $state): ?Style
    {
        if ($state['attrs'] === 0 && $state['fg'] === null && $state['bg'] === null) {
            return null;
        }
        return Style::new($state['fg'], $state['bg'], $state['attrs']);
    }

    /**
     * The canonical xterm-256 palette as 0xRRGGBB: the 16 system colors
     * (used directly for SGR 30-37/90-97/40-47/100-107 and as the head of
     * the 256-entry ramp; 256-color and RGB colors map to a Style color so
     * the cell buffer diff can SEE color-only changes. Re-emission goes
     * through the cell buffer's SGR emitter as truecolor: visually equal to
     * the original, byte-equal only when the original was already 38;2.
     */
    private const BASIC_ANSI_RGB = [
        0x000000, 0x800000, 0x008000, 0x808000,
        0x000080, 0x800080, 0x008080, 0xc0c0c0,
        0x808080, 0xff0000, 0x00ff00, 0xffff00,
        0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
    ];

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
     * Ladder: grapheme_str_split (PHP >= 8.4 with intl), then
     * IntlBreakIterator (PHP 8.3 with intl — this extension provides real
     * UAX #29 cluster boundaries), then mb_str_split as the last-resort
     * fallback, which splits PER CODEPOINT, not per cluster — on such a
     * build a ZWJ family emoji occupies several cells, mirroring terminals
     * without cluster shaping rather than lying about it.
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
        if (\class_exists(\IntlBreakIterator::class)) {
            $iterator = \IntlBreakIterator::createCharacterInstance();
            $iterator->setText($s);
            $out = [];
            $previous = 0;
            foreach ($iterator as $end) {
                if ($end > $previous) {
                    $out[] = \substr($s, $previous, $end - $previous);
                    $previous = $end;
                }
            }
            return $out;
        }
        if (\function_exists('mb_str_split')) {
            return mb_str_split($s, 1, 'UTF-8');
        }
        return \preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
