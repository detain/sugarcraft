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
    ) {}
}
