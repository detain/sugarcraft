<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

/**
 * "The agentic loop stopped between provider calls because the session's
 * spend reached its cap" — emitted by
 * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()} when E20's
 * mid-turn check fires, on the SAME frame channel {@see ToolStarted} and
 * {@see ToolFinished} use, because the transcript can only place this fact
 * correctly in the turn's story if it arrives IN that story's order.
 *
 * Exists because the pre-E20 cap could only refuse a turn BEFORE it started
 * ({@see \SugarCraft\Crush\Chat::spendCapRefusal()}), while a turn is up to
 * `maxSteps` billed provider calls: the turn that crossed the cap ran all
 * eight and the session overshot by a whole turn, silently. This event is the
 * mid-turn half — the loop refuses the NEXT call, not the one already paid
 * for, and says so.
 *
 * THE MESSAGE MUST KEEP THE TWO GUARANTEES APART. "Refused to start" (the
 * pre-flight refusal) and "aborted mid-turn" (this event) are different
 * promises to the user: the first means nothing was billed, the second means
 * something WAS billed and further billing stopped. A renderer or a test that
 * conflates them re-reports the exact confusion E20 exists to end.
 *
 * A breach report, never a control signal: the loop has already broken by the
 * time this is emitted, and — like every other event on the channel — a
 * consumer that ignores it costs the turn nothing. The usage the calls that
 * DID run accumulated rides the settled Message as always; this event only
 * names where the loop decided to stop.
 */
final readonly class SpendCapBreached
{
    /**
     * @param int   $completedCalls provider calls this turn had ALREADY made
     *                              when the loop stopped (1-based — the call
     *                              refused would have been number
     *                              $completedCalls + 1).
     * @param float $spentUsd       session spend at the breach: the session
     *                              total the turn started with, plus every
     *                              step of THIS turn the provider billed.
     * @param float $capUsd         the cap that was reached, as the user set
     *                              it via `$SUGARCRUSH_MAX_COST` / `/budget`.
     */
    public function __construct(
        public int $completedCalls,
        public float $spentUsd,
        public float $capUsd,
    ) {}
}
