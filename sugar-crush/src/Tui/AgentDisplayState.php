<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Crush\Util\TokenCount;

/**
 * Represents the live display state of a single agent in the status bar.
 *
 * Collected from the agent pool at render time so the status bar can
 * display name, operational status, current task, elapsed wall-clock
 * time, and accumulated token usage without the renderer needing to
 * know about internal agent state.
 */
class AgentDisplayState
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
        /**
         * The agent's CURRENT context — what its latest request carried —
         * as opposed to $tokensUsed, every token it has burned in all. A long
         * run can have spent 2M tokens while sitting at a 200K context, and
         * the two answer different questions. 0 when unknown.
         */
        public int $contextTokens = 0,
    ) {}

    /**
     * Factory method to create a new AgentDisplayState instance.
     *
     * Mirrors SugarCraft\Crush\Tui\AgentDisplayState.__construct.
     */
    public static function new(
        string $name,
        string $status,
        string $operation,
        int $elapsedSeconds,
        int $tokensUsed,
        float $costUsd,
        int $contextTokens = 0,
    ): self {
        return new self($name, $status, $operation, $elapsedSeconds, $tokensUsed, $costUsd, $contextTokens);
    }

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
     * Formatted token + cost summary, e.g. "2.1M tok · 200K ctx | $0.0042":
     * the tokens used in all, the current context when it is known, and the
     * cost only when something was billed. Counts are compact
     * ({@see TokenCount}) — exact figures cost columns nobody reads.
     */
    public function usageDisplay(): string
    {
        $tok = TokenCount::compact($this->tokensUsed) . ' tok';
        if ($this->contextTokens > 0) {
            $tok .= ' · ' . TokenCount::compact($this->contextTokens) . ' ctx';
        }

        // No dollar figure when nothing was billed: a backend with no pricing
        // (a self-hosted SGLang) reports tokens but never cost, and a
        // permanent `| $0.0000` on every row spends columns saying nothing.
        if ($this->costUsd <= 0.0) {
            return $tok;
        }

        return $tok . ' | $' . number_format($this->costUsd, 4);
    }
}
