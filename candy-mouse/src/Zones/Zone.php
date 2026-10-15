<?php

declare(strict_types=1);

namespace SugarCraft\Zone;

use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\MouseAction as MouseGeometryAction;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Zone as MouseZone;

/**
 * A rectangular zone discovered by {@see Manager::scan()}. Coordinates are
 * 1-based terminal cells, matching {@see MouseMsg::$x} / {@see MouseMsg::$y}.
 *
 * The zone is the smallest axis-aligned rectangle that contains every cell
 * occupied by the marked content.
 *
 * Backward-compatibility adapter: this type keeps its historical public
 * surface (readonly bounds + `MouseMsg`-based {@see inBounds()} / {@see pos()}
 * plus {@see contains()}), but delegates the point-in-rect / width / height
 * math to a wrapped {@see \SugarCraft\Mouse\Zone} built from the same bounds.
 * That keeps the geometry engine in exactly one place (candy-mouse) across
 * both zone ports while consumers of candy-zone (sugar-bits, sugar-gallery)
 * keep reading `->id`, `->startCol`, … and passing `MouseMsg`s unchanged.
 */
final class Zone
{
    /**
     * Shared geometry engine — the candy-mouse hit-test primitive built from
     * the same bounds. All point-in-rect / dimension math routes through it.
     */
    private readonly MouseZone $geometry;

    public function __construct(
        public readonly string $id,
        public readonly int $startCol,
        public readonly int $startRow,
        public readonly int $endCol,
        public readonly int $endRow,
    ) {
        $this->geometry = new MouseZone($id, $startCol, $startRow, $endCol, $endRow);
    }

    /**
     * Adapt a candy-mouse {@see \SugarCraft\Mouse\Zone} into a candy-zone Zone,
     * preserving id + bounds. Used by {@see Manager::scan()} to store the
     * SSOT-parsed zones under candy-zone's public type.
     */
    public static function fromMouseZone(MouseZone $zone): self
    {
        return new self(
            $zone->id,
            $zone->startCol,
            $zone->startRow,
            $zone->endCol,
            $zone->endRow,
        );
    }

    public function inBounds(MouseMsg $msg): bool
    {
        return $this->geometry->inBounds(self::event($msg));
    }

    /**
     * Mouse position relative to the zone's top-left, 0-based.
     * Returns negative values when the mouse is outside the zone.
     *
     * @return array{0:int,1:int} [col, row]
     */
    public function pos(MouseMsg $msg): array
    {
        return $this->geometry->pos(self::event($msg));
    }

    public function width(): int  { return $this->geometry->width(); }
    public function height(): int { return $this->geometry->height(); }

    /**
     * True when the zone has no recorded position — bubblezone returns
     * a zero-valued struct from `Get()` if the id has never been
     * scanned. CandyZone's `Manager::get()` returns null for the same
     * case, but holders of a Zone that was never scanned (e.g. an
     * end-marker arrived without a start) can use `isZero()` to spot
     * the degenerate state without a special-case check at every site.
     */
    public function isZero(): bool
    {
        return $this->geometry->isZero();
    }

    /**
     * True when $other is entirely contained within this zone.
     * Uses closed interval semantics (boundaries are inclusive),
     * matching {@see inBounds()}.
     */
    public function contains(Zone $other): bool
    {
        return $other->startCol >= $this->startCol
            && $other->endCol <= $this->endCol
            && $other->startRow >= $this->startRow
            && $other->endRow <= $this->endRow;
    }

    /**
     * Bridge a Core {@see MouseMsg} into a candy-mouse {@see MouseEvent} for
     * geometry checks. Only x/y are read by the geometry primitive; button
     * and action are irrelevant here, so a placeholder Press is used.
     */
    private static function event(MouseMsg $msg): MouseEvent
    {
        return new MouseEvent($msg->x, $msg->y, 0, MouseGeometryAction::Press);
    }
}
