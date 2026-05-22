<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Reply to a {@see \SugarCraft\Core\Cmd::requestCursorPosition()}. The
 * terminal answers `CSI <row> ; <col> R` (1-based, like all CSI cursor
 * coordinates) and {@see \SugarCraft\Core\InputReader} converts it into
 * this Msg.
 */
final class CursorPositionMsg implements Msg
{
    public function __construct(
        public readonly int $row,
        public readonly int $col,
    ) {
    }
}
