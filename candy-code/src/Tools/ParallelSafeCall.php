<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * Per-INVOCATION counterpart of {@see ParallelSafe}, for a tool whose safety
 * depends on what it was asked to do rather than on what it is — `Bash` is
 * concurrency-safe for `git status` and not for `rm -rf`.
 *
 * {@see \SugarCraft\Code\Runtime} consults this instead of {@see ParallelSafe}
 * when a tool implements both-or-either; the same two rules apply to the
 * answer (no observable mutation, session state survives the fork). Say true
 * only when THESE arguments satisfy them; anything doubtful is false, which is
 * the barrier every unknown call already gets.
 */
interface ParallelSafeCall
{
    /**
     * @param array<string, mixed> $args the call's decoded arguments
     */
    public function isParallelSafeFor(array $args): bool;
}
