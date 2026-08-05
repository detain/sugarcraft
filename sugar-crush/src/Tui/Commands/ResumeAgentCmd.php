<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Commands;

/**
 * Command to resume a stalled agent in the agent view.
 *
 * Mirrors charmbracelet/crush ResumeAgentCmd.
 */
final readonly class ResumeAgentCmd implements KeyCmd
{
    public function __construct(
        /** Index of the agent to resume. */
        public int $agentIndex,
    ) {}
}
