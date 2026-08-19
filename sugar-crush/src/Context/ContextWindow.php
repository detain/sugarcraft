<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\ReportsContextWindow;

/**
 * The single place the token budget behind every context tier is resolved
 * (crush_code.md Phase 5 item 4).
 *
 * {@see ContextCompactor}'s three predicates and the idle-compaction prompt
 * are each a percentage of a limit passed in by their caller. Before this
 * class the callers wrote that limit as a literal `100000` — once on `Chat`
 * as a private const, once again inside `Chat::shouldPromptIdleCompaction()`,
 * and a third time inside `Runtime::shouldPromptIdleCompaction()`. Three
 * independent copies of one number, with four more references to it in the
 * prose of the same two files. This class owns it instead, so a threshold and
 * the budget it is a percentage of cannot drift apart.
 */
final class ContextWindow
{
    /**
     * Budget assumed when the backend cannot report a real one.
     *
     * The unit is ESTIMATED tokens — the chars/4 + 10-per-message proxy
     * {@see ContextCompactor::countTokens()} and
     * {@see \SugarCraft\Crush\Chat::estimateTokenCount()} both compute — not
     * tokenizer-counted tokens. The value is the one this app acted on before
     * any real window was reachable, kept deliberately so that a backend with
     * no model behind it (echo, or a shelled-out command) goes on behaving
     * exactly as it did before crush_code.md Phase 5 item 4.
     *
     * It is NOT a conservative floor, and an earlier draft of this docblock
     * claimed it was. Measured over every `contextWindow()` in
     * `src/Providers/`, 100,000 is LARGER than six provider/model pairs:
     * {@see \SugarCraft\Crush\Providers\OpenAIProvider} reports 8,192 for
     * `gpt-4` and for its `default` arm and 16,385 for `gpt-3.5-turbo`, and
     * {@see \SugarCraft\Crush\Providers\BedrockProvider} reports 8,192 for
     * both `meta.llama3-*` models and for its `default` arm. So on an unknown
     * backend this errs toward compacting LATER than those six would, not
     * earlier. That is the honest trade: guessing small would compact a
     * shelled-out command's session it has no reason to touch, and there is no
     * value that is simultaneously safe for a 8,192-token model and not
     * absurd for a 200,000-token one — which is why every provider that CAN
     * answer is asked instead, and why 0 (see
     * {@see \SugarCraft\Crush\Providers\ProviderInterface::contextWindow()})
     * means "unknown" rather than "unlimited".
     */
    public const FALLBACK_TOKENS = 100_000;

    /**
     * Turn a reported window into one safe to divide by.
     *
     * The guard is the whole point: {@see ContextCompactor}'s three
     * predicates each early-return `false` on `$tokenLimit <= 0`, so handing
     * a 0 straight through would silently disable the reminder, the automatic
     * compaction AND the blocking tier — switching the feature off in exactly
     * the situation (a backend that cannot answer) where it is most needed.
     * A non-positive answer therefore becomes the explicit fallback, never a
     * pass-through.
     */
    public static function resolve(int $reported): int
    {
        return $reported > 0 ? $reported : self::FALLBACK_TOKENS;
    }

    /**
     * The window a chat backend reports, or {@see FALLBACK_TOKENS}.
     *
     * The one `instanceof` behind {@see ReportsContextWindow}: a backend that
     * does not implement the capability is not interrogated and is not asked
     * to invent an answer.
     */
    public static function ofBackend(Backend $backend): int
    {
        if (!$backend instanceof ReportsContextWindow) {
            return self::FALLBACK_TOKENS;
        }

        return self::resolve($backend->contextWindow());
    }
}
