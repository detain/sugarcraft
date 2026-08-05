<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Commands;

/**
 * Command to cancel the selected agent in the agent view.
 *
 * Mirrors charmbracelet/crush CancelAgentCmd.
 */
final readonly class CancelAgentCmd implements KeyCmd
{
    public function __construct(
        /** Index of the agent to cancel. */
        public int $agentIndex,
    ) {}
}
