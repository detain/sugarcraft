<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * PRV-2 (§6.5): the Anthropic-family thinking-token mapping of a named
 * reasoning-effort tier.
 *
 * `reasoningEffort` is the effort knob spelled as a WORD; `thinkingBudget`
 * is the same spend spelled as TOKENS. Providers that think in tokens —
 * the Anthropic Messages API, and the Gemini `thinkingConfig` when the
 * operator left its own budget unset — need one translation, and it lives
 * here rather than in each wire so every host maps a tier to the same
 * number. `low` sits exactly on the Messages API's `budget_tokens` floor
 * (1024, {@see AnthropicMessagesWire::MIN_THINKING_BUDGET}): the cheapest
 * tier that still switches thinking ON, because `low` is a request for
 * thinking, not for less of nothing.
 *
 * A float is refused rather than coerced: a token budget is an integer
 * protocol field, and the float form of `reasoningEffort` is SGLang's
 * own 0.0–0.99 dial — a "how much" that transport already consumes
 * directly, so mapping it here would spend a token number on a wire
 * that asked for a dial. Unknown spellings and null mean "no mapping",
 * which every caller treats as "ask nothing", never as an error.
 *
 * This is a SugarCraft architecture helper, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
final class EffortBudget
{
    /** Tokens per tier. `low` is the protocol floor; the rest step x2. */
    private const TOKENS = [
        'low' => 1024,
        'medium' => 4096,
        'high' => 8192,
        'xhigh' => 16384,
        'max' => 32768,
    ];

    private function __construct()
    {
    }

    /**
     * The thinking-token budget a named tier means on a tokens-speaking
     * wire; null when the value is not one of the five tier names (a float
     * dial, an unknown word, or nothing at all).
     */
    public static function thinkingTokens(string|float|null $effort): ?int
    {
        if (!\is_string($effort)) {
            return null;
        }

        return self::TOKENS[strtolower(trim($effort))] ?? null;
    }

    /**
     * The tier names, in ascending spend order — the list `/effort` prints
     * in its refusal and usage rows, derived from the table itself so the
     * two can never disagree.
     *
     * @return list<string>
     */
    public static function levels(): array
    {
        return array_keys(self::TOKENS);
    }
}
