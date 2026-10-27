<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Lang;

/**
 * C-PRV-1: the single door the `/effort` surface walks through to answer
 * "will the tier actually go anywhere?".
 *
 * Before this class the answer was assembled twice from two partial
 * truths: `Chat::handleEffortCommand()` checked the bare
 * {@see ShapesReasoningEffort} marker (a class-level yes that stayed yes
 * on `gpt-4o`, where OpenAI has no field to put the tier), and the status
 * phrase chain was inlined in the command itself. Both halves now live
 * here: {@see verdict()} is the per-model predicate with the
 * {@see FallbackProvider} unwrap folded in (a fallback roster's shape is
 * its primary's shape — the tier rides the request that goes to whichever
 * arm answers, and claiming the fallbacks' wire abilities for the primary
 * would be a different lie), and {@see shape()} renders the status phrase
 * byte-for-byte as it read before the extraction, with `bedrock` joining
 * the thinking-tokens arm because it spends the same {@see EffortBudget}
 * table.
 *
 * This is a SugarCraft architecture gate, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
final class EffortGate
{
    /**
     * Will this provider send the tier for this model?
     *
     * A null provider (no engine backend yet) never shapes anything.
     */
    public static function verdict(?ProviderInterface $provider, string $model): bool
    {
        if ($provider instanceof FallbackProvider) {
            $provider = $provider->primary();
        }

        return $provider instanceof ShapesReasoningEffort
            && $provider->shapesEffortFor($model);
    }

    /**
     * The one-sentence answer to "what does the tier do here?", spelled
     * from the `chat.effort.shape.*` catalogue: the refusal when the
     * verdict is no, the honest unset note when nothing is asked, the
     * token-spend statement on the four families that turn the tier into
     * a thinking budget, and the request-field statement everywhere else
     * the verdict is yes.
     *
     * The level is the already-stringified engine value; null means unset.
     */
    public static function shape(?ProviderInterface $provider, ?string $level, string $model): string
    {
        if (!self::verdict($provider, $model)) {
            return Lang::t('chat.effort.shape.none');
        }

        if ($level === null) {
            return Lang::t('chat.effort.shape.unset');
        }

        if ($provider instanceof FallbackProvider) {
            $provider = $provider->primary();
        }

        if ($provider instanceof AnthropicProvider
            || $provider instanceof VertexProvider
            || $provider instanceof GeminiProvider
            || $provider instanceof BedrockProvider
        ) {
            return Lang::t('chat.effort.shape.thinking', ['tokens' => (string) EffortBudget::thinkingTokens($level)]);
        }

        return Lang::t('chat.effort.shape.request');
    }

    private function __construct()
    {
    }
}
