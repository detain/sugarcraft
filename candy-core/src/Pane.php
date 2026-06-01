<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Util\Ansi;

/**
 * A Pane wraps a Model and knows its bounds within the terminal grid.
 *
 * Each Pane renders its content positioned at its origin (x, y). The
 * content is offset via ANSI cursor positioning so multiple Panes can
 * be composed side-by-side without their outputs overwriting each other.
 *
 * Mirrors the role of a "Pane" in Bubble Tea's view system — a region
 * of the screen occupied by a sub-model.
 */
readonly class Pane
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
        return [new self($model, $this->bounds), $cmd];
    }

    /**
     * Render the pane's content positioned at its bounds origin.
     */
    public function view(): string
    {
        $content = $this->model->view();

        // Position cursor at pane origin, then output content.
        // Newlines naturally advance rows while staying at the pane's x column.
        $positioned = Ansi::cursorTo($this->bounds->y + 1, $this->bounds->x + 1);

        // For the common case of string output, prepend positioning.
        // View objects are passed through as-is; callers composing Panes
        // with View-returning models should handle positioning themselves.
        if (\is_string($content)) {
            return $positioned . $content;
        }

        return $positioned . $content->body;
    }
}
