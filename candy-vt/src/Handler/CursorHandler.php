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
 * are clamped to the buffer bounds.
 */
final class CursorHandler
{
    /**
     * @param list<int> $params
     */
    public function apply(int $final, array $params, Cursor $cursor, Buffer $buffer): Cursor
    {
        $first = $params[0] ?? -1;
        $count = $first === -1 ? 1 : max(1, $first);

        return match (chr($final)) {
            'A' => $cursor->withRow(max(0, $cursor->row - $count)),
            'B' => $cursor->withRow(min($buffer->rows - 1, $cursor->row + $count)),
            'C' => $cursor->withCol(min($buffer->cols - 1, $cursor->col + $count)),
            'D' => $cursor->withCol(max(0, $cursor->col - $count)),
            'E' => $cursor->withRow(min($buffer->rows - 1, $cursor->row + $count))->withCol(0),
            'F' => $cursor->withRow(max(0, $cursor->row - $count))->withCol(0),
            'G' => $cursor->withCol($this->clampCol($count - 1, $buffer)),
            'd' => $cursor->withRow($this->clampRow($count - 1, $buffer)),
            'H', 'f' => $this->cup($params, $cursor, $buffer),
            's' => $cursor->save(),
            'u' => $cursor->restore(),
            default => $cursor,
        };
    }

    /** @param list<int> $params */
    private function cup(array $params, Cursor $cursor, Buffer $buffer): Cursor
    {
        $row = $params[0] ?? -1;
        $col = $params[1] ?? -1;
        $row = $row === -1 ? 1 : max(1, $row);
        $col = $col === -1 ? 1 : max(1, $col);
        return $cursor
            ->withRow($this->clampRow($row - 1, $buffer))
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
}
