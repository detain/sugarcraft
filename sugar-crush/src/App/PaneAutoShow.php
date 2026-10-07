<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Crush\Tui\Pane;

/**
 * Which sidepanes have settled out of auto-enable consideration (CL-2 FIX 1).
 *
 * Two memories, both pane-keyed, both process-lifetime:
 *
 * - autoShown: the pane already took its first automatic dock when its
 *   content appeared — it is never auto-docked a second time;
 * - userToggled: the user themselves docked/undocked/re-sided the pane —
 *   a manual close is honored for the remainder of the process, and the
 *   auto-show never overrides it.
 *
 * Immutable and fluent like the rest of the shell state; it rides the App
 * lineage through {@see App::mutate()}. Nothing here persists — the dock
 * itself persists through the normal layout channel, so an auto-shown pane
 * saved by that channel comes back docked, exactly like any user choice.
 */
final class PaneAutoShow
{
    /**
     * @param list<string> $autoShown Pane values already auto-docked once.
     * @param list<string> $userToggled Pane values the user touched by hand.
     */
    public function __construct(
        private readonly array $autoShown = [],
        private readonly array $userToggled = [],
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /** The auto-show leaves this pane alone for the rest of the process. */
    public function settled(Pane $pane): bool
    {
        return \in_array($pane->value, $this->autoShown, true)
            || \in_array($pane->value, $this->userToggled, true);
    }

    public function withAutoShown(Pane $pane): self
    {
        if (\in_array($pane->value, $this->autoShown, true)) {
            return $this;
        }

        return new self([...$this->autoShown, $pane->value], $this->userToggled);
    }

    public function withUserToggled(Pane $pane): self
    {
        if (\in_array($pane->value, $this->userToggled, true)) {
            return $this;
        }

        return new self($this->autoShown, [...$this->userToggled, $pane->value]);
    }
}
