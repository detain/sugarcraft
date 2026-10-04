<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * How large one step's request may grow before the engine treats it as under
 * pressure (roadmap 2.1, the deepseek-harness rule `min(0.8·W, W − maxOut −
 * 64k)`).
 *
 * Chat's tiers (70% / 85% / 95%) judge the conversation once per prompt, at
 * submit. A turn is up to `maxSteps` provider calls, and every tool result it
 * reads lands in the NEXT call's request, so the request that overflows is
 * usually one the user never submitted. This is the per-step budget
 * {@see \SugarCraft\Crush\Backend\EngineBackend}'s loop measures each request
 * against just before sending it ({@see ContextPressure}).
 *
 * THE TWO TERMS. 80% of the window keeps headroom on every model; the second
 * term keeps room for the reply itself (the configured `maxOutputTokens`) and a
 * reserve for the tool output a step can still add. The reserve is the
 * harness's 64k, capped at a fifth of the window: on a 128k window with a 32k
 * output ceiling the literal formula leaves 32k — a quarter of the window,
 * which would call almost every real turn over budget — while the cap keeps it
 * at 70k. On a 1M window the reserve is the full 64k and 80% is the smaller
 * term, which is the case the rule was written for.
 *
 * A window too small for the second term to stay positive (an output ceiling
 * at or past the window) falls back to the 80% term rather than to a budget of
 * zero, which would report every request over budget.
 *
 * AN ABSOLUTE CAP (roadmap 2.9) can sit beside the two terms: the automatic
 * compaction tier's `backgroundCompactionTokens` from
 * {@see CompactorConfig}, so a 1M window configured to compact at 100k also
 * prunes and summarises a turn at 100k rather than at 800k. Unset — the
 * default, until the settings decision N-P4b makes one — it changes nothing.
 * The threshold is the one place the three meet.
 */
final readonly class ContextBudget
{
    /** The share of the window a step's request may use. */
    public const WINDOW_SHARE = 0.8;

    /** The reserve for tool output a step can still add (deepseek-harness: 64k). */
    public const RESERVE_TOKENS = 65_536;

    /** The reserve never exceeds this fraction of the window (1/5). */
    public const RESERVE_WINDOW_DIVISOR = 5;

    private function __construct(
        /** The model's context window, in tokens (≥ 1). */
        public int $window,
        /** The configured per-request output ceiling, or null when none is set. */
        public ?int $maxOutputTokens,
        /** The absolute cap in tokens, or null when none is configured. */
        public ?int $absoluteTokens = null,
    ) {
    }

    /**
     * @param int  $window          the model's window as its provider reports
     *                              it; a non-positive report ("unknown") takes
     *                              {@see ContextWindow::FALLBACK_TOKENS}, the
     *                              figure every other tier falls back to
     * @param ?int $maxOutputTokens `maxOutputTokens` from the user config, or
     *                              null; a non-positive value counts as none
     * @param ?int $absoluteTokens  an absolute cap on the budget
     *                              ({@see forCompactor()}), or null; a
     *                              non-positive value counts as none
     */
    public static function new(int $window, ?int $maxOutputTokens = null, ?int $absoluteTokens = null): self
    {
        return new self(
            ContextWindow::resolve($window),
            $maxOutputTokens !== null && $maxOutputTokens > 0 ? $maxOutputTokens : null,
            $absoluteTokens !== null && $absoluteTokens > 0 ? $absoluteTokens : null,
        );
    }

    /**
     * The budget with $config's automatic-compaction cap as its absolute term
     * — the config already resolved for the model ({@see CompactorConfig::forModel()}).
     */
    public static function forCompactor(int $window, ?int $maxOutputTokens, CompactorConfig $config): self
    {
        return self::new($window, $maxOutputTokens, $config->backgroundCompactionTokens);
    }

    /** The token count at or above which a step's request is over budget. */
    public function threshold(): int
    {
        $share = (int) floor($this->window * self::WINDOW_SHARE);
        $reserve = min(self::RESERVE_TOKENS, intdiv($this->window, self::RESERVE_WINDOW_DIVISOR));
        $headroom = $this->window - ($this->maxOutputTokens ?? 0) - $reserve;

        $threshold = $headroom > 0 ? min($share, $headroom) : $share;
        if ($this->absoluteTokens !== null) {
            $threshold = min($threshold, $this->absoluteTokens);
        }

        return max(1, $threshold);
    }
}
