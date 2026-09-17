<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Forms\Util\RenderSafe;

/**
 * Public face of the per-error help line (plan 5.11, round 89 lane y3).
 *
 * A field carrying error help renders one extra row directly beneath its
 * validation error row (`  ? ...` under `! ...`) — the hint is attached to
 * the FAILURE, not to the field: it stays invisible while the value is
 * valid, so a long validator contract never spends vertical space until it
 * bites. This is the shape charmbracelet/huh uses for its per-field error
 * copy (help text surfaced beside the error, not the description); the
 * literal '  ? ' prefix mirrors the literal '! ' of the error row.
 *
 * Storage law (E741): the slot itself lives in
 * {@see CarriesNonCtorState} — every `new self(...)` rebuild funnels
 * through the shared carrier, which copies the value — so this trait only
 * adds the fluent setter, the bare accessor and the render helper. A class
 * using this trait MUST therefore also use {@see CarriesNonCtorState}, and
 * MUST call {@see errorHelpLines()} from its view() right after the error
 * row (pinned per-field in ErrorHelpTest; the census in
 * TraitStateCarryFamilyTest keeps the slot itself from drifting).
 */
trait HasErrorHelp
{
    /**
     * Help line shown beside a validation error. `null` (the default)
     * renders nothing; pass `null` again to clear.
     */
    public function withErrorHelp(?string $help): static
    {
        $next        = clone $this;
        $next->errorHelp = $help;
        return $next;
    }

    public function errorHelp(): ?string
    {
        return $this->errorHelp;
    }

    /**
     * The extra view rows this contributes — empty unless BOTH an error and
     * help are set (the help rides the error, never floats alone).
     *
     * @return list<string>
     */
    protected function errorHelpLines(): array
    {
        if ($this->error === null || $this->errorHelp === null) {
            return [];
        }
        return ['  ? ' . RenderSafe::clean($this->errorHelp)];
    }
}
