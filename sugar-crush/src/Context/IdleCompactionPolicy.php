<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * "This session has been sitting idle and is bigger than its window" — the
 * outermost context tier, and the one place its two numbers live.
 *
 * Extracted because {@see \SugarCraft\Crush\Chat::shouldPromptIdleCompaction()}
 * was a deliberate copy of
 * {@see \SugarCraft\Crush\Runtime::shouldPromptIdleCompaction()} (Chat's own
 * docblock said so: the Runtime instance is not reachable from the TUI event
 * loop), and both hardcoded the same threshold independently. The copy stays
 * — Chat still must not reach for a Runtime it does not hold — but both now
 * delegate here, so the two cannot disagree.
 *
 * Both callers keep their own public method: each supplies the token limit
 * from whatever it can see (Chat from its {@see \SugarCraft\Crush\Backend},
 * Runtime from its {@see \SugarCraft\Crush\Providers\ProviderInterface}).
 *
 * THE FILE NOW CARRIES A SECOND TIER'S NUMBER TOO, and that is said plainly
 * rather than left to be discovered by a reader who assumed otherwise.
 * {@see REFILL_LIMIT} and {@see thrashTripped()} belong to the AUTOMATIC 85%
 * tier, not to the idle one: they are the circuit breaker that stops that tier
 * from compacting a context that refills the moment it is compacted
 * (prompt_expand.md §4.23). They live here because a threshold and the
 * predicate that reads it belong in one place — the same reason this file
 * exists at all, which was two copies of one number disagreeing. Everything
 * above about the idle tier is still exactly true: `IDLE_SECONDS` and
 * {@see shouldPrompt()} are unmoved, and this remains the one place the idle
 * tier's two numbers live.
 */
final class IdleCompactionPolicy
{
    /**
     * How long a session must have gone untouched before its size is worth
     * interrupting the user about. One hour: short enough that a session
     * resumed the next morning is caught, long enough that a coffee break is
     * not. The default of `compaction.idleOfferSeconds` (roadmap N-P4b), which
     * a session carries on its {@see CompactorConfig}.
     */
    public const IDLE_SECONDS = 3600;

    /**
     * How many consecutive automatic-tier compactions may leave the context back
     * at or over its tier before that tier stops trying — the circuit breaker's
     * number (prompt_expand.md §4.23).
     *
     * The value is the one the upstream fix names, quoted in that section from
     * Claude Code's own changelog: "now detects when context refills to the limit
     * immediately after compacting three times in a row and stops with an
     * actionable error instead of burning API calls". Three is therefore not a
     * tuned constant but a reproduced one, and it is generous rather than tight:
     * a compaction is routinely the largest single prompt this app sends, so
     * three of them in a row with nothing gained has already cost the user real
     * money.
     *
     * What counts as "refills to the limit immediately" is measured at compaction
     * COMPLETION, against the same estimate-and-window pair the tier itself used
     * — see {@see \SugarCraft\Crush\Chat}'s `consecutiveRefillCompactions`
     * property, which owns the measurement, and the comment on it for why the
     * rewrite's own post-state is the only honest reading of the phrase.
     *
     * The default of `compaction.refillLimit` (roadmap N-P4b), carried on the
     * session's {@see CompactorConfig}.
     */
    public const REFILL_LIMIT = 3;

    /**
     * Whether to interrupt this turn and offer `/compact` instead of sending it.
     *
     * The size test is "past the WHOLE window", not a percentage of it, which
     * is what makes this the outermost tier: {@see ContextCompactor}'s 85%
     * automatic compaction and 95% blocking refusal both fire earlier and
     * both act on their own, so the only way to arrive here is for automatic
     * compaction to have been unable to get back under the window (it
     * preserves the most recent exchanges in full, so a handful of enormous
     * ones cannot be shrunk) AND for the user to then have left. That is
     * precisely the "nothing automatic is left to try, ask the human" case
     * this prompt describes.
     *
     * `null` lastActivityAt means idleness is unknown, which is never grounds
     * for interrupting.
     *
     * @param int $tokenCount Estimated tokens (chars/4 proxy) in the history.
     * @param int $tokenLimit Budget from {@see ContextWindow}; a non-positive
     *                        one disables the check rather than firing on
     *                        every turn, matching {@see ContextCompactor}'s
     *                        own guards.
     * @param int|null $now Unix seconds to measure idleness against; `time()`
     *                      when omitted, which is what both live callers pass.
     *                      It exists so the boundary AT {@see IDLE_SECONDS} can
     *                      be asserted deterministically: with two independent
     *                      clock reads (one to build $lastActivityAt, one here)
     *                      an integer-second rollover between them makes an
     *                      exactly-3,600-second-old timestamp measure 3,601 and
     *                      cross the boundary under test.
     * @param int $idleSeconds How long counts as idle: the session's
     *                      `compaction.idleOfferSeconds`
     *                      ({@see CompactorConfig::$idleOfferSeconds}), else
     *                      {@see IDLE_SECONDS}. `0` turns the offer off.
     */
    public static function shouldPrompt(
        int $tokenCount,
        ?\DateTimeImmutable $lastActivityAt,
        int $tokenLimit,
        ?int $now = null,
        int $idleSeconds = self::IDLE_SECONDS,
    ): bool {
        if ($idleSeconds <= 0) {
            return false;
        }

        if ($tokenLimit <= 0 || $tokenCount <= $tokenLimit) {
            return false;
        }

        if ($lastActivityAt === null) {
            return false;
        }

        return (($now ?? time()) - $lastActivityAt->getTimestamp()) > $idleSeconds;
    }

    /**
     * Whether the automatic compaction tier has thrashed: this many compactions
     * in a row each left the context back at or over the tier that asked for
     * them, so the next one would buy nothing and cost a provider call.
     *
     * `>=` and not `===`, deliberately: the count is a threshold being crossed,
     * not an event being matched, and a caller that incremented past the limit
     * (a reset wired wrong, a future second increment site) must still be
     * stopped rather than served. The boundary is asserted on both sides.
     *
     * The predicate is pure and holds nothing — the counter it reads lives on
     * {@see \SugarCraft\Crush\Chat}, because it counts that object's compactions
     * and resets with its thread. This file answers "is that many enough to
     * stop", which is the part two callers must not disagree about.
     *
     * @param int $limit the session's `compaction.refillLimit`
     *                   ({@see CompactorConfig::$refillLimit}), else
     *                   {@see REFILL_LIMIT}; below 1 counts as 1
     */
    public static function thrashTripped(int $consecutiveRefills, int $limit = self::REFILL_LIMIT): bool
    {
        return $consecutiveRefills >= max(1, $limit);
    }
}
