<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * A Pane wraps a Model and knows its bounds within the terminal grid.
 *
 * Each Pane renders its content positioned at its origin (x, y). The
 * content is offset via ANSI cursor positioning so multiple Panes can
 * be composed side-by-side without their outputs overwriting each other.
 *
 * Bounds are a hard partition (sugar-core-027): {@see view()} clips the
 * model's content to `height` rows and every row to `width` cells
 * (visible, ANSI-aware — {@see Width::truncate()}) before positioning.
 * Overflow is DISCARDED, silently by house decision: a pane may never
 * bleed into the cells of a sibling pane or below the grid, and a log
 * line per discarded row was measured as frame-rate noise for content
 * that is oversized by exactly one row. Models that need scroll or
 * pagination own that inside their own bounds.
 *
 * Mirrors the role of a "Pane" in Bubble Tea's view system — a region
 * of the screen occupied by a sub-model.
 */
final readonly class Pane
{
    public function __construct(
        public Model $model,
        public Rect $bounds,
    ) {
    }

    /**
     * Route a message to the underlying model, returning an updated Pane.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        [$model, $cmd] = $this->model->update($msg);
        if ($model === $this->model && $cmd === null) {
            // Identity fast path (sugar-core-036 PERF-13): an unchanged
            // model must not clone the pane, or every parent's identity
            // check below it permanently misses and a keystroke rebuilds
            // the whole composition tree.
            return [$this, null];
        }
        return [new self($model, $this->bounds), $cmd];
    }

    /**
     * Render the pane's content positioned at its bounds origin.
     */
    public function view(): string
    {
        $raw = $this->model->view();
        $content = \is_string($raw) ? $this->clipToBounds($raw) : $this->clipToBounds($raw->body);

        // Position cursor at pane origin, then output content.
        // Newlines naturally advance rows while staying at the pane's x column.
        $positioned = Ansi::cursorTo($this->bounds->y + 1, $this->bounds->x + 1);

        // For the common case of string output, prepend positioning.
        // View objects are passed through as-is; callers composing Panes
        // with View-returning models should handle positioning themselves.
        return $positioned . $content;
    }

    /**
     * Clip a string body to the pane's cell budget: at most `height`
     * lines, each at most `width` visible cells (escape sequences ride
     * free — Width is ANSI-aware). A view() that returned a structured
     * View object keeps the pre-existing pass-through contract: the
     * body string inside it is clipped, cursor rows are not re-parsed.
     */
    private function clipToBounds(string $body): string
    {
        if ($body === '') {
            return $body;
        }
        $lines = \explode("\n", $body);
        if (\count($lines) > $this->bounds->height) {
            $lines = \array_slice($lines, 0, \max(0, $this->bounds->height));
        }
        $clipped = [];
        foreach ($lines as $line) {
            $clipped[] = Width::truncate($line, \max(0, $this->bounds->width));
        }
        return \implode("\n", $clipped);
    }
}
