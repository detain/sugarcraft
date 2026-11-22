<?php

declare(strict_types=1);

namespace SugarCraft\Panels;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;

/**
 * A run of panes split along one axis with draggable dividers.
 *
 * A horizontal splitter lays its panes side by side with vertical dividers
 * (dragged by column); a vertical splitter stacks them with horizontal
 * dividers (dragged by row). The splitter owns each pane's size along its
 * axis and nothing else: {@see node()} builds the layout tree SugarPanels
 * paints, so the dividers are the ordinary spacing-0 separators (or the
 * panes' own frame edges), and divider `i` sits on the last column (row) of
 * pane `i`.
 *
 * Drag is TEA-driven: {@see update()} takes the host's mouse messages. A left
 * press on a divider grabs it, motion moves it ({@see withDividerDrag()}),
 * and the release lets go. Each move trades cells between the two panes
 * beside the divider only, and neither may shrink below
 * {@see withMinPaneSize()}.
 *
 * Positions are SCREEN cells: {@see withBounds()} says where the splitter is
 * painted, so a host routes raw mouse coordinates without translating them.
 *
 * Original sugar-panels work (no upstream counterpart in bubbleboxer).
 */
final class Splitter
{
    /**
     * @param list<Node> $panes
     * @param list<int>  $sizes cells per pane along the axis ([] until bounds are known)
     * @param list<int>  $pending sizes asked for by withSizes() before bounds
     *                            were known, fitted when withBounds() arrives
     */
    private function __construct(
        public readonly string $axis,
        public readonly array $panes,
        public readonly array $sizes = [],
        public readonly int $x = 0,
        public readonly int $y = 0,
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly int $minPaneSize = 1,
        public readonly ?int $dragging = null,
        private readonly array $pending = [],
    ) {
    }

    /** Panes side by side; dividers are vertical and drag by column. */
    public static function horizontal(Node ...$panes): self
    {
        return new self(Node::HORIZONTAL, \array_values($panes));
    }

    /** Panes stacked top to bottom; dividers are horizontal and drag by row. */
    public static function vertical(Node ...$panes): self
    {
        return new self(Node::VERTICAL, \array_values($panes));
    }

    /**
     * Replace the panes. Sizes survive when the pane count is unchanged;
     * otherwise the span is shared out again equally.
     */
    public function withPanes(Node ...$panes): self
    {
        $panes = \array_values($panes);
        $same = \count($panes) === \count($this->panes);
        $sizes = $same ? $this->sizes : [];

        return $this->mutate(
            panes: $panes,
            sizes: self::fit($sizes, \count($panes), $this->span(), $this->minPaneSize),
            dragging: null,
            draggingSet: true,
            pending: $same ? $this->pending : [],
        );
    }

    /**
     * Where the splitter is painted, in screen cells. Existing sizes are
     * rescaled proportionally to the new span (equal shares when none were
     * set), so a terminal resize keeps the split's proportions.
     */
    public function withBounds(int $x, int $y, int $width, int $height): self
    {
        $width  = \max(0, $width);
        $height = \max(0, $height);
        $span   = $this->axis === Node::HORIZONTAL ? $width : $height;

        return $this->mutate(
            x: $x,
            y: $y,
            width: $width,
            height: $height,
            sizes: self::fit($this->sizes !== [] ? $this->sizes : $this->pending, \count($this->panes), $span, $this->minPaneSize),
            // A zero span parks the current sizes so a later resize restores
            // the proportions instead of falling back to equal shares.
            pending: $span > 0 ? [] : ($this->sizes !== [] ? $this->sizes : $this->pending),
        );
    }

    /**
     * Set the pane sizes explicitly (cells along the axis). The list is
     * fitted to the span: a short or long total is rescaled, and no pane goes
     * below the minimum while the span can hold every pane at the minimum.
     * Called before {@see withBounds()} (no span yet), the list is kept and
     * fitted once bounds arrive, so the call order does not matter.
     *
     * @throws \InvalidArgumentException when a size is negative: the minimum
     *         floor cannot repair it on a span too small to hold every pane,
     *         so it would put dividers on off-screen cells.
     */
    public function withSizes(int ...$sizes): self
    {
        $sizes = \array_values($sizes);
        foreach ($sizes as $i => $size) {
            if ($size < 0) {
                throw new \InvalidArgumentException("Pane sizes must not be negative, got {$size} at index {$i}");
            }
        }
        if ($this->span() <= 0) {
            return $this->mutate(pending: $sizes);
        }

        return $this->mutate(sizes: self::fit($sizes, \count($this->panes), $this->span(), $this->minPaneSize), pending: []);
    }

    /** Smallest size a drag may leave a pane with (clamped to at least 1). */
    public function withMinPaneSize(int $cells): self
    {
        $min = \max(1, $cells);

        return $this->mutate(minPaneSize: $min, sizes: self::fit($this->sizes, \count($this->panes), $this->span(), $min));
    }

    /**
     * Move a divider so it lands on screen column (row, for a vertical
     * splitter) $position.
     *
     * The divider moved is $divider when given, else the one being dragged,
     * else the one nearest $position. Only the two panes beside it change
     * size, and the move clamps so both keep at least the minimum. Without
     * bounds (no span yet) or with a single pane there is nothing to move.
     */
    public function withDividerDrag(int $position, ?int $divider = null): self
    {
        $count = \count($this->sizes);
        if ($count < 2) {
            return $this;
        }
        $d = $divider ?? $this->dragging ?? $this->nearestDivider($position);
        if ($d < 0 || $d >= $count - 1) {
            return $this;
        }

        $start = $this->origin();
        for ($i = 0; $i < $d; $i++) {
            $start += $this->sizes[$i];
        }
        $pair = $this->sizes[$d] + $this->sizes[$d + 1];
        $min  = \min($this->minPaneSize, \intdiv($pair, 2));
        $lead = \max($min, \min($pair - $min, $position - $start + 1));

        $sizes = $this->sizes;
        $sizes[$d] = $lead;
        $sizes[$d + 1] = $pair - $lead;

        return $this->mutate(sizes: $sizes);
    }

    /**
     * Which divider sits at screen cell ($col, $row), or null. Divider `i` is
     * the last column (row) of pane `i`, across the splitter's full cross
     * extent.
     */
    public function dividerAt(int $col, int $row): ?int
    {
        if ($col < $this->x || $col >= $this->x + $this->width || $row < $this->y || $row >= $this->y + $this->height) {
            return null;
        }
        $along = $this->axis === Node::HORIZONTAL ? $col : $row;
        foreach ($this->dividerPositions() as $i => $position) {
            if ($position === $along) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Screen column (row) of each divider, in order.
     *
     * @return list<int>
     */
    public function dividerPositions(): array
    {
        $out = [];
        $at  = $this->origin();
        $last = \count($this->sizes) - 1;
        foreach ($this->sizes as $i => $size) {
            $at += $size;
            if ($i < $last) {
                $out[] = $at - 1;
            }
        }

        return $out;
    }

    public function isDragging(): bool
    {
        return $this->dragging !== null;
    }

    /**
     * Drive divider drag from mouse messages.
     *
     * A left press on a divider grabs it; left-button motion while grabbed
     * moves it; a release (anywhere) moves it one last time and lets go. A
     * left press off every divider drops any grab left by a lost release, and
     * button-less hover motion never moves a divider. A press off any divider
     * with nothing grabbed, and every other message, leaves the splitter
     * unchanged so the host can route the event onward. No command is ever
     * issued: the new sizes are on the returned splitter.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof MouseMsg) {
            return [$this, null];
        }
        $along = $this->axis === Node::HORIZONTAL ? $msg->x : $msg->y;

        if ($msg->action === MouseAction::Press && $msg->button === MouseButton::Left) {
            $divider = $this->dividerAt($msg->x, $msg->y);

            if ($divider === null && $this->dragging === null) {
                return [$this, null];
            }

            return [$this->mutate(dragging: $divider, draggingSet: true), null];
        }

        if ($this->dragging === null) {
            return [$this, null];
        }

        if ($msg->action === MouseAction::Motion) {
            if ($msg->button !== MouseButton::Left) {
                return [$this, null];
            }

            return [$this->withDividerDrag($along), null];
        }

        if ($msg->action === MouseAction::Release) {
            return [$this->withDividerDrag($along)->mutate(dragging: null, draggingSet: true), null];
        }

        return [$this, null];
    }

    /**
     * The layout tree for the current sizes: a frameless, spacing-0 container
     * whose panes are weighted by their sizes, so SugarPanels gives each pane
     * exactly its cells when the tree is rendered at the splitter's span. A
     * pane's own flex weight is cleared and its min* along the axis becomes
     * its size (the splitter owns that axis). Before bounds are known the
     * panes are returned unweighted.
     */
    public function node(): Node
    {
        $panes = $this->panes;
        if ($this->sizes !== []) {
            foreach ($panes as $i => $pane) {
                $pane = $pane->withFlex(0);
                $panes[$i] = $this->axis === Node::HORIZONTAL
                    ? $pane->withMinWidth($this->sizes[$i])
                    : $pane->withMinHeight($this->sizes[$i]);
            }
        }
        $node = $this->axis === Node::HORIZONTAL ? Node::horizontal(...$panes) : Node::vertical(...$panes);

        return $node->withBorder(false)->withSpacing(0);
    }

    private function span(): int
    {
        return $this->axis === Node::HORIZONTAL ? $this->width : $this->height;
    }

    private function origin(): int
    {
        return $this->axis === Node::HORIZONTAL ? $this->x : $this->y;
    }

    private function nearestDivider(int $position): int
    {
        $best = 0;
        $bestDistance = \PHP_INT_MAX;
        foreach ($this->dividerPositions() as $i => $at) {
            $distance = \abs($at - $position);
            if ($distance < $bestDistance) {
                $best = $i;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Fit a size list to $count panes summing to $span: proportional rescale
     * of the given sizes (equal shares when there are none or they sum to 0),
     * the rounding remainder on the last pane, each pane floored at $min while
     * the span can hold every pane at $min (cells are taken from the largest
     * panes to pay for the floor).
     *
     * @param list<int> $sizes
     * @return list<int>
     */
    private static function fit(array $sizes, int $count, int $span, int $min): array
    {
        if ($count === 0 || $span <= 0) {
            return [];
        }
        if (\count($sizes) !== $count || \array_sum($sizes) <= 0) {
            $sizes = \array_fill(0, $count, 1);
        }
        $total = \array_sum($sizes);

        $out = [];
        $used = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            $share = (int) \floor($sizes[$i] * $span / $total);
            $out[] = $share;
            $used += $share;
        }
        $out[] = $span - $used;

        if ($min * $count <= $span) {
            foreach ($out as $i => $size) {
                while ($out[$i] < $min) {
                    $donor = \array_keys($out, \max($out))[0];
                    $out[$donor]--;
                    $out[$i]++;
                }
            }
        }

        return $out;
    }

    /** @param list<Node>|null $panes @param list<int>|null $sizes @param list<int>|null $pending */
    private function mutate(
        ?array $panes = null,
        ?array $sizes = null,
        ?int $x = null,
        ?int $y = null,
        ?int $width = null,
        ?int $height = null,
        ?int $minPaneSize = null,
        ?int $dragging = null,
        bool $draggingSet = false,
        ?array $pending = null,
    ): self {
        return new self(
            $this->axis,
            $panes ?? $this->panes,
            $sizes ?? $this->sizes,
            $x ?? $this->x,
            $y ?? $this->y,
            $width ?? $this->width,
            $height ?? $this->height,
            $minPaneSize ?? $this->minPaneSize,
            $draggingSet ? $dragging : $this->dragging,
            $pending ?? $this->pending,
        );
    }
}
