<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * C-PRV-1: one table of which model FAMILIES think at all, and how large a
 * reply they may spend thinking inside of — the Anthropic and Gemini
 * families, which are the two whose wires carry a thinking-token budget.
 *
 * Before this class the question "does this model support extended
 * thinking" was answered per provider by whichever default that provider
 * happened to ship, so `claude-3-5-sonnet` (a generation before thinking
 * existed) could be handed a `budget_tokens` arm at all, and the exclusions
 * lived in no one place. This is the single home every shaper consults:
 * {@see AnthropicProvider}, {@see VertexProvider} (both its arms),
 * {@see GeminiProvider} and {@see BedrockProvider} (its Claude-family ids).
 * C-PRV-5.a reuses the ceiling leg for budget clamping.
 *
 * Honesty about the numbers: the family GENERATIONS here are documented
 * upstream facts (Anthropic introduced extended thinking with
 * claude-3-7-sonnet; Gemini's thinking budgets start at 2.5), and the
 * ceilings are each family's published current-generation maximum output.
 * Nothing in this class is a wire measurement — no AWS or API credentials
 * live in this repository — so a test may assert against this table but a
 * change here is a claim about the vendors' documentation, not about code.
 *
 * This is a SugarCraft architecture table, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
final class ThinkingCapability
{
    public const FAMILY_ANTHROPIC = 'anthropic';
    public const FAMILY_GEMINI = 'gemini';

    /**
     * Model ids of these generations answer the thinking question with no:
     * the Anthropic rows predate claude-3-7-sonnet (the first generation
     * whose API accepts a `thinking` block), the Gemini rows predate the
     * 2.5 family's `thinkingConfig`.
     */
    private const NO_THINKING = [
        // claude-2, claude-3-opus/sonnet/haiku, claude-3-5-sonnet/haiku.
        self::FAMILY_ANTHROPIC => '/claude-(?:2(?:\.1)?(?:-[0-9]{2})?|3-opus|3-sonnet|3-haiku|3-5)/',
        // gemini-1.x and gemini-2.0 shipped no thinking budget.
        self::FAMILY_GEMINI => '/gemini-(?:1|2\.0)/',
    ];

    /**
     * Published per-family maximum output tokens for the thinking-capable
     * generations — the ceiling a thinking budget must stay under, since
     * thinking tokens are billed inside the reply. Family-wide on purpose:
     * a per-generation table would be a maintenance ledger nobody audits,
     * and every current-generation row of both families clears the highest
     * rung {@see EffortBudget} can name (32768).
     */
    private const OUTPUT_CEILINGS = [
        self::FAMILY_ANTHROPIC => 64_000,
        self::FAMILY_GEMINI => 65_536,
    ];

    /**
     * The family a model id belongs to for thinking purposes, or null when
     * the id is neither family (a llama id, a random served name).
     *
     * Ids arrive in every shop's dress — `anthropic.claude-sonnet-4-6`,
     * `us.anthropic.claude-sonnet-4-6`, `claude-sonnet-4-6@20250929`, an
     * inference-profile ARN — so the answer keys on the substring that
     * survives all of those, not on a prefix rule.
     */
    public static function family(string $model): ?string
    {
        $id = strtolower(trim($model));

        if (str_contains($id, 'claude')) {
            return self::FAMILY_ANTHROPIC;
        }

        if (str_contains($id, 'gemini')) {
            return self::FAMILY_GEMINI;
        }

        return null;
    }

    public static function supportsThinking(string $model): bool
    {
        $family = self::family($model);

        if ($family === null) {
            return false;
        }

        return preg_match(self::NO_THINKING[$family], strtolower(trim($model))) !== 1;
    }

    /**
     * The family's published maximum output tokens, or null for a model
     * outside the two thinking families. Null means "this table has no
     * answer", never "zero".
     */
    public static function outputCeiling(string $model): ?int
    {
        $family = self::family($model);

        return $family === null ? null : self::OUTPUT_CEILINGS[$family];
    }

    private function __construct()
    {
    }
}
