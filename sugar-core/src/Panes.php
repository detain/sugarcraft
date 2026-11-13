<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Msg\KeyMsg;

/**
 * A Model that holds and composes multiple Panes.
 *
 * Each Pane renders its content positioned at its bounds origin via
 * ANSI cursor sequences. Tab cycles the active pane so keyboard events
 * are routed to the correct sub-model. The constructor clamps
 * `activeIndex` into range; Tab handling is the consumer-facing focus
 * API (the runtime does not rebind it elsewhere — see the Tab arm).
 *
 * Usage:
 * ```php
 * $panes = new Panes([
 *     new Pane($leftModel, new Rect(0, 0, 40, 24)),
 *     new Pane($rightModel, new Rect(40, 0, 40, 24)),
 * ]);
 * ```
 */
final readonly class Panes implements Model
{
    /** @param list<Pane> $panes */
    private array $panes;

    /** Focus index, clamped into [0, count-1] by the constructor. */
    public readonly int $activeIndex;

    public function __construct(
        array $panes,
        int $activeIndex = 0,
    ) {
        $this->panes = $panes;
        // activeIndex is clamped into [0, count-1] — the ship default of
        // the house with*/ctor law (sugar-core-027). An out-of-range focus
        // index used to make update() dereference a missing slot; refusal
        // instead of clamping is a documented OWNER-gate alternative.
        $count = \count($panes);
        $this->activeIndex = $count === 0 ? 0 : \max(0, \min($count - 1, $activeIndex));
    }

    /** @return null|\Closure */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        $count = count($this->panes);
        if ($count === 0) {
            return [$this, null];
        }

        // Tab cycles to the next pane.
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab) {
            $newIndex = ($this->activeIndex + 1) % $count;
            return [new self($this->panes, $newIndex), null];
        }

        // Route to active pane.
        $active = $this->panes[$this->activeIndex];
        [$updatedPane, $cmd] = $active->update($msg);

        if ($updatedPane === $active) {
            return [$this, $cmd];
        }

        $newPanes = $this->panes;
        $newPanes[$this->activeIndex] = $updatedPane;

        return [new self($newPanes, $this->activeIndex), $cmd];
    }

    /**
     * Render all panes in sequence, each positioned at its origin.
     */
    public function view(): string
    {
        $output = '';
        foreach ($this->panes as $pane) {
            $output .= $pane->view();
        }
        return $output;
    }

    /**
     * The number of panes.
     */
    public function count(): int
    {
        return count($this->panes);
    }

    /**
     * The index of the currently active (focused) pane.
     */
    public function activeIndex(): int
    {
        return $this->activeIndex;
    }

    /**
     * The list of panes.
     *
     * @return list<Pane>
     */
    public function panes(): array
    {
        return $this->panes;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}
