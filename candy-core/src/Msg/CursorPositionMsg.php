<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Reply to a {@see \CandyCore\Core\Cmd::requestCursorPosition()}. The
 * terminal answers `CSI <row> ; <col> R` (1-based, like all CSI cursor
 * coordinates) and {@see \CandyCore\Core\InputReader} converts it into
 * this Msg.
 */
final class CursorPositionMsg implements Msg
{
    public function __construct(
        public readonly int $row,
        public readonly int $col,
    ) {}
}
