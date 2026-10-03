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
        /** Stall warning when the agent's output has stalled, null otherwise. */
        public ?StallWarning $stallWarning = null,
        /**
         * Lines the agent has produced in all, when that is more than
         * $outputBuffer holds — the buffer is a bounded tail, so counting it
         * would pin an "N more lines" figure at the tail's size forever.
         * Null means the buffer is the whole story.
         */
        public ?int $totalLines = null,
        /** See {@see AgentDisplayState::$contextTokens}. */
        int $contextTokens = 0,
        /**
         * The run this row shows ({@see \SugarCraft\Crush\Agents\SubAgent::$id}),
         * so a click can name it; null for a row that is not one run (a
         * per-agent roll-up, a background session).
         */
        public ?string $key = null,
    ) {
        parent::__construct(
            name: $name,
            status: $status,
            operation: $operation,
            elapsedSeconds: $elapsedSeconds,
            tokensUsed: $tokensUsed,
            costUsd: $costUsd,
            contextTokens: $contextTokens,
        );
    }

    /**
     * Lines produced in all: never fewer than the buffer holds.
     */
    public function lineCount(): int
    {
        return max(count($this->outputBuffer), $this->totalLines ?? 0);
    }

    /**
     * Wrap an existing AgentDisplayState with output-specific fields.
     */
    public static function fromDisplayState(AgentDisplayState $display, string $model, array $outputBuffer = [], ?StallWarning $stallWarning = null, ?int $totalLines = null, ?string $key = null): self
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
            stallWarning: $stallWarning,
            totalLines: $totalLines,
            contextTokens: $display->contextTokens,
            key: $key,
        );
    }
}
