<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Core\Util\Width;

/**
 * A rendered frame: the exact bytes {@see Toast::view()} painted, plus the
 * geometry that produced them.
 *
 * Two findings converge here. 001: hit-testing used to RECOMPUTE the layout
 * from the caller's arguments, so a host that painted over a 120x40 frame and
 * then asked about the default 80x24 one got rectangles describing a screen
 * nobody was looking at — silently. A frame object makes "the frame you
 * rendered" a value that can be carried, matched, and hit-tested honestly.
 * 026: hosts (candy-code's renderer, sugar-dashboard's adapter) re-parsed the
 * composed ANSI with explode/trim to find the box; {@see boxRows()} and the
 * x/y origin hand them the box as data instead.
 *
 * Immutable value object — built by {@see Toast::frame()} only, never by
 * hand, so its bytes and rects can never disagree with a real render.
 */
final class ToastFrame
{
    /**
     * @param list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int,fold:int}> $rects
     */
    public function __construct(
        /** Column of the top-most (newest, paint-order last) box's top-left cell. */
        public readonly int $x,
        /** Row of the top-most box's top-left cell. */
        public readonly int $y,
        /** The composited frame — byte-identical to what view() returned. */
        public readonly string $rows,
        /** Hit-test rectangles of every painted action button, queue order. */
        public readonly array $rects = [],
        /**
         * Bounding rectangle of EVERY painted box (a stack spans more than
         * the newest box). null on a hand-built frame, which cannot be
         * re-placed — the geometry is only trustworthy from Toast::frame().
         *
         * @var array{x:int,y:int,width:int,height:int}|null
         */
        public readonly ?array $bounds = null,
    ) {
    }

    /**
     * The composed frame as a list of lines, without the trailing-newline
     * artefact. Over an empty background these are exactly the box rows:
     * dashboard-style consumers stop splitting and trimming rendered ANSI.
     *
     * @return list<string>
     */
    public function boxRows(): array
    {
        $body = \str_ends_with($this->rows, "\n") ? \substr($this->rows, 0, -1) : $this->rows;
        return \explode("\n", $body);
    }

    /**
     * Absolute frame cells of every painted action button — the same
     * structure {@see actionAt()} walks.
     *
     * @return list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int,fold:int}>
     */
    public function actionRects(): array
    {
        return $this->rects;
    }

    /**
     * Which Action (if any) covers frame position ($col, $row)? Where boxes
     * share rows, the top-most (newest) one wins — walking in reverse paint
     * order, because a later box is composited OVER an earlier one and a
     * button hidden beneath it must never fire.
     */
    public function actionAt(int $col, int $row): ?Action
    {
        foreach (\array_reverse($this->rects) as $rect) {
            if (($rect['fold'] ?? 0) === 0 && $rect['row'] === $row && $col >= $rect['col'] && $col < $rect['col'] + $rect['width']) {
                return $rect['action'];
            }
        }
        return null;
    }

    /**
     * Re-anchor the painted boxes at a host-chosen cell: the inset door the
     * nine anchors cannot express (candy-code wants a top-right box inset
     * from the screen edge, and hand-pasted rows with a re-derived x).
     *
     * Returns a frame whose rows are ONLY the bounding rectangle of the
     * boxes — background stripped, cell-exact cut through the ANSI — with
     * x/y/bounds and every hit rect translated so ($col, $row) is where the
     * rectangle's top-left cell now lives. Pasting boxRows() at ($col,
     * $row) reproduces the composited box; placeAt(bounds x, bounds y)
     * reproduces it in place.
     *
     * @throws \LogicException on a frame not produced by Toast::frame()
     */
    public function placeAt(int $col, int $row): self
    {
        if ($this->bounds === null) {
            throw new \LogicException('placeAt() needs a frame produced by Toast::frame(): its box bounds are unknown');
        }
        ['x' => $bx, 'y' => $by, 'width' => $bw, 'height' => $bh] = $this->bounds;
        $lines = $this->boxRows();
        $cropped = [];
        for ($r = $by; $r < $by + $bh; $r++) {
            $cropped[] = Width::takeAnsi(Width::dropAnsi($lines[$r] ?? '', $bx), $bw);
        }
        $dx = $col - $bx;
        $dy = $row - $by;
        $rects = [];
        foreach ($this->rects as $rect) {
            $rect['col'] += $dx;
            $rect['row'] += $dy;
            $rects[] = $rect;
        }
        return new self(
            $this->x + $dx,
            $this->y + $dy,
            \implode("\n", $cropped) . "\n",
            $rects,
            ['x' => $col, 'y' => $row, 'width' => $bw, 'height' => $bh],
        );
    }

    /**
     * The activeAlerts() index whose `[+N more]` fold row covers frame
     * position ($col, $row), or null. Fold rects ride actionRects() flagged
     * by a non-zero 'fold' (the hidden-button count) but never answer
     * {@see actionAt()} — hosts invoking the returned Action's callback must
     * not mistake the fold for a button; this is its own door.
     */
    public function foldAt(int $col, int $row): ?int
    {
        foreach (\array_reverse($this->rects) as $rect) {
            if (($rect['fold'] ?? 0) > 0 && $rect['row'] === $row && $col >= $rect['col'] && $col < $rect['col'] + $rect['width']) {
                return $rect['alertIndex'];
            }
        }
        return null;
    }
}
