<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * A {@see Tool} whose `execute()` mutates SESSION-scoped state living outside
 * its own (readonly) properties — the "announce this once and never again"
 * bookkeeping of {@see \SugarCraft\Crush\Context\InstructionFileLoader} and
 * {@see \SugarCraft\Crush\Skills\SkillPathNudge} being the two real cases.
 *
 * A tool run inside one of {@see \SugarCraft\Crush\Runtime}'s forked children
 * mutates the CHILD's copy-on-write copy of that state, which dies with the
 * child: the emitted text still reaches the model (it is part of the returned
 * {@see ToolResult}), but the "already emitted" mark does not, so the next
 * call would emit it a second time. This interface is the seam that carries
 * the mark back across the fork.
 *
 * Both halves must be SET-shaped and order-independent: the parent merges
 * exports from several children that ran concurrently, in no guaranteed
 * order, on top of its own state. Merging is therefore a union, never a
 * replacement, and applying the same export twice must be a no-op.
 *
 * Implementations must export only scalars/arrays — the payload crosses the
 * fork boundary as a `serialize()`d array that the parent decodes with
 * `allowed_classes => false`.
 */
interface CarriesSessionState
{
    /**
     * @return array<string, mixed> the session-scoped marks this tool
     *                              accumulated, safe to hand to
     *                              {@see mergeSessionState()} on another
     *                              instance wired to the same collaborators
     */
    public function exportSessionState(): array;

    /**
     * @param array<string, mixed> $state an {@see exportSessionState()}
     *                                    payload, possibly from an older
     *                                    build — unknown or malformed keys
     *                                    must be ignored, never fatal
     */
    public function mergeSessionState(array $state): void;
}
