<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * The seam for providers whose wire speaks a SERVICE-TIER switch.
 *
 * C-PRV-3.a (plan_comparison). The `serviceTier` setting — the words
 * `default` or `fast` in the user's config — is not a wire value, and the
 * wire value is not a word the user types. Each vendor spells its own
 * premium lane: the OpenAI surface carries `service_tier: "priority"`, the
 * Anthropic Messages surface carries `speed: "fast"` and only on the
 * fast-mode family. This interface lets the launch-time overlay — and the
 * `/model` rebind seam it rides — ask a provider two things:
 *
 *  - `serviceTierFor($model)`: WHAT the provider would put on the wire for
 *    a fast request against this model, or null when the pair shapes
 *    nothing. The answer is a verdict the settings UI, the status line, and
 *    the honest refusal in `LaunchContext::withResolvedServiceTier()` all
 *    read from ONE door, so no surface can guess a model eligible on one
 *    provider and invisible on the next.
 *
 *  - `withServiceTier($tier)`: a clone carrying the resolved WIRE value (or
 *    null to clear), NOT the config word. The overlay in `EngineBackend`
 *    keeps the word; the provider keeps only what its own wire speaks.
 *
 * Why a provider seam and not a `CompleteRequest` field: the tier belongs
 * to the session's launch configuration the same way `promptCache` does —
 * it survives model rebinds (the provider clone carries it) and never
 * rides per-turn history. Mirroring the sibling `ShapesReasoningEffort`
 * doctrine: the roster is deliberately narrow — a provider that does not
 * implement this is NOT silently ignored; the gate answers false honestly
 * and the launch notice (C-PRV-3.b) says so in prose instead of the wire
 * quietly dropping what the user asked for.
 *
 * The billing half is part of the contract: a fast-tiered provider answers
 * `costPer1kTokens()` with null while the tier is active, because neither
 * vendor's price table is tier-keyed and the premium is NOT derivable from
 * a model-keyed lookup. Zero billing plus the `unpricedModel` signal is
 * the honest ledger; guessing a multiplier would fabricate dollars.
 * Tier-priced tables are the C-PRV-3.b seam, named in both providers.
 */
interface ShapesServiceTier
{
    /**
     * The configuration words the `serviceTier` setting accepts. The wire
     * values are each provider's business; these two strings are the one
     * vocabulary the settings schema, the resolver and the overlay share.
     */
    public const WORD_DEFAULT = 'default';

    public const WORD_FAST = 'fast';

    /**
     * The wire value this provider sends for a fast request against
     * `$model`, or null when this pair shapes nothing fast.
     */
    public function serviceTierFor(string $model): ?string;

    /**
     * A clone carrying `$tier` — a resolved wire value from
     * `serviceTierFor()`, or null to clear back to the standard lane.
     */
    public function withServiceTier(?string $tier): static;
}
