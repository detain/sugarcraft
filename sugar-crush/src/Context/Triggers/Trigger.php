<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Triggers;

/**
 * The closed union of the three trigger families that decide when a rule,
 * skill, or memory note becomes eligible for the prompt.
 *
 * DESIGN SOURCE: prompt_expand.md §4.20 (the whole-word keyword provenance),
 * §7.5 (trigger firing), §9.7 (the discriminated-union sketch), and §9.13
 * (intent-as-listing). This is a SugarCraft architecture type, not a port —
 * charmbracelet/crush has no Trigger symbol to mirror, so the repo's
 * "Mirrors charmbracelet/<repo>.<Method>" convention does not apply. The
 * honest lineage is upstream Claude Code's surviving anchored keyword matcher
 * (§4.20 records its /\bultrathink\b/i form) generalised into three named
 * families, and upstream OpenHands' trigger trio with its third member
 * renamed from TaskTrigger to IntentTrigger per §9.13's framing: the model
 * reads the intent description in the listing and decides for itself, so no
 * PHP code ever "matches" an intent.
 *
 * WHY A MARKER INTERFACE WITH ZERO METHODS. The three families match against
 * different domains: `KeywordTrigger` against the user prompt, `PathTrigger`
 * against a file path handed to it, `IntentTrigger` against nothing at all.
 * A common `matches(string)` would force IntentTrigger to pretend it answers
 * a question it never gets, and a `kind()` discriminator has zero precedent
 * in this codebase — verified before this type landed. Class identity is the
 * discriminator: consumers branch with `$trigger instanceof KeywordTrigger`
 * exactly as existing code does elsewhere, and the enum-companion escape
 * hatch (`Stability` beside `PromptSection`) stays unused because instanceof
 * over three closed final classes is already exhaustive.
 *
 * WHAT THIS TYPE IS FOR TODAY (P6.S1). It is the shared type for the
 * heterogeneous collections the next step's loader will build
 * (`array{Trigger}`), and it is the seam the plan's "one union, not three
 * parallel loaders" requirement names. Nothing is wired to it yet by design:
 * the consumer lands in P6.S2, and per §1.10 shipping these classes unwired
 * is the intended state of this step, not a leftover to tidy.
 *
 * Every implementer is a final, immutable value object constructed from
 * plain PHP arrays/strings — no parsing, no filesystem, no environment. They
 * take candidate strings as ARGUMENTS and return bools/strings, which keeps
 * them pure: the read-sink census over `src/` must never gain a row for this
 * directory.
 */
interface Trigger
{
}
