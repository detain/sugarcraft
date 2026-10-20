<?php

declare(strict_types=1);

namespace SugarCraft\Code\Goal;

use SugarCraft\Code\Backend;
use SugarCraft\Code\Lang;

/**
 * The one decision table of the goal loop: given the live goal and what its
 * judge came back with, what happens next. Extracted for W7a-3 so the
 * terminal (`Chat::landGoalVerdict()`) and the served host
 * (`SessionHost::landGoalVerdict()`) run the SAME round budget and stop
 * conditions — a fork here would let one surface keep grinding after the
 * other had stopped.
 *
 * Pure by construction: the verdict is already parsed (a null verdict is
 * already a failure carrying its own reason string), and the outcome names
 * rows and prompts, never effects. Each owner applies them through its own
 * machinery — Chat's immutable mutate, SessionHost's transcript appends —
 * which is exactly the seam shape the two hosts have always shared for
 * commands.
 */
final class GoalLoop
{
    /** The judge answered nothing usable and the reason is already prose. */
    public const STOP_NO_VERDICT = 'no-verdict';

    /** The goal condition was met. */
    public const END_MET = 'met';

    /** The follow-up budget is spent without a met verdict. */
    public const END_NOT_MET = 'not-met';

    /** Something ended the loop short: a failed judge call, a refused round. */
    public const END_CHECK_STOPPED = 'check-stopped';

    /** Send one more follow-up round. */
    public const CONTINUE = 'continue';

    /**
     * The pre-judge doors: the loop cannot run without a judge engine, and
     * a capped session stops spending here as it does at every other gate.
     * A non-null answer is the reason, already translated, for
     * {@see self::stopped()}.
     */
    public static function refusalReason(?Backend $judge, bool $spendCapReached): ?string
    {
        return match (true) {
            $judge === null => Lang::t('chat.goal.stop.no_title_model'),
            $spendCapReached => Lang::t('chat.goal.stop.spend_cap'),
            default => null,
        };
    }

    /**
     * Judge answered with no usable verdict: the loop stops and says why —
     * the transport error if there was one, the bare no-verdict sentence
     * otherwise.
     */
    public static function stopped(GoalState $goal, ?string $error): self
    {
        return new self(
            kind: self::END_CHECK_STOPPED,
            goal: $goal,
            status: GoalState::STOPPED,
            notice: Lang::t('chat.goal.check_stopped', [
                'reason' => $error ?? Lang::t('chat.goal.stop.no_verdict'),
                'command' => $goal->mode->command(),
            ]),
        );
    }

    /**
     * One entry, the whole table: what $goal does next given $verdict.
     *
     * Order is load-bearing and mirrors both surfaces' history: a met verdict
     * wins over a spent budget, a spent budget ends the loop with the judge's
     * still-missing list, and only a live, unspent, unfinished goal continues.
     */
    public static function afterVerdict(GoalState $goal, GoalVerdict $verdict): self
    {
        $score = $verdict->score === null ? '' : ' (' . $verdict->scoreLabel() . ')';

        if ($verdict->complete) {
            return new self(
                kind: self::END_MET,
                goal: $goal,
                status: GoalState::MET,
                notice: Lang::t('chat.goal.met', ['score' => $score, 'condition' => $goal->condition]),
            );
        }

        if ($goal->exhausted()) {
            return new self(
                kind: self::END_NOT_MET,
                goal: $goal,
                status: GoalState::STOPPED,
                notice: Lang::t('chat.goal.not_met', [
                    'rounds' => $goal->round,
                    'score' => $score,
                    'condition' => $goal->condition,
                    'missing' => $verdict->missing === []
                        ? ''
                        : Lang::t('chat.goal.still_missing', ['missing' => implode('; ', $verdict->missing)]),
                    'command' => $goal->mode->command(),
                ]),
            );
        }

        return new self(
            kind: self::CONTINUE,
            goal: $goal,
            status: GoalState::ACTIVE,
            notice: '',
            next: $goal->withRound($goal->round + 1),
            nextPrompt: GoalJudge::followupPrompt($goal->withRound($goal->round + 1), $verdict),
        );
    }

    public function __construct(
        public readonly string $kind,
        public readonly GoalState $goal,
        public readonly string $status,
        public readonly string $notice,
        public readonly ?GoalState $next = null,
        public readonly string $nextPrompt = '',
    ) {
    }
}
