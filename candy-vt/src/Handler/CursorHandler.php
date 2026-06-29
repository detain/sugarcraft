<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;

/**
 * Applies CSI cursor-movement sequences to a {@see Cursor}.
 *
 * Covers CUU/CUD/CUF/CUB (A/B/C/D), CUP/HVP (H/f), CNL/CPL (E/F),
 * CHA (G), VPA (d), and the CSI s/u save/restore aliases. Movements
 * are clamped to the buffer bounds or, when DECOM (origin mode) is
 * active, to the scroll region [scrollTop, scrollBottom].
 */
final class CursorHandler
{
    /**
     * @param list<int> $params
     * @param int $scrollTop    Top row of scroll region (0-indexed, inclusive)
     * @param int $scrollBottom Bottom row of scroll region (0-indexed, inclusive)
     * @param bool $originMode DECOM active: cursor addressing relative to scroll region
     */
    public function apply(int $final, array $params, Cursor $cursor, Buffer $buffer, int $scrollTop = 0, int $scrollBottom = -1, bool $originMode = false): Cursor
    {
        if ($scrollBottom < 0) {
            $scrollBottom = $buffer->rows - 1;
        }

        $first = $params[0] ?? -1;
        $count = $first === -1 ? 1 : max(1, $first);

        $minRow = $originMode ? $scrollTop : 0;
        $maxRow = $originMode ? $scrollBottom : $buffer->rows - 1;

        return match (chr($final)) {
            'A' => $cursor->withRow(max($minRow, $cursor->row - $count)),
            'B' => $cursor->withRow(min($maxRow, $cursor->row + $count)),
            'C' => $cursor->withCol(min($buffer->cols - 1, $cursor->col + $count)),
            'D' => $cursor->withCol(max(0, $cursor->col - $count)),
            'E' => $cursor->withRow(min($maxRow, $cursor->row + $count))->withCol(0),
            'F' => $cursor->withRow(max($minRow, $cursor->row - $count))->withCol(0),
            'G' => $cursor->withCol($this->clampCol($count - 1, $buffer)),
            'd' => $cursor->withRow($this->clampRowOrigin($count - 1, $scrollTop, $scrollBottom, $originMode, $buffer)),
            'H', 'f' => $this->cup($params, $cursor, $buffer, $scrollTop, $scrollBottom, $originMode),
            's' => $cursor->save(),
            'u' => $cursor->restore(),
            default => $cursor,
        };
    }

    /** @param list<int> $params */
    private function cup(array $params, Cursor $cursor, Buffer $buffer, int $scrollTop, int $scrollBottom, bool $originMode): Cursor
    {
        $row = $params[0] ?? -1;
        $col = $params[1] ?? -1;
        $row = $row === -1 ? 1 : max(1, $row);
        $col = $col === -1 ? 1 : max(1, $col);

        if ($originMode) {
            $absRow = $scrollTop + ($row - 1);
        } else {
            $absRow = $row - 1;
        }

        return $cursor
            ->withRow($this->clampRow($absRow, $buffer))
            ->withCol($this->clampCol($col - 1, $buffer));
    }

    private function clampCol(int $col, Buffer $buffer): int
    {
        return max(0, min($buffer->cols - 1, $col));
    }

    private function clampRow(int $row, Buffer $buffer): int
    {
        return max(0, min($buffer->rows - 1, $row));
    }

    /**
     * Clamp row for VPA (vertical position absolute, 'd').
     * When originMode is true, interprets row as relative to scroll region.
     */
    private function clampRowOrigin(int $row, int $scrollTop, int $scrollBottom, bool $originMode, Buffer $buffer): int
    {
        if ($originMode) {
            $absRow = $scrollTop + $row;
            return max($scrollTop, min($scrollBottom, $absRow));
        }
        return $this->clampRow($row, $buffer);
    }
}
