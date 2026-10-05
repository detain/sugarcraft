<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * A session's provider-reported spend against its cap: recording a call's
 * usage, the one definition of "over budget", the refusal and mid-turn
 * notices, and the `/budget` command's logic (roadmap O-2c, Appendix O §4.2
 * "Spend accounting").
 *
 * WHY IT LEFT {@see Chat}. None of it needs a screen, and a host that runs
 * sessions headless must enforce the same cap with the same words — a refusal
 * worded differently by the TUI and the server would read as two features.
 * Chat delegates every decision and every sentence here and keeps its own
 * method names as thin delegates, because tests and docs cite them.
 *
 * STATELESS BY DESIGN, for the same reason as {@see ContextMeter}: one ledger
 * is registered per workspace ({@see WorkspaceContext::service()}) and serves
 * every session in it, so the session's {@see TokenTracker} and its cap are
 * handed in. The tracker is the only thing written, and it is written by
 * {@see account()} alone.
 *
 * DOLLARS, NOT TOKENS. Everything here is what the provider said it billed;
 * {@see ContextMeter} is a script-weighted estimate of what was sent. The two
 * are different units and are never combined — see {@see Usage}.
 */
final class SpendLedger
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * What crossed the cap when a freshly submitted prompt is refused: a
     * previous turn, which was allowed to finish. The other refusal site (a
     * turn the 85% tier parked) names its own crossing.
     */
    public static function crossedByPreviousTurn(): string
    {
        return Lang::t('host.spend.crossed_by_previous_turn');
    }

    /**
     * The session's provider-reported spend in US dollars. Exactly 0.0 is
     * BOTH "nothing was reported" and "this provider is free", which is why a
     * readout keys off {@see hasReported()} rather than off this being
     * positive.
     */
    public function spent(TokenTracker $tracker): float
    {
        return $tracker->totalCost();
    }

    /**
     * Whether any call this session actually reported usage. A READOUT
     * concern only: the cap decision ({@see capReached()}) gets the same
     * fail-open from arithmetic — an unreported session's spend is `0.0` and a
     * cap is always positive — not from consulting this.
     */
    public function hasReported(TokenTracker $tracker): bool
    {
        return $tracker->totalTokens() > 0 || $tracker->totalCost() > 0.0;
    }

    /**
     * Put one provider call's reported usage on the session tracker, or do
     * nothing when the provider reported none — a null $usage is "unknown",
     * which must not become a zero-dollar call.
     *
     * `addTotalUsage()`, not `addUsage()`: the figure crossing every seam that
     * reaches here is a TOTAL with no input/output split. A 0-cost call from
     * an UNPRICED model is a different claim from a free one, and the tracker
     * remembers that the session's figure is a lower bound from then on.
     */
    public function account(TokenTracker $tracker, ?Usage $usage): void
    {
        if ($usage === null) {
            return;
        }

        $tracker->addTotalUsage($usage->totalTokens, $usage->costUsd);

        if ($usage->unpricedModel !== null) {
            $tracker->noteUnpricedUsage();
        }
    }

    /**
     * Whether a session has reached its cap — the one definition of "over
     * budget" every enforcement site asks. False whenever there is no cap,
     * and false for a session nothing was reported for (spend `0.0` against a
     * positive cap): the deliberate fail-OPEN that makes a cap a budget guard
     * rather than a security control.
     */
    public function capReached(TokenTracker $tracker, ?float $cap): bool
    {
        return $cap !== null && $this->spent($tracker) >= $cap;
    }

    /**
     * Whether $cap is a spend ceiling this app will act on: a positive, finite
     * number of dollars. `0` and negatives are REFUSED rather than read as "no
     * cap" (opposite intentions), and non-finite is refused because
     * `(float) '1e309'` is `INF`, which every comparison treats as no cap at
     * all — and `NAN` compares false both ways.
     */
    public static function isUsableCap(float $cap): bool
    {
        return is_finite($cap) && $cap > 0.0;
    }

    /**
     * The notice a turn refused at the cap carries: the state, what crossed
     * it ($crossing), and the three ways out.
     */
    public function refusalNotice(TokenTracker $tracker, float $cap, string $crossing): string
    {
        $spent = $this->spent($tracker);

        return Lang::t('host.spend.refused', [
            'spent' => sprintf('%.4f', $spent),
            'cap' => sprintf('%.4f', $cap),
            'crossing' => $crossing,
            'raise' => sprintf('%.2f', $spent * 2),
        ]);
    }

    /**
     * The notice E20's mid-turn abort puts on the transcript. A DIFFERENT
     * promise from {@see refusalNotice()}'s: that one refused to start and
     * nothing was billed; this one says a call already happened and the loop
     * stopped before the next.
     */
    public function midTurnNotice(SpendCapBreached $event): string
    {
        return Lang::t('host.spend.mid_turn', [
            'calls' => $event->completedCalls,
            'spent' => sprintf('%.4f', $event->spentUsd),
            'cap' => sprintf('%.4f', $event->capUsd),
        ]);
    }

    /**
     * Where a session stands, in the two units it can honestly report. Says
     * "not reported" rather than `$0.0000` when nothing has arrived, and says
     * LOWER BOUND once any call came from a model with no price on file.
     */
    public function statusLine(TokenTracker $tracker, ?float $cap): string
    {
        $capText = $cap === null
            ? Lang::t('host.spend.no_cap')
            : Lang::t('host.spend.cap', ['cap' => sprintf('%.4f', $cap)]);

        if (!$this->hasReported($tracker)) {
            return Lang::t('host.spend.status_unreported', ['cap' => $capText]);
        }

        return Lang::t('host.spend.status', [
            'spent' => sprintf('%.4f', $this->spent($tracker)),
            'cap' => $capText,
            'summary' => $tracker->summary(),
        ]) . ($tracker->hasUnpricedUsage() ? Lang::t('host.spend.lower_bound') : '');
    }

    /**
     * `/budget <argument>` decided: the answer to print and what happens to
     * the cap. Three forms — bare shows the status, `off`/`none`/`clear`
     * clears, an amount (a leading `$` accepted) sets — and anything that is
     * not a usable cap ({@see isUsableCap()}) gets the usage line with the cap
     * left alone.
     *
     * `cap` non-null means "set it"; `clearCap` means "remove it"; neither
     * leaves it as it was. The caller owns the session state and applies it.
     *
     * @return array{response: string, cap: ?float, clearCap: bool}
     */
    public function budgetReply(string $argument, TokenTracker $tracker, ?float $currentCap): array
    {
        if ($argument === '') {
            return ['response' => $this->statusLine($tracker, $currentCap), 'cap' => null, 'clearCap' => false];
        }

        if (in_array(strtolower($argument), ['off', 'none', 'clear'], true)) {
            return [
                'response' => Lang::t($currentCap === null ? 'host.spend.no_cap_was_set' : 'host.spend.cleared')
                    . $this->statusLine($tracker, $currentCap),
                'cap' => null,
                'clearCap' => true,
            ];
        }

        // A leading `$` is what a human types; stripped before the numeric
        // test so `$5` and `5` mean the same. is_numeric() alone is not
        // enough: `1e309` is numeric and casts to INF.
        $amount = ltrim($argument, '$');
        if (!is_numeric($amount) || !self::isUsableCap((float) $amount)) {
            return [
                'response' => Lang::t('host.spend.usage'),
                'cap' => null,
                'clearCap' => false,
            ];
        }

        $cap = (float) $amount;

        return [
            'response' => Lang::t('host.spend.set', ['cap' => sprintf('%.4f', $cap)]) . $this->statusLine($tracker, $cap),
            'cap' => $cap,
            'clearCap' => false,
        ];
    }
}
