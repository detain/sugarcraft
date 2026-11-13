<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

use SugarCraft\Core\Util\Width;
use SugarCraft\TreeList\Support\AssertGlyph;

/**
 * Default box-drawing prefixer with line numbers and tree-style borders.
 *
 * Renders items with:
 * - ╭ / ├ / ╰ box-drawing separators chosen by the item's POSITION in the
 *   rendered list (first / middle / last), with │ on wrap continuations
 * - optional line numbers (absolute or relative to cursor)
 * - > current-item marker
 * - wrap continuation prefix
 *
 * All separator/marker glyph fields are validated at init: control bytes
 * (C0, DEL, C1, or malformed UTF-8) are refused loudly so the gutter can
 * never smuggle terminal escapes onto the screen. An empty string is a
 * legal value (it drops the decoration).
 *
 * The instance is shared across every item of a render pass (and across
 * clones of the model that set it): the position state below is re-sealed
 * by initPrefixer() for each item, so two models rendering alternately to
 * the same terminal never interleave stale positions.
 */
final class DefaultPrefixer implements Prefixer
{
    public bool $number = true;          // show line numbers
    public bool $numberRelative = false; // show each item's unsigned distance from the cursor
    public bool $prefixWrap = true;      // wrap with │ continuation

    public string $firstSep      = '╭';   // top-left corner (first item)
    public string $separator     = '├';   // mid join (items between the ends)
    public string $lastSep       = '╰';   // bottom-left corner (last item)
    public string $separatorWrap = '│';   // continuation

    public string $currentMarker = '>';   // marks current item
    public string $emptyMarker   = ' ';   // non-current items

    private int $currentIndex  = 0;  // index of the item being rendered
    private int $cursorIndex   = 0;  // index of the real cursor position
    private int $totalItems    = 1;  // items in the rendered list (set by initPrefixer)
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
        int $totalItems,
    ): int {
        // Fail fast BEFORE emitting anything: a glyph field carrying control
        // bytes would reach the terminal verbatim (the old path never checked).
        // Validation lives in the shared Support\AssertGlyph owner so the
        // Default prefixer/suffixer can never drift apart.
        AssertGlyph::field(self::class, 'firstSep', $this->firstSep);
        AssertGlyph::field(self::class, 'separator', $this->separator);
        AssertGlyph::field(self::class, 'lastSep', $this->lastSep);
        AssertGlyph::field(self::class, 'separatorWrap', $this->separatorWrap);
        AssertGlyph::field(self::class, 'currentMarker', $this->currentMarker);
        AssertGlyph::field(self::class, 'emptyMarker', $this->emptyMarker);

        $this->currentIndex = $currentIndex;
        $this->cursorIndex  = $cursorIndex;
        $this->totalItems   = \max(1, $totalItems);

        // Compute number field width from the LARGEST number a pass can render.
        // Sizing on $currentIndex alone made item 9 reserve 6 prefix columns and
        // item 10 reserve 7 — ragged borders for every ≥10-item list. Absolute
        // mode renders indices up to totalItems-1 and relative mode renders
        // distances bounded by the same value, so one width serves both.
        if ($this->number) {
            $this->numWidth = \max(1, \strlen((string) \max(0, $totalItems - 1)));
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

        // Separator — the join glyph reflects the item's position in the
        // rendered list. The old shape keyed ╭ on the LINE index, so every
        // single-line item got the top corner and ├/╰ were unreachable: the
        // advertised tree gutter never appeared. A one-item list is both ends
        // at once and renders as the top corner.
        if ($isFirst) {
            $sep = match (true) {
                $this->currentIndex <= 0 => $this->firstSep,
                $this->currentIndex >= $this->totalItems - 1 => $this->lastSep,
                default => $this->separator,
            };
        } else {
            $sep = $this->prefixWrap ? $this->separatorWrap : $this->separator;
        }

        // Line number
        $numStr = '';
        if ($this->number) {
            if ($this->numberRelative && $currentLine === 0) {
                // Distance from cursor, unsigned: rows above and below the
                // cursor mirror each other (2 1 0 1 2). Signed display would
                // widen negative rows and break column alignment.
                $numStr = \sprintf("%{$this->numWidth}d ", \abs($this->currentIndex - $this->cursorIndex));
            } elseif ($currentLine === 0) {
                $numStr = \sprintf("%{$this->numWidth}d ", $this->currentIndex);
            } else {
                $numStr = \str_repeat(' ', $this->numWidth) . ' ';
            }
        }

        // Current marker (only on first line of current item)
        $mark = ($isCurrent && $currentLine === 0) ? $this->currentMarker : $this->emptyMarker;

        return "{$sep} {$numStr}{$mark} ";
    }
}
