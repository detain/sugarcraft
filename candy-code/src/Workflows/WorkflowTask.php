<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Agents\Isolation;

/**
 * Readonly DTO representing a fully-assembled workflow task.
 *
 * Produced by TaskBuilder::build(); holds all parameters needed
 * to schedule and run a single agent task within a workflow.
 */
final readonly class WorkflowTask
{
    public function __construct(
        public string $agentType,
        public string $prompt,
        public array $tools = [],
        public ?int $timeout = null,
        public ?int $retries = null,
        public ?Isolation $isolation = null,
        public ?string $name = null,
        // G3: decoded-trusted JSON-schema document constraining this task's terminal turn; null = free-text report.
        public ?array $outputSchema = null,
        // S2: the model this task asked to run on; null keeps the session's own — the ladder that judges a non-null ask lives in Agents\ModelChoice.
        public ?string $model = null,
        /**
         * Verdict rejection, for a verification stage's verifier slot only:
         * a PCRE pattern whose MATCH against the verifier's completed output
         * rejects the verdict — the stage fails as if the agent had. Null
         * (the default) keeps the status-only behavior byte-identical.
         * Validity is enforced at {@see TaskBuilder::reject()} set time; a
         * hand-built malformed pattern still fails CLOSED at the scan.
         */
        public ?string $reject = null,
        /**
         * Occurrence threshold paired with {@see $reject}: the verdict is
         * rejected only when the pattern matches AT LEAST this many times.
         * The default 1 is the match-once behavior byte-identical; a
         * hand-built value below 1 fails CLOSED at the scan (a rule that can
         * never read clean must not read as one). Only consulted when
         * {@see $reject} is set.
         */
        public int $rejectMinOccurrences = 1,
        /**
         * O3 host-resource serialization: the name of the exclusive lock this
         * task's STAGE holds while it dispatches (e.g. 'gpu'). Null (the
         * default) takes no lock at all — byte-identical to every pre-O3
         * workflow. The lock is per stage: tasks in one stage that name the
         * same resource acquire it once, distinct names are held together,
         * taken in sorted name order.
         */
        public ?string $resource = null,
        /**
         * How many seconds a stage may WAIT for {@see $resource} before the
         * stage fails loudly (0 gives up at once). Null falls to the engine's
         * default bound. Only consulted when {@see $resource} is set;
         * validity is enforced at {@see TaskBuilder} set time and by the YAML
         * loader — a hand-built negative can only ever tighten the wait to an
         * immediate loud refusal, never widen it.
         */
        public ?int $lockWaitSeconds = null,
        /**
         * C7 (Option A): seconds a forked agent may publish NOTHING before
         * the pool expires it as stalled — a re-armable idle deadline beside
         * the absolute {@see $timeout}, re-seeded every time progress bytes
         * grow. Null (the default) arms nothing: behavior byte-identical to
         * every pre-C7 workflow. The inline (non-forked) path never reads it
         * — only pcntl_fork workers publish progress.
         */
        public ?int $stallTimeout = null,
        /**
         * How many times a STALLED task may be re-dispatched (C7), counted
         * ONLY against stall expiries — {@see $retries} never shares this
         * counter and vice versa. The default 0 disables stall restarts: a
         * stall then settles in exactly today's plain-timeout shape.
         */
        public int $restarts = 0,
        /**
         * C9 fan-in join: a normalized `array{from: list<string>, as: string}`
         * naming PRIOR stages whose recorded rows this task's prompt receives
         * under the fixed `input` handle. {@see TaskBuilder::trustedJoin()}
         * validates the shape at parse time — by the time a DTO exists the
         * spec is trusted, and null means no join at all (behavior
         * byte-identical to every pre-C9 workflow). The engine builds the
         * payload from the recorded rows immediately before prompt
         * interpolation, into a task-local copy of the context that is never
         * persisted, so a resume re-derives byte-identical bytes.
         */
        public ?array $input = null,
        /**
         * P1-4 verifier panel: how many INDEPENDENT dispatches of this
         * verifier judge the task (concurrently, sharing the stage budget).
         * 1 (the default) is the single-verifier path, byte-identical to
         * every pre-P1-4 stage. Verifier slot only — the engine refuses any
         * other placement before anything runs, like {@see $reject}.
         */
        public int $votes = 1,
        /**
         * P1-4: one lens name per vote (`count === votes`), interpolated as
         * `{{lens}}` into that vote's prompt. Null = no lenses (`{{lens}}`
         * stays verbatim). {@see TaskBuilder::verifierPanel()} holds the
         * shape law.
         */
        public ?array $lenses = null,
        /**
         * P1-4: how many votes must NOT reject for the verdict to pass,
         * 1..votes. Null = the majority, {@see TaskBuilder::defaultQuorum()}.
         */
        public ?int $quorum = null,
    ) {}

    /**
     * P1-4: whether this task declares a verifier panel at all — any of
     * votes/lenses/quorum away from its default. Only a declared panel
     * takes the panel path; an undeclared verifier stays byte-identical.
     */
    public function declaresPanel(): bool
    {
        return $this->votes !== 1 || $this->lenses !== null || $this->quorum !== null;
    }

    /** P1-4: the quorum this panel is judged by (the declared one, or the majority). */
    public function effectiveQuorum(): int
    {
        return $this->quorum ?? TaskBuilder::defaultQuorum($this->votes);
    }
}
