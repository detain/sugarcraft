<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

use SugarCraft\Core\Util\Width;

/**
 * Default box-drawing prefixer with line numbers and tree-style borders.
 *
 * Renders items with:
 * - ╭ / ├ / │ box-drawing separators
 * - optional line numbers (absolute or relative to cursor)
 * - > current-item marker
 * - wrap continuation prefix
 */
final class DefaultPrefixer implements Prefixer
{
    public bool $number = true;          // show line numbers
    public bool $numberRelative = false; // relative to cursor
    public bool $prefixWrap = true;      // wrap with │ continuation

    public string $firstSep      = '╭';   // top-left corner
    public string $separator     = '├';   // mid join
    public string $separatorWrap = '│';   // continuation

    public string $currentMarker = '>';   // marks current item
    public string $emptyMarker   = ' ';   // non-current items

    private int $currentIndex  = 0;  // index of the item being rendered
    private int $cursorIndex   = 0;  // index of the real cursor position
    private int $numWidth      = 1;       // width of number field
    private int $markWidth     = 1;       // width of current marker
    private int $sepWidth      = 1;       // width of separator
    private int $prefixWidth   = 0;       // total prefix width (set by initPrefixer)

    public function initPrefixer(
        \Stringable $value,
        int $currentIndex,
        int $cursorIndex,
        int $lineOffset,
        int $width,
        int $height,
    ): int {
        $this->currentIndex = $currentIndex;
        $this->cursorIndex  = $cursorIndex;

        // Compute number field width
        if ($this->number) {
            $this->numWidth = \strlen((string) $currentIndex);
            if ($this->numberRelative) {
                $max = \max($cursorIndex, \abs($cursorIndex - $lineOffset));
                $this->numWidth = \strlen((string) $max);
            }
        }

        // Separator widths
        $this->sepWidth     = Width::string($this->separator);
        $this->markWidth    = Width::string($this->currentMarker);

        // The prefix = [sep] [number] [mark] + 2 spaces
        $this->prefixWidth  = $this->sepWidth
                            + ($this->number ? $this->numWidth + 1 : 0)  // +1 for space after number
                            + $this->markWidth
                            + 2;

        return $this->prefixWidth;
    }

    public function prefix(int $currentLine, int $totalLines): string
    {
        $isFirst    = ($currentLine === 0);
        $isCurrent  = ($this->currentIndex === $this->cursorIndex); // true when rendering the cursor item
        $isWrap     = (!$isFirst && $this->prefixWrap);

        // Separator
        $sep = $isWrap ? $this->separatorWrap : ($isFirst ? $this->firstSep : $this->separator);

        // Line number
        $numStr = '';
        if ($this->number) {
            if ($this->numberRelative && $currentLine === 0) {
                // Show distance from cursor for first line
                $numStr = \sprintf("%{$this->numWidth}d ", \abs($this->currentIndex - $this->cursorIndex));
            } elseif ($currentLine === 0) {
                $numStr = \sprintf("%{$this->numWidth}s ", (string) $this->currentIndex);
            } else {
                $numStr = \str_repeat(' ', $this->numWidth) . ' ';
            }
        }

        // Current marker (only on first line of current item)
        $mark = ($isCurrent && $currentLine === 0) ? $this->currentMarker : $this->emptyMarker;

        return "{$sep} {$numStr}{$mark} ";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
}
