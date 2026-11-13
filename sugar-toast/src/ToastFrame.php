<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

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
     * @param list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int}> $rects
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
     * @return list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int}>
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
            if ($rect['row'] === $row && $col >= $rect['col'] && $col < $rect['col'] + $rect['width']) {
                return $rect['action'];
            }
        }
        return null;
    }
}
