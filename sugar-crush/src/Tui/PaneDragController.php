<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Layout\Dock\Side;

/**
 * The mouse gesture state behind pane docking: one drag in flight, or idle.
 *
 * The gesture phase's answer to candy-zone's `DragTracker` (which it does NOT
 * adopt — see the measurement in the phase plan's step 0): a resize needs raw
 * column arithmetic against the LIVE band while the pointer stays inside a
 * run of per-row single-cell zones, whereas `DragTracker` only speaks in
 * zone-boundary crossings and strands a release whose origin zone was
 * re-rendered away. This controller instead keeps the three facts a gesture
 * needs — what was grabbed, where it was grabbed, and whether the pointer
 * has moved far enough to count as a drag — and leaves the hit-testing to
 * the two registries the shell already paints
 * ({@see \SugarCraft\Crush\Tui\Renderer::chromeZoneAt()}).
 *
 * Immutable value object per the house law; a drag spans several `update()`
 * calls and `App` is immutable, so the live instance is parked in the same
 * kind of static the shell's click trackers already use (see
 * `App::chromeClickTracker()`) and every transition returns a fresh copy via
 * {@see mutate()}.
 *
 * FOLLOWS-UP SEAM (stated, not hidden): a `stackdiv:<side>:<slot>:r<row>`
 * press is currently consumed as a no-op — intra-stack HEIGHT dragging needs
 * the row-band of each slot and a `withStackWeight` decision the gesture
 * phase scoped out; column resize and side docking are the user-visible
 * musts this class ships.
 */
final class PaneDragController
{
    /**
     * Manhattan distance past which a press-plus-motion becomes a drag
     * rather than a click. One cell, so it agrees with Chat's
     * click-versus-selection tolerance a pointer passes through on the way
     * out of a chrome zone; a release inside it still completes the plain
     * click the tracker would have completed.
     */
    public const DRAG_ARM_TOLERANCE_CELLS = 1;

    private const KIND_IDLE = 'idle';
    private const KIND_RESIZE = 'resize';
    private const KIND_DOCK = 'dock';

    private function __construct(
        private readonly string $kind,
        private readonly ?Side $side,
        private readonly int $grabCol,
        private readonly ?string $paneId,
        private readonly int $pressX,
        private readonly int $pressY,
        private readonly bool $armed,
        private readonly bool $previewed,
        private readonly int $pointerX,
        private readonly int $pointerY,
    ) {
    }

    /**
     * The no-gesture state every frame starts in.
     */
    public static function idle(): self
    {
        return new self(
            kind: self::KIND_IDLE,
            side: null,
            grabCol: 0,
            paneId: null,
            pressX: 0,
            pressY: 0,
            armed: false,
            previewed: false,
            pointerX: 0,
            pointerY: 0,
        );
    }

    public function isIdle(): bool
    {
        return $this->kind === self::KIND_IDLE;
    }

    /**
     * A press landed on `divider:<side>:r<row>` — the pointer now owns that
     * side's band width until the release.
     */
    public function beginResize(Side $side, int $grabCol): self
    {
        return $this->mutate(
            kind: self::KIND_RESIZE,
            side: $side,
            grabCol: $grabCol,
            paneId: null,
            paneIdSet: true,
            armed: true,
        );
    }

    /**
     * A press landed on one of a docked pane's header surfaces — its frame
     * header (`pane:<id>`) or the menu-bar tab standing for it
     * (`panetab:<id>`, the live-report fix: dragging the bar label must move
     * the pane exactly like dragging the box top). NOT yet armed: a release
     * that never left the tolerance must still complete the plain click the
     * chrome tracker would have completed — focus from the header, the dock
     * toggle from the tab.
     */
    public function beginDockDrag(string $paneId, int $pressX, int $pressY): self
    {
        return $this->mutate(
            kind: self::KIND_DOCK,
            side: null,
            sideSet: true,
            grabCol: 0,
            paneId: $paneId,
            pressX: $pressX,
            pressY: $pressY,
            armed: false,
            pointerX: $pressX,
            pointerY: $pressY,
        );
    }

    public function isResizing(): bool
    {
        return $this->kind === self::KIND_RESIZE;
    }

    public function isDockDragging(): bool
    {
        return $this->kind === self::KIND_DOCK;
    }

    /**
     * Whether the dock drag has travelled far enough to BE a drag. A resize
     * is armed at the press by definition — grabbing the divider column IS
     * the intent.
     */
    public function isArmed(): bool
    {
        return $this->armed;
    }

    public function dragSide(): ?Side
    {
        return $this->side;
    }

    public function dragPaneId(): ?string
    {
        return $this->paneId;
    }

    /**
     * Feed one motion event to the gesture in flight.
     *
     * For a dock drag this is the arming decision, once-and-forever: the
     * pointer may wander back through the tolerance without un-arming. For
     * a resize it records that at least one preview actually happened — the
     * release consults {@see isPreviewed()} so a bare click on a divider
     * (press and release, pointer still) never costs a disk write.
     */
    public function withMotion(int $x, int $y): self
    {
        if ($this->kind === self::KIND_RESIZE) {
            return $this->previewed ? $this : $this->mutate(previewed: true);
        }

        if ($this->kind !== self::KIND_DOCK) {
            return $this;
        }

        // The pointer is recorded on every motion, armed or not: the
        // renderer paints the drop target under it (see {@see pointer()}).
        $travelled = abs($x - $this->pressX) + abs($y - $this->pressY);

        return $this->mutate(
            armed: $this->armed || $travelled > self::DRAG_ARM_TOLERANCE_CELLS,
            pointerX: $x,
            pointerY: $y,
        );
    }

    /**
     * Where the pointer of a dock drag last was, 1-based terminal cells —
     * the press until the first motion arrives. The renderer resolves it
     * through {@see dockDropSide()} each frame to paint the drop target the
     * release would land on, so what is highlighted and what the release
     * does can never disagree.
     *
     * @return array{0: int, 1: int} x, y
     */
    public function pointer(): array
    {
        return [$this->pointerX, $this->pointerY];
    }

    /**
     * Whether any motion preview actually mutated the model during this
     * resize — see {@see withMotion()}.
     */
    public function isPreviewed(): bool
    {
        return $this->previewed;
    }

    /**
     * Which band width the pointer asks for, in content columns, clamped
     * into the frame the dock itself will enforce.
     *
     * Delta, not absolute: the grabbed divider is PAINTED (inside the side
     * block's box decoration), while the width lives in resolve space —
     * the two coordinate systems differ by a per-side constant nobody
     * outside the renderer should have to know. Translating the pointer's
     * travel onto the current width is therefore correct wherever the grab
     * happened: dragging right by n cells grows a left band by n, dragging
     * left by n grows a right band by n. `$centerRoomPx` is how far the
     * centre may still shrink (`centre width - centreMinCols`) and is what
     * keeps the centre honest: the side can never grow past the centre's
     * floor. When the frame is too tight for both floors, `sideMinCols`
     * wins locally and the dock's own degradation ladder decides at resolve
     * time — the drag states the request, the geometry keeps the guarantee.
     *
     * @throws \LogicException when asked of an idle or dock-dragging state
     *                         (the caller's dispatch bug, never user input)
     */
    public function resizeColumns(
        int $releaseX,
        int $currentWidthPx,
        int $sideMinCols,
        int $centerRoomPx,
    ): int {
        if ($this->kind !== self::KIND_RESIZE || $this->side === null) {
            throw new \LogicException('resizeColumns() asked outside a resize gesture.');
        }

        // Delta, not absolute: the grabbed divider is painted inside the
        // side block's box decoration, so its column and the side's
        // resolve-space width live in coordinate systems that differ by a
        // constant. Translating the GRAB by the pointer's travel is the one
        // formula correct in both.
        $travel = $this->side === Side::Left
            ? $releaseX - $this->grabCol
            : $this->grabCol - $releaseX;

        $raw = $currentWidthPx + $travel;

        $max = max($sideMinCols, $currentWidthPx + $centerRoomPx);

        return max($sideMinCols, min($raw, $max));
    }

    /**
     * Where a released dock drag lands: Left band, Right band, or null for
     * "the middle of the centre — cancel". `$centerFromCol`/`$centerToCol`
     * are the centre column's 0-based inclusive frame span as last painted.
     *
     * The centre's outer thirds are drop targets for the side they face. An
     * EMPTY side paints no band, so the centre runs to the frame edge and a
     * rule of "left of / right of the centre" alone had no cell at all that
     * could dock into it — dragging a pane to the empty right side was
     * unreachable in the default layout. The middle third still cancels.
     */
    public function dockDropSide(int $releaseX, int $centerFromCol, int $centerToCol): ?Side
    {
        $col = $releaseX - 1;
        $edge = self::dropEdgeCols($centerFromCol, $centerToCol);

        if ($col < $centerFromCol + $edge) {
            return Side::Left;
        }

        return $col > $centerToCol - $edge ? Side::Right : null;
    }

    /**
     * How many of the centre's outer columns, on each side, belong to the
     * facing side's drop target — the one rule {@see dockDropSide()} and
     * the renderer's drop-target outline share.
     */
    public static function dropEdgeCols(int $centerFromCol, int $centerToCol): int
    {
        return intdiv(max(0, $centerToCol - $centerFromCol + 1), 3);
    }

    /**
     * The slot index a release asks for on the drop side: how many of that
     * side's currently-painted docked slots START above the release row.
     * `$slotTopsAbs` is those 0-based frame rows in slot order; a release
     * above every slot inserts at 0, below every one appends.
     *
     * @param list<int> $slotTopsAbs
     */
    public static function insertIndex(int $releaseY, array $slotTopsAbs): int
    {
        $row = $releaseY - 1;
        $index = 0;

        foreach ($slotTopsAbs as $top) {
            if ($top < $row) {
                $index++;
            }
        }

        return $index;
    }

    /**
     * @return self
     */
    private function mutate(
        ?string $kind = null,
        ?Side $side = null,
        bool $sideSet = false,
        ?int $grabCol = null,
        ?string $paneId = null,
        bool $paneIdSet = false,
        ?int $pressX = null,
        ?int $pressY = null,
        ?bool $armed = null,
        ?bool $previewed = null,
        ?int $pointerX = null,
        ?int $pointerY = null,
    ): self {
        return new self(
            kind: $kind ?? $this->kind,
            side: $sideSet ? $side : ($side ?? $this->side),
            grabCol: $grabCol ?? $this->grabCol,
            paneId: $paneIdSet ? $paneId : ($paneId ?? $this->paneId),
            pressX: $pressX ?? $this->pressX,
            pressY: $pressY ?? $this->pressY,
            armed: $armed ?? $this->armed,
            previewed: $previewed ?? $this->previewed,
            pointerX: $pointerX ?? $this->pointerX,
            pointerY: $pointerY ?? $this->pointerY,
        );
    }
}
