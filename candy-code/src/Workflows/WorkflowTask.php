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
    ) {}
}
