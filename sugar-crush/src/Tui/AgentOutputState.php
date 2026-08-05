<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Represents the live display state of a single agent's streaming output.
 *
 * Extends AgentDisplayState with fields specific to the per-agent output pane:
 * model name and the live output buffer (list of lines received so far).
 *
 * Collected from the agent pool at render time so AgentOutputPane can render
 * the live response buffer without the renderer needing to know about internal
 * agent state.
 */
final class AgentOutputState extends AgentDisplayState
{
    public function __construct(
        /** Agent name or role label, e.g. "coder-1", "reviewer-2". */
        public string $name,
        /** Human-readable operational status string. */
        public string $status,
        /** Short description of what the agent is currently doing. */
        public string $operation,
        /** Wall-clock seconds since the agent was started. */
        public int $elapsedSeconds,
        /** Total input + output tokens consumed so far. */
        public int $tokensUsed,
        /** Total cost in USD so far. */
        public float $costUsd,
        /** Model name being used, e.g. "claude-sonnet-4-6". */
        public string $model,
        /** Live output buffer: lines of text received so far from the agent. */
        public array $outputBuffer,
    ) {
        parent::__construct(
            name: $name,
            status: $status,
            operation: $operation,
            elapsedSeconds: $elapsedSeconds,
            tokensUsed: $tokensUsed,
            costUsd: $costUsd,
        );
    }

    /**
     * Wrap an existing AgentDisplayState with output-specific fields.
     */
    public static function fromDisplayState(AgentDisplayState $display, string $model, array $outputBuffer = []): self
    {
        return new self(
            name: $display->name,
            status: $display->status,
            operation: $display->operation,
            elapsedSeconds: $display->elapsedSeconds,
            tokensUsed: $display->tokensUsed,
            costUsd: $display->costUsd,
            model: $model,
            outputBuffer: $outputBuffer,
        );
    }
}
