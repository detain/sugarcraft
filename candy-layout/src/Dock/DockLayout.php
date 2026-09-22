<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Dock;

use SugarCraft\Layout\Region;

/**
 * Immutable dock/pane-layout description: a center surface flanked by two
 * optional vertical stacks of docked panes, resolved into concrete
 * {@see DockGeometry} for any {@see Region} frame.
 *
 * Original SugarCraft design (no upstream mirror). Column and stack shares are
 * rationals (numerator/denominator) so they survive arbitrary resizes without
 * floating-point drift — the same discipline sugar-crush's `Tui\SplitLayout`
 * applies to its two-pane split (prose reference only; this package stays a
 * dependency-free leaf).
 *
 * Policy notes (all deterministic, pinned by tests):
 *  - `new()` starts at thirds (1/3 + 1/3) — the design default; the 1/2 pair
 *    ceiling described on {@see withColumnShare()} is a MUTATOR policy, not a
 *    state invariant, so defaults and restored manifests may exceed it and
 *    {@see resolve()} handles tight centers through its degradation ladder.
 *  - resolve() never throws on small/degenerate frames; it degrades.
 */
final class DockLayout
{
    /**
     * Gap rows between consecutive slots in one stack.
     */
    private const STACK_GAP_ROWS = 1;

    /**
     * Default column share both sides are created with (thirds).
     */
    public const DEFAULT_SHARE_NUM = 1;
    public const DEFAULT_SHARE_DENOM = 3;

    /**
     * @param list<DockSlot> $leftSlots
     * @param list<DockSlot> $rightSlots
     */
    private function __construct(
        public readonly string $centerPaneId,
        public readonly array $leftSlots,
        public readonly array $rightSlots,
        public readonly int $leftShareNum,
        public readonly int $leftShareDenom,
        public readonly int $rightShareNum,
        public readonly int $rightShareDenom,
        public readonly int $centerMinCols,
        public readonly int $sideMinCols,
        public readonly int $dividerCols,
    ) {
        if ($centerPaneId === '') {
            throw new \InvalidArgumentException('DockLayout centerPaneId must be a non-empty string');
        }
        foreach ([...$leftSlots, ...$rightSlots] as $slot) {
            if ($slot instanceof DockSlot === false) {
                throw new \InvalidArgumentException('DockLayout slots must be DockSlot instances');
            }
        }
        if ($leftShareNum < 1 || $leftShareDenom < 1 || $rightShareNum < 1 || $rightShareDenom < 1) {
            throw new \InvalidArgumentException(
                'DockLayout column shares must carry numerator and denominator >= 1; got '
                . "left={$leftShareNum}/{$leftShareDenom}, right={$rightShareNum}/{$rightShareDenom}"
            );
        }
        if ($centerMinCols < 1 || $sideMinCols < 1) {
            throw new \InvalidArgumentException(
                "DockLayout minimums must be >= 1; got centerMinCols={$centerMinCols}, sideMinCols={$sideMinCols}"
            );
        }
        if ($dividerCols < 0) {
            throw new \InvalidArgumentException("DockLayout dividerCols must be >= 0; got {$dividerCols}");
        }
    }

    /**
     * Empty dock: no side slots, both column shares at the 1/3 design default,
     * center minimum 24 cols, side minimum 20 cols, 1-col dividers.
     */
    public static function new(string $centerPaneId = 'chat'): self
    {
        return new self(
            centerPaneId: $centerPaneId,
            leftSlots: [],
            rightSlots: [],
            leftShareNum: self::DEFAULT_SHARE_NUM,
            leftShareDenom: self::DEFAULT_SHARE_DENOM,
            rightShareNum: self::DEFAULT_SHARE_NUM,
            rightShareDenom: self::DEFAULT_SHARE_DENOM,
            centerMinCols: 24,
            sideMinCols: 20,
            dividerCols: 1,
        );
    }

    /**
     * Dock a pane onto a side. Null index appends; index is an insertion
     * position in `0..count`. Duplicate pane ids across BOTH sides are refused
     * — a pane has exactly one home.
     */
    public function withSlotAdded(Side $side, string $paneId, ?int $index = null): self
    {
        $slot = DockSlot::new($paneId);
        if ($this->findSlot($paneId) !== null) {
            throw new \InvalidArgumentException(
                "Pane '{$paneId}' is already docked; a pane has exactly one home"
            );
        }

        [$key, $slots] = $this->sideAccessors($side);
        $position = $index ?? count($slots);
        $this->guardInsertion($position, $slots, $side);
        array_splice($slots, $position, 0, [$slot]);

        return $this->mutate([$key => $slots]);
    }

    /**
     * Undock a pane from whichever side holds it. Unknown pane ids are a no-op
     * returning the identical instance.
     */
    public function withSlotRemoved(string $paneId): self
    {
        $found = $this->findSlot($paneId);
        if ($found === null) {
            return $this;
        }
        [$key, $slots] = $this->sideAccessors($found['side']);
        $kept = array_values(array_filter(
            $slots,
            static fn(DockSlot $slot): bool => $slot->paneId !== $paneId,
        ));

        return $this->mutate([$key => $kept]);
    }

    /**
     * Move a pane across sides or reorder it within its side. Null index moves
     * to the end of the destination stack. Unknown pane ids throw — a move of
     * a pane that was never docked is a caller bug, not a no-op.
     */
    public function withSlotMovedTo(string $paneId, Side $side, ?int $index = null): self
    {
        $found = $this->findSlot($paneId);
        if ($found === null) {
            throw new \InvalidArgumentException("Cannot move undocked pane '{$paneId}'");
        }

        [$fromKey, $fromSlots] = $this->sideAccessors($found['side']);
        [$toKey, $toSlots] = $this->sideAccessors($side);

        if ($fromKey === $toKey) {
            $slot = $fromSlots[$found['index']];
            array_splice($fromSlots, $found['index'], 1);
            $position = $index ?? count($fromSlots);
            $this->guardInsertion($position, $fromSlots, $side);
            array_splice($fromSlots, $position, 0, [$slot]);

            return $this->mutate([$toKey => array_values($fromSlots)]);
        }

        $slot = $found['slot'];
        $position = $index ?? count($toSlots);
        $this->guardInsertion($position, $toSlots, $side);
        array_splice($toSlots, $position, 0, [$slot]);

        return $this->mutate([
            $fromKey => array_values(array_filter(
                $fromSlots,
                static fn(DockSlot $s): bool => $s->paneId !== $paneId,
            )),
            $toKey => array_values($toSlots),
        ]);
    }

    /**
     * Set a side's column share. Invalid rationals throw; the pair rule
     * leftShare + rightShare <= 1/2 is enforced by CLAMPING the requested share
     * (never throwing) — chat is the primary surface and must keep at least
     * half the usable columns once the host starts tightening shares. The
     * creation default (thirds) predates any tightening and is documented on
     * the class.
     */
    public function withColumnShare(Side $side, int $num, int $denom): self
    {
        if ($num < 1 || $denom < 1) {
            throw new \InvalidArgumentException(
                "Column share must carry numerator and denominator >= 1; got {$num}/{$denom}"
            );
        }

        $other = $this->columnShare($side->other());
        // cap = 1/2 - other = (otherDenom - 2*otherNum) / (2*otherDenom)
        $capNum = $other['denom'] - 2 * $other['num'];
        $capDenom = 2 * $other['denom'];
        if ($capNum < 1) {
            $capNum = 1; // floor to the smallest representable share
        }

        if ($num * $capDenom <= $capNum * $denom) {
            [$shareNum, $shareDenom] = [$num, $denom];
        } else {
            [$shareNum, $shareDenom] = [$capNum, $capDenom];
        }

        if ($side === Side::Left) {
            return $this->mutate([
                'leftShareNum' => $shareNum,
                'leftShareDenom' => $shareDenom,
            ]);
        }

        return $this->mutate([
            'rightShareNum' => $shareNum,
            'rightShareDenom' => $shareDenom,
        ]);
    }

    /**
     * Replace the rational stack weight at `$index` (a current position in the
     * side's stack, `0..count-1`).
     */
    public function withStackWeight(Side $side, int $index, int $num, int $denom): self
    {
        [$key, $slots] = $this->sideAccessors($side);
        if ($index < 0 || $index >= count($slots)) {
            throw new \InvalidArgumentException(
                'Stack weight index ' . $index . ' out of bounds 0..' . (count($slots) - 1)
                . ' for ' . $side->name . ' side'
            );
        }
        $slots[$index] = $slots[$index]->withWeight($num, $denom);

        return $this->mutate([$key => $slots]);
    }

    /**
     * Set the column minimums; both must be >= 1.
     */
    public function withMinimums(int $centerMinCols, int $sideMinCols): self
    {
        if ($centerMinCols < 1 || $sideMinCols < 1) {
            throw new \InvalidArgumentException(
                "Minimums must be >= 1; got centerMinCols={$centerMinCols}, sideMinCols={$sideMinCols}"
            );
        }

        return $this->mutate(['centerMinCols' => $centerMinCols, 'sideMinCols' => $sideMinCols]);
    }

    /**
     * @return list<DockSlot>
     */
    public function slots(Side $side): array
    {
        return $side === Side::Left ? $this->leftSlots : $this->rightSlots;
    }

    /**
     * @return array{num:int,denom:int}
     */
    public function columnShare(Side $side): array
    {
        return $side === Side::Left
            ? ['num' => $this->leftShareNum, 'denom' => $this->leftShareDenom]
            : ['num' => $this->rightShareNum, 'denom' => $this->rightShareDenom];
    }

    /**
     * Which side (if any) holds a pane, with its slot and stack index.
     *
     * @return ?array{side:Side,index:int,slot:DockSlot}
     */
    private function findSlot(string $paneId): ?array
    {
        foreach ([[Side::Left, $this->leftSlots], [Side::Right, $this->rightSlots]] as [$side, $slots]) {
            foreach ($slots as $index => $slot) {
                if ($slot->paneId === $paneId) {
                    return ['side' => $side, 'index' => $index, 'slot' => $slot];
                }
            }
        }
        return null;
    }

    /**
     * @return array{0:string,1:list<DockSlot>} property key + slot list
     */
    private function sideAccessors(Side $side): array
    {
        return $side === Side::Left ? ['leftSlots', $this->leftSlots] : ['rightSlots', $this->rightSlots];
    }

    /**
     * @param list<DockSlot> $slots
     */
    private function guardInsertion(int $position, array $slots, Side $side): void
    {
        if ($position < 0 || $position > count($slots)) {
            throw new \InvalidArgumentException(
                'Slot index ' . $position . ' out of bounds 0..' . count($slots)
                . ' for ' . $side->name . ' side'
            );
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    // ── Resolution ──

    /**
     * Lay this dock out inside `$frame`.
     *
     * Deterministic rules (pinned by DockLayoutTest):
     *  - Degenerate frames (width or height <= 0) resolve to an EMPTY geometry;
     *    resolve never throws.
     *  - Each active side gets floor((width - dividersTotal) * share) columns,
     *    then min-protection raises a side to `sideMinCols` (Left first, then
     *    Right) ONLY while the center keeps `centerMinCols`.
     *  - If the center would fall below `centerMinCols`, degrade: drop the side
     *    with FEWER slots (ties drop Left first), retry; drop the remaining
     *    side; finally center-only across the whole frame.
     *  - Stack rows = frame height minus one gap row between consecutive slots;
     *    heights are the floor of each rational weight share, residual to the
     *    LAST slot; absurd frames may yield zero-height slots — allowed.
     */
    public function resolve(Region $frame): DockGeometry
    {
        if ($frame->width <= 0 || $frame->height <= 0) {
            return DockGeometry::new();
        }

        $active = [];
        if ($this->leftSlots !== []) {
            $active[] = Side::Left;
        }
        if ($this->rightSlots !== []) {
            $active[] = Side::Right;
        }

        $plan = null;
        while ($active !== []) {
            $plan = $this->planColumns($frame->width, $active);
            if ($plan !== null) {
                break;
            }
            $active = $this->dropOneSide($active);
        }

        if ($plan === null) {
            // Center-only across the whole frame (final rung of the ladder,
            // also the shape when both sides were empty from the start).
            return DockGeometry::new([
                $this->centerPaneId => new Region($frame->x, $frame->y, $frame->width, $frame->height),
            ], []);
        }

        $regions = [];
        $dividers = [];
        $cursor = $frame->x;

        $leftCols = $plan['cols'][self::key(Side::Left)] ?? 0;
        $rightCols = $plan['cols'][self::key(Side::Right)] ?? 0;
        $centerCols = $plan['center'];

        if (array_key_exists(self::key(Side::Left), $plan['cols'])) {
            $this->appendStack($regions, Side::Left, $cursor, $leftCols, $frame);
            $dividers[] = ['x' => $cursor + $leftCols, 'side' => Side::Left];
            $cursor += $leftCols + $this->dividerCols;
        }

        $regions[$this->centerPaneId] = new Region($cursor, $frame->y, $centerCols, $frame->height);
        $cursor += $centerCols;

        if (array_key_exists(self::key(Side::Right), $plan['cols'])) {
            $dividers[] = ['x' => $cursor, 'side' => Side::Right];
            $cursor += $this->dividerCols;
            $this->appendStack($regions, Side::Right, $cursor, $rightCols, $frame);
        }

        return DockGeometry::new($regions, $dividers);
    }

    /**
     * Column budget for a candidate set of active sides.
     *
     * @param list<Side> $active
     * @return ?array{cols:array<string,int>,center:int} null when infeasible
     */
    private function planColumns(int $width, array $active): ?array
    {
        $dividersTotal = $this->dividerCols * count($active);
        $usable = $width - $dividersTotal;
        if ($usable < 0) {
            return null;
        }

        $cols = [];
        foreach ($active as $side) {
            $share = $this->columnShare($side);
            $cols[self::key($side)] = (int) floor($usable * $share['num'] / $share['denom']);
        }

        // Min-protection pass, Left first then Right: a side short of
        // sideMinCols is raised only while the center still keeps its minimum.
        foreach ($active as $side) {
            $k = self::key($side);
            if ($cols[$k] >= $this->sideMinCols) {
                continue;
            }
            $raised = $cols;
            $raised[$k] = $this->sideMinCols;
            if ($usable - array_sum($raised) >= $this->centerMinCols) {
                $cols = $raised;
            }
        }

        $center = $usable - array_sum($cols);
        if ($center < $this->centerMinCols) {
            return null;
        }

        return ['cols' => $cols, 'center' => $center];
    }

    /**
     * Degradation step: drop the side with fewer slots; ties drop Left first.
     *
     * @param list<Side> $active
     * @return list<Side>
     */
    private function dropOneSide(array $active): array
    {
        if (count($active) === 1) {
            return [];
        }

        return count($this->leftSlots) <= count($this->rightSlots) ? [Side::Right] : [Side::Left];
    }

    /**
     * Stack one side's slots vertically at $x with $cols columns, writing
     * their regions into $regions.
     *
     * @param array<string,Region> $regions
     */
    private function appendStack(array &$regions, Side $side, int $x, int $cols, Region $frame): void
    {
        $slots = $this->slots($side);
        $heights = $this->stackHeights($slots, $frame->height);

        $y = $frame->y;
        $last = count($slots) - 1;
        foreach ($slots as $index => $slot) {
            $regions[$slot->paneId] = new Region($x, $y, $cols, $heights[$index]);
            if ($index !== $last) {
                $y += $heights[$index] + self::STACK_GAP_ROWS;
            }
        }
    }

    /**
     * Deterministic proportional split of stack rows: float weight math,
     * floor() per slot, whole residual to the LAST slot. Never negative —
     * absurd frames collapse slots toward zero height instead of throwing.
     *
     * @param list<DockSlot> $slots
     * @return list<int>
     */
    private function stackHeights(array $slots, int $height): array
    {
        $count = count($slots);
        if ($count === 1) {
            return [max(0, $height)];
        }

        $rows = $height - self::STACK_GAP_ROWS * ($count - 1);
        $weights = array_map(
            static fn(DockSlot $slot): float => $slot->weightNum / $slot->weightDenom,
            $slots,
        );
        $totalWeight = array_sum($weights);

        $heights = [];
        $assigned = 0;
        foreach ($slots as $index => $slot) {
            if ($index === $count - 1) {
                continue;
            }
            $share = $rows <= 0 || $totalWeight <= 0.0
                ? 0
                : (int) floor($rows * ($weights[$index] / $totalWeight));
            $heights[$index] = max(0, $share);
            $assigned += $heights[$index];
        }
        $heights[] = max(0, $rows - $assigned);

        return $heights;
    }

    // ── Persistence ──

    /**
     * Exact versioned manifest shape.
     *
     * @return array{version:int,center:string,sides:array{left:list<array{id:string,weight:array{0:int,1:int}}>,right:list<array{id:string,weight:array{0:int,1:int}}>},columnShare:array{left:array{0:int,1:int},right:array{0:int,1:int}},minimums:array{0:int,1:int}}
     */
    public function toArray(): array
    {
        $encodeSlots = static fn(array $slots): array => array_map(
            static fn(DockSlot $slot): array => [
                'id' => $slot->paneId,
                'weight' => [$slot->weightNum, $slot->weightDenom],
            ],
            $slots,
        );

        return [
            'version' => 1,
            'center' => $this->centerPaneId,
            'sides' => [
                'left' => $encodeSlots($this->leftSlots),
                'right' => $encodeSlots($this->rightSlots),
            ],
            'columnShare' => [
                'left' => [$this->leftShareNum, $this->leftShareDenom],
                'right' => [$this->rightShareNum, $this->rightShareDenom],
            ],
            'minimums' => [$this->centerMinCols, $this->sideMinCols],
        ];
    }

    /**
     * Parse a manifest produced by {@see toArray()}. Strict at the boundary —
     * every failure throws InvalidArgumentException naming the offending key
     * path. Hand-edited data that violates basic ranges is REFUSED here
     * (fail-fast), while the 1/2 pair rule stays a withColumnShare() clamping
     * policy: restored manifests keep their persisted shares untouched (the
     * thirds default included), so round-trip identity holds.
     *
     * dividerCols is not part of the shape — it has no public mutator, so
     * every layout carries the default 1 and persistence stays lossless.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (array_key_exists('version', $data) === false || $data['version'] !== 1) {
            throw new \InvalidArgumentException(
                "Dock manifest key 'version' must be int 1; got " . var_export($data['version'] ?? null, true)
            );
        }
        if (isset($data['center']) === false || is_string($data['center']) === false || $data['center'] === '') {
            throw new \InvalidArgumentException("Dock manifest key 'center' must be a non-empty string");
        }
        if (isset($data['sides']) === false || is_array($data['sides']) === false) {
            throw new \InvalidArgumentException("Dock manifest key 'sides' must be an array");
        }

        $seen = [];
        $slotsFor = static function (string $sideKey) use ($data, &$seen): array {
            if (isset($data['sides'][$sideKey]) === false || is_array($data['sides'][$sideKey]) === false) {
                throw new \InvalidArgumentException("Dock manifest key 'sides.{$sideKey}' must be an array");
            }
            $slots = [];
            foreach (array_values($data['sides'][$sideKey]) as $position => $row) {
                $path = "sides.{$sideKey}.{$position}";
                if (is_array($row) === false) {
                    throw new \InvalidArgumentException("Dock manifest key '{$path}' must be an array");
                }
                if (isset($row['id']) === false || is_string($row['id']) === false || $row['id'] === '') {
                    throw new \InvalidArgumentException("Dock manifest key '{$path}.id' must be a non-empty string");
                }
                if (isset($seen[$row['id']])) {
                    throw new \InvalidArgumentException(
                        "Dock manifest key '{$path}.id' duplicates pane '{$row['id']}' across sides"
                    );
                }
                if (DockLayout::isIntPair($row['weight'] ?? null) === false) {
                    throw new \InvalidArgumentException("Dock manifest key '{$path}.weight' must be a [num, denom] pair of ints");
                }
                if ($row['weight'][0] < 1 || $row['weight'][1] < 1) {
                    throw new \InvalidArgumentException(
                        "Dock manifest key '{$path}.weight' must carry values >= 1; got [{$row['weight'][0]}, {$row['weight'][1]}]"
                    );
                }
                $seen[$row['id']] = true;
                $slots[] = DockSlot::new($row['id'], $row['weight'][0], $row['weight'][1]);
            }
            return $slots;
        };

        $leftSlots = $slotsFor('left');
        $rightSlots = $slotsFor('right');

        if (isset($data['columnShare']) === false || is_array($data['columnShare']) === false) {
            throw new \InvalidArgumentException("Dock manifest key 'columnShare' must be an array");
        }
        $shareFor = static function (string $sideKey) use ($data): array {
            if (DockLayout::isIntPair($data['columnShare'][$sideKey] ?? null) === false) {
                throw new \InvalidArgumentException("Dock manifest key 'columnShare.{$sideKey}' must be a [num, denom] pair of ints");
            }
            [$num, $denom] = $data['columnShare'][$sideKey];
            if ($num < 1 || $denom < 1) {
                throw new \InvalidArgumentException(
                    "Dock manifest key 'columnShare.{$sideKey}' must carry values >= 1; got [{$num}, {$denom}]"
                );
            }
            return [$num, $denom];
        };
        [$leftNum, $leftDenom] = $shareFor('left');
        [$rightNum, $rightDenom] = $shareFor('right');

        if (DockLayout::isIntPair($data['minimums'] ?? null) === false) {
            throw new \InvalidArgumentException("Dock manifest key 'minimums' must be a [center, side] pair of ints");
        }
        if ($data['minimums'][0] < 1 || $data['minimums'][1] < 1) {
            throw new \InvalidArgumentException(
                "Dock manifest key 'minimums' must carry values >= 1; got [{$data['minimums'][0]}, {$data['minimums'][1]}]"
            );
        }

        return new self(
            centerPaneId: $data['center'],
            leftSlots: $leftSlots,
            rightSlots: $rightSlots,
            leftShareNum: $leftNum,
            leftShareDenom: $leftDenom,
            rightShareNum: $rightNum,
            rightShareDenom: $rightDenom,
            centerMinCols: $data['minimums'][0],
            sideMinCols: $data['minimums'][1],
            dividerCols: 1,
        );
    }

    /**
     * Stable persistence/plan key for a side.
     */
    private static function key(Side $side): string
    {
        return $side === Side::Left ? 'left' : 'right';
    }

    /**
     * @param mixed $value
     */
    private static function isIntPair(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && count($value) === 2
            && is_int($value[0])
            && is_int($value[1]);
    }
}
