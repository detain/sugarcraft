<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Backend;

/**
 * Opt-in declaration that a {@see Backend} knows the token budget the model
 * behind it will actually accept (crush_code.md Phase 5 item 4).
 *
 * The context tiers in {@see \SugarCraft\Crush\Chat} — the 70% reminder, the
 * 85% automatic compaction, the 95% blocking refusal and the idle-compaction
 * prompt — are all percentages of *something*, and until this interface
 * existed that something was a hardcoded 100,000 written into `Chat` twice
 * and into {@see \SugarCraft\Crush\Runtime} once. Every
 * {@see \SugarCraft\Crush\Providers\ProviderInterface} already reports its
 * real window; nothing could reach it, because `Chat` holds a `Backend` and
 * `Backend` has no provider.
 *
 * Deliberately a separate capability rather than a method on `Backend`
 * itself, for two reasons:
 *
 *  1. `Backend` is documented as a third-party extension point ("Implement
 *     this interface to wire SugarCrush to your LLM of choice … a local
 *     script, anything that returns text"). Adding a required method to it
 *     is a load-time fatal for every implementation outside this repo, not a
 *     graceful degradation.
 *
 *  2. Three of the four in-repo backends have no model behind them at all —
 *     {@see EchoBackend} echoes, {@see CommandBackend} and
 *     {@see StreamingCommandBackend} shell out to an arbitrary command whose
 *     window is unknowable here. Forcing them to answer would mean three
 *     invented constants, each of which then *silently* becomes the
 *     compaction denominator. One named fallback in one place
 *     ({@see \SugarCraft\Crush\Context\ContextWindow::FALLBACK_TOKENS}) is
 *     auditable; three authoritative-looking fabrications are not.
 *
 * Same shape as {@see \SugarCraft\Crush\Tools\ParallelSafe} /
 * {@see \SugarCraft\Crush\Tools\CarriesSessionState}: not implementing it is
 * the safe default, and the one consumer
 * ({@see \SugarCraft\Crush\Context\ContextWindow::ofBackend()}) asks "does
 * this backend know its window?" rather than "is this an EngineBackend?".
 */
interface ReportsContextWindow
{
    /**
     * The model's context window in PROVIDER-COUNTED tokens.
     *
     * Note the unit mismatch the caller has to live with: everything this is
     * compared against in `Chat` is a chars/4 estimate, not a tokenizer
     * count. That is why the status bar prints its readout with a leading
     * `~` and why the 95% tier exists at all rather than a 100% one.
     *
     * A backend that cannot determine one should return 0 rather than guess;
     * {@see \SugarCraft\Crush\Context\ContextWindow::resolve()} turns any
     * non-positive answer into the explicit fallback. Returning 0 must never
     * be read as "no limit": a 0 handed straight to the compactor's
     * predicates disables all three of them via their own `$tokenLimit <= 0`
     * guards, which would switch the feature off instead of on.
     */
    public function contextWindow(): int;
}
