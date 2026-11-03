<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Value-object representing a command paused across update cycles.
 *
 * A Suspended holds a callable that, when invoked, produces a Cmd
 * (a `Closure(): ?Msg`). The runtime stores the Suspended and resumes
 * it later when the subscription fires or the model decides to continue.
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
final class Suspended
{
    private readonly \Closure $resume;

    private bool $resumed = false;

    /**
     * @param callable(): (?\Closure) $resume  Called to resume the paused operation
     * @param T|null $state  Optional opaque state carried through the pause
     */
    public function __construct(
        callable $resume,
        private mixed $state = null,
    ) {
        $this->resume = \Closure::fromCallable($resume);
    }

    /**
     * @param callable(): (?\Closure) $resume
     * @param T|null $state
     */
    public static function new(callable $resume, mixed $state = null): self
    {
        return new self($resume, $state);
    }

    /**
     * Resume the paused operation. Returns the Cmd closure to be dispatched —
     * once. A second call yields null instead of re-running the effect: a
     * handle handed to two schedulers must not double-fire the command.
     */
    public function resume(): ?\Closure
    {
        if ($this->resumed) {
            return null;
        }

        $this->resumed = true;

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
