<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Vms;

use SugarCraft\Top\View\Rect;

/**
 * The VM dashboard's card grid inside its box — the single geometry the
 * painter, the keyboard navigation and the mouse map share.
 *
 * Columns follow the width: as many {@see CARD_MIN_WIDTH}-wide cards as
 * fit with one blank column between them, the spare columns spread over
 * the leftmost cards so the grid spans the box exactly. Cards are
 * {@see CARD_HEIGHT} rows (a title border, cpu, ram, disk, net, a bottom
 * border); when the whole fleet fits with room to spare they grow to
 * fill the box, the extra rows becoming a PSI line, a full-width cpu
 * history and taller disk / net graphs ({@see VmCard}); a box shorter
 * than one card gets one row of cut-down cards (down to
 * {@see CARD_MIN_HEIGHT}).
 * Rows of cards scroll: `start` is the first visible grid row.
 */
final class VmsGrid
{
    public const CARD_MIN_WIDTH = 34;

    public const CARD_HEIGHT = 6;

    /** The shortest card drawn: its title border, the cpu row and its bottom border. */
    public const CARD_MIN_HEIGHT = 3;

    private function __construct(
        public readonly int $columns,
        public readonly int $rows,
        public readonly int $cardHeight,
        private readonly int $innerWidth,
    ) {
    }

    /** The grid for a `$width` x `$height` box (border included) holding `$count` cards. */
    public static function for(int $width, int $height, int $count): self
    {
        $innerW = max(0, $width - 2);
        $innerH = max(0, $height - 2);
        $columns = max(1, intdiv($innerW + 1, self::CARD_MIN_WIDTH + 1));
        $needed = max(1, intdiv(max(0, $count) + $columns - 1, $columns));
        $cardH = self::CARD_HEIGHT;
        if ($needed * self::CARD_HEIGHT <= $innerH) {
            // The whole fleet fits: the cards share the box's height.
            $cardH = intdiv($innerH, $needed);
        }
        // A box squeezed below one card (GPU rows on a short terminal) still
        // shows a row of cards, cut to the rows they get.
        if ($innerH < self::CARD_HEIGHT) {
            $cardH = max(self::CARD_MIN_HEIGHT, $innerH);
        }

        return new self($columns, $innerH >= self::CARD_MIN_HEIGHT ? intdiv($innerH, $cardH) : 0, $cardH, $innerW);
    }

    /** Cards visible at once. */
    public function perPage(): int
    {
        return $this->columns * $this->rows;
    }

    /** Grid rows `$count` cards need. */
    public function totalRows(int $count): int
    {
        return intdiv(max(0, $count) + $this->columns - 1, $this->columns);
    }

    /** The card at visible slot `$slot` (row-major) in box-local coordinates. */
    public function card(int $slot): Rect
    {
        $col = $slot % $this->columns;
        $row = intdiv($slot, $this->columns);
        $space = max(0, $this->innerWidth - ($this->columns - 1));
        $base = intdiv($space, $this->columns);
        $extra = $space % $this->columns;

        return Rect::new(
            1 + $col * ($base + 1) + min($col, $extra),
            1 + $row * $this->cardHeight,
            $base + ($col < $extra ? 1 : 0),
            $this->cardHeight,
        );
    }

    /** The visible slot under box-local ($x, $y), null between or below cards or past `$shown`. */
    public function slotAt(int $x, int $y, int $shown): ?int
    {
        for ($slot = 0; $slot < min($shown, $this->perPage()); $slot++) {
            if ($this->card($slot)->contains($x, $y)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The first visible grid row: the selected card kept in view (`start
     * = clamp(start, selRow - rows + 1, selRow)`, the ctr box's law by
     * rows), then clamped so the last page is full.
     */
    public function scroll(int $start, int $selected, int $count): int
    {
        $rows = max(1, $this->rows);
        if ($selected >= 0) {
            $row = intdiv($selected, $this->columns);
            $start = max($row - $rows + 1, min($start, $row));
        }

        return max(0, min($start, $this->totalRows($count) - $rows));
    }
}
