<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Agents\Isolation;

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
    ) {}
}
