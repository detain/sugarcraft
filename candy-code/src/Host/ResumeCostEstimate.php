<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Message;

/**
 * The cost shape of re-sending a resumed transcript — C-SES-7.d.
 *
 * A resume (`--resume`, `--continue`, the picker, a deck tab opened on a
 * stored session) hands the model the WHOLE saved history again on the very
 * next prompt. Whether the provider still charges for it uncached depends on
 * one thing the transcript itself cannot say: how long the session has been
 * idle. Anthropic-style prompt caches live about five minutes
 * ({@see CACHE_TTL_SECONDS}); past that, the re-sent tokens are billed as new
 * input, and on a long session that is real money the user never typed a
 * prompt to spend.
 *
 * This class is the whole decision as ONE pure function: it takes the
 * history, a {@see ContextMeter} to size it, the model's input price (or
 * null when the model has no price on file), the session's last-activity
 * instant (or null when no store could say) and the user's warning
 * threshold, and answers either `null` (nothing to warn about) or the
 * estimate behind the warning. No I/O, no clock of its own — the caller
 * passes `now`, so the TTL edge is testable to the second.
 *
 * Two judgement calls, both fail-toward-the-warning by design:
 *  - an UNKNOWN last-activity is treated as cold. The common case for that
 *    is an attached or legacy-store session where no local row names an
 *    instant — and an attach re-sends just as much history as a resume,
 *    cache status genuinely unknown. A spurious notice costs one extra
 *    Enter; a missed one costs a surprise bill.
 *  - an UNPRICED model fires on any stale resume with tokens to send. We
 *    cannot compute what it costs, which is exactly why the notice carries
 *    a `≥` over the threshold instead of an `≈` over a number: the warning
 *    says "this is at least worth your attention", never a fabricated
 *    dollar figure.
 *
 * `resumeCostWarnUsd` at zero (or below) disables the guard outright — the
 * knob is the opt-out, checked before any of the arithmetic.
 */
final class ResumeCostEstimate
{
    /**
     * How long a provider prompt cache is assumed alive: the five-minute
     * default of the Anthropic-compatible cache TTL (`cache_control`
     * breakpoints bill the next hit inside this window as cache reads).
     */
    public const CACHE_TTL_SECONDS = 300;

    /** The shipped default for `resumeCostWarnUsd` — a quarter of a dollar. */
    public const DEFAULT_WARN_USD = 0.25;

    private function __construct(
        /** Calibrated token estimate of the history about to be re-sent. */
        public readonly int $tokens,
        /** What the re-send would bill as fresh input; null = no price on file. */
        public readonly ?float $costUsd,
        /** The threshold that made it worth saying something. */
        public readonly float $warnUsd,
    ) {}

    /**
     * The estimate, or null when there is nothing to warn about.
     *
     * @param list<Message>                  $history        the stored transcript
     * @param ?float                         $costPer1kInput the model's input price; null = no price on file
     * @param \DateTimeImmutable|null        $lastActivityAt session's last activity; null = unknown (treated cold)
     * @param float                          $warnUsd        `resumeCostWarnUsd`; <= 0 disables
     * @param \DateTimeImmutable|null        $now            injectable clock for the TTL edge
     */
    public static function of(
        ContextMeter $meter,
        array $history,
        ?float $costPer1kInput,
        ?\DateTimeImmutable $lastActivityAt,
        float $warnUsd,
        ?float $calibration = null,
        ?\DateTimeImmutable $now = null,
    ): ?self {
        if ($warnUsd <= 0.0) {
            return null;
        }
        $tokens = $meter->estimate($history, $calibration);
        if ($tokens <= 0) {
            return null;
        }
        if ($lastActivityAt !== null) {
            $idleSeconds = ($now ?? new \DateTimeImmutable())->getTimestamp() - $lastActivityAt->getTimestamp();
            if ($idleSeconds < self::CACHE_TTL_SECONDS) {
                return null;
            }
        }
        $costUsd = $costPer1kInput === null ? null : $tokens / 1000.0 * $costPer1kInput;
        if ($costUsd !== null && $costUsd < $warnUsd) {
            return null;
        }

        return new self($tokens, $costUsd, $warnUsd);
    }

    /** True when the model has a price on file (the notice reads `≈`, not `≥`). */
    public function isPriced(): bool
    {
        return $this->costUsd !== null;
    }

    /** The tokens figure as the notice shows it: `84k` for 84_213, `640` under a thousand. */
    public function formatTokens(): string
    {
        if ($this->tokens < 1000) {
            return (string) $this->tokens;
        }

        return (string) max(1, (int) round($this->tokens / 1000)) . 'k';
    }
}
