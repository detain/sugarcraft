<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Represents the live display state of a single agent in the status bar.
 *
 * Collected from the agent pool at render time so the status bar can
 * display name, operational status, current task, elapsed wall-clock
 * time, and accumulated token usage without the renderer needing to
 * know about internal agent state.
 */
final readonly class AgentDisplayState
{
    public function __construct(
        /** Agent name or role label, e.g. "coder-1", "reviewer-2". */
        public string $name,
        /** Human-readable operational status string shown in the bar. */
        public string $status,
        /** Short description of what the agent is currently doing. */
        public string $operation,
        /** Wall-clock seconds since the agent was started. */
        public int $elapsedSeconds,
        /** Total input + output tokens consumed so far. */
        public int $tokensUsed,
        /** Total cost in USD so far. */
        public float $costUsd,
    ) {}

    /**
     * Formatted elapsed time string, e.g. "1m 23s" or "2m".
     */
    public function elapsedDisplay(): string
    {
        if ($this->elapsedSeconds < 60) {
            return $this->elapsedSeconds . 's';
        }

        $minutes = (int) floor($this->elapsedSeconds / 60);
        $seconds = $this->elapsedSeconds % 60;

        if ($seconds === 0) {
            return $minutes . 'm';
        }

        return $minutes . 'm ' . $seconds . 's';
    }

    /**
     * Formatted token + cost summary, e.g. "1,234 tok | $0.0042".
     */
    public function usageDisplay(): string
    {
        $tok = number_format($this->tokensUsed);
        $cost = number_format($this->costUsd, 4);

        return "{$tok} tok | \${$cost}";
    }
}
