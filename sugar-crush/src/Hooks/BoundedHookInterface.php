<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * A hook whose one run has a wall-clock bound, and which can be handed a
 * shorter one.
 *
 * WHY THIS IS A SEPARATE INTERFACE AND NOT TWO METHODS ON
 * {@see HookInterface}. A hand-written PHP hook is a synchronous method call in
 * this process; there is no portable way to put a deadline on one, and adding
 * methods to `HookInterface` would break every implementation for a bound none
 * of them can honour. Only a hook that runs something OUT of process can be cut
 * short, so only {@see ScriptHook} implements this — and
 * {@see HookRegistry::executeHooks()} charges exactly those hooks against the
 * chain's shared budget and leaves the rest alone.
 *
 * That asymmetry is stated rather than hidden: a chain of hand-written hooks is
 * bounded by nothing this class can see, exactly as it always was. What changed
 * is that a chain of SCRIPT hooks no longer multiplies its per-hook budget by
 * the number of hooks and by {@see HookRegistry::MAX_REWRITE_PASSES}.
 */
interface BoundedHookInterface extends HookInterface
{
    /** The wall clock one run of this hook gets, in seconds. Positive and finite. */
    public function timeoutSeconds(): float;

    /**
     * The same hook with a shorter bound.
     *
     * Only ever called with LESS than {@see timeoutSeconds()} already returns:
     * the chain may take budget away from a hook, never grant it more than its
     * author asked for.
     */
    public function withTimeoutSeconds(float $seconds): self;
}
