<?php

declare(strict_types=1);

namespace CandyCore\Zone;

use CandyCore\Core\Msg\MouseMsg;

/**
 * A rectangular zone discovered by {@see Manager::scan()}. Coordinates are
 * 1-based terminal cells, matching {@see MouseMsg::$x} / {@see MouseMsg::$y}.
 *
 * The zone is the smallest axis-aligned rectangle that contains every cell
 * occupied by the marked content.
 */
final class Zone
{
    public function __construct(
        public readonly string $id,
        public readonly int $startCol,
        public readonly int $startRow,
        public readonly int $endCol,
        public readonly int $endRow,
    ) {}

    public function inBounds(MouseMsg $msg): bool
    {
        return $msg->x >= $this->startCol && $msg->x <= $this->endCol
            && $msg->y >= $this->startRow && $msg->y <= $this->endRow;
    }

    /**
     * Mouse position relative to the zone's top-left, 0-based.
     * Returns negative values when the mouse is outside the zone.
     *
     * @return array{0:int,1:int} [col, row]
     */
    public function pos(MouseMsg $msg): array
    {
        return [$msg->x - $this->startCol, $msg->y - $this->startRow];
    }

    public function width(): int  { return $this->endCol - $this->startCol + 1; }
    public function height(): int { return $this->endRow - $this->startRow + 1; }
}
