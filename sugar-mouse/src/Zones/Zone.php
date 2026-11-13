<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseAction as CoreAction;
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
 * That keeps the geometry engine in exactly one place (the primitive layer)
 * while consumers of this package (sugar-widgets, sugar-gallery) keep
 * reading `->id` and `->startCol` and pass `MouseMsg` instances through
 * unchanged.
 */
final class Zone
{
    /**
     * Shared geometry engine — the primitive-layer hit-test zone built from
     * the same bounds. All point-in-rect / dimension math routes through it.
     */
    private readonly MouseZone $geometry;

    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(string $id, int $startCol, int $startRow, int $endCol, int $endRow): self
    {
        return new self($id, $startCol, $startRow, $endCol, $endRow);
    }


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
     * Adapt a primitive {@see \SugarCraft\Mouse\Zone} (as parsed by
     * {@see \SugarCraft\Mouse\Scan}) into a Zones-layer Zone, preserving id +
     * bounds. Used by {@see Manager::scan()} to store the SSOT-parsed zones
     * under this namespace's public type.
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
     * scanned. SugarMouse's `Manager::get()` returns null for the same
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
     * Translate a Core wire message into the primitive {@see MouseEvent}
     * vocabulary — the lib's ONE public bridge between the two action
     * enums (Core `Press|Release|Motion` + the `MouseWheelMsg` subclass
     * vs primitive `Press|Release|Drag|Scroll`).
     *
     * Motion maps to Drag (the primitive's held-move form; Core has no
     * button-held flag on plain motion, and geometry consumers treat the
     * two alike). Wheel reports carry Core action `Press` only because
     * sugar-core's InputReader hardcodes it there — the `MouseWheelMsg`
     * type is the discriminator, so it is matched first. Buttons map to
     * the X10 numbers the primitive keys on (`MouseAction::BUTTON_*`,
     * including the wheel pair `BUTTON_WHEEL_UP` / `BUTTON_WHEEL_DOWN`);
     * unmapped Core buttons become LEFT.
     *
     * `inBounds()` / `pos()` still read only x/y from the result, so the
     * richer translation cannot change geometry outcomes — it exists for
     * consumers converting events to feed `ZoneClickTracker` and friends.
     */
    public static function toMouseEvent(MouseMsg $msg): MouseEvent
    {
        $action = match (true) {
            $msg instanceof MouseWheelMsg => MouseGeometryAction::Scroll,
            $msg->action === CoreAction::Release => MouseGeometryAction::Release,
            $msg->action === CoreAction::Motion => MouseGeometryAction::Drag,
            default => MouseGeometryAction::Press,
        };
        $button = match ($msg->button) {
            MouseButton::Middle => MouseGeometryAction::BUTTON_MIDDLE,
            MouseButton::Right => MouseGeometryAction::BUTTON_RIGHT,
            MouseButton::WheelUp => MouseGeometryAction::BUTTON_WHEEL_UP,
            MouseButton::WheelDown => MouseGeometryAction::BUTTON_WHEEL_DOWN,
            default => MouseGeometryAction::BUTTON_LEFT,
        };
        return new MouseEvent($msg->x, $msg->y, $button, $action);
    }

    /** Geometry-only adapter; see {@see toMouseEvent()} for the full bridge. */
    private static function event(MouseMsg $msg): MouseEvent
    {
        return self::toMouseEvent($msg);
    }
}
