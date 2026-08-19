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
 */
final class IdleCompactionPolicy
{
    /**
     * How long a session must have gone untouched before its size is worth
     * interrupting the user about. One hour: short enough that a session
     * resumed the next morning is caught, long enough that a coffee break is
     * not.
     */
    public const IDLE_SECONDS = 3600;

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
     */
    public static function shouldPrompt(
        int $tokenCount,
        ?\DateTimeImmutable $lastActivityAt,
        int $tokenLimit,
        ?int $now = null,
    ): bool {
        if ($tokenLimit <= 0 || $tokenCount <= $tokenLimit) {
            return false;
        }

        if ($lastActivityAt === null) {
            return false;
        }

        return (($now ?? time()) - $lastActivityAt->getTimestamp()) > self::IDLE_SECONDS;
    }
}
