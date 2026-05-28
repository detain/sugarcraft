<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Value-object representing a paused TEA command.
 *
 * A Suspended holds a callable that, when invoked, produces a Cmd.
 * The runtime stores the Suspended and resumes it later when the
 * subscription fires or the model decides to continue.
 *
 * This pattern allows long-running or event-driven commands to
 * be represented as data rather than eagerly executing side-effects.
 *
 * Mirrors the Elm architecture's "paused" command concept used for
 * effects that span multiple update cycles (e.g. animations,
 * debounced input, async event handlers).
 *
 * @template T
 */
final readonly class Suspended
{
    /**
     * @param callable(): (\SugarCraft\Core\Cmd|null) $resume  Called to resume the paused operation
     * @param T|null $state  Optional opaque state carried through the pause
     */
    public function __construct(
        private callable $resume,
        private mixed $state = null,
    ) {
    }

    /**
     * Resume the paused operation. Returns the Cmd to be dispatched.
     */
    public function resume(): \SugarCraft\Core\Cmd|null
    {
        return ($this->resume)();
    }

    /**
     * @return T|null
     */
    public function state(): mixed
    {
        return $this->state;
    }
}
