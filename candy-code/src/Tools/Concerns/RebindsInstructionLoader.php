<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Concerns;

use SugarCraft\Code\Context\InstructionFileLoader;

/**
 * The one implementation of {@see \SugarCraft\Code\Tools\AcceptsInstructionLoader}
 * every instruction-delivering built-in shares (ISS-065, the full fix).
 *
 * The sister of {@see RebindsWorktreeJail}, and the same preconditions: the
 * tools using it are `final readonly` with every property constructor-promoted
 * and a promoted `$instructionLoader` among them, so
 * {@see get_object_vars()} IS the constructor's argument list, by name — a
 * rebuild carries every field (one added later included) and replaces the
 * loader alone, failing loudly on `Unknown named parameter` rather than
 * dropping state.
 */
trait RebindsInstructionLoader
{
    public function withInstructionLoader(InstructionFileLoader $loader): static
    {
        return new self(...array_replace(get_object_vars($this), ['instructionLoader' => $loader]));
    }
}
