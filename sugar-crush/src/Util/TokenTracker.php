<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

/**
 * Tracks token usage and cost across API calls.
 * Mirrors upstream provider usage tracking patterns.
 */
final class TokenTracker
{
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private float $totalCost = 0.0;

    /**
     * Add usage from a single API call.
     */
    public function addUsage(int $input, int $output, float $cost): void
    {
        $this->inputTokens += $input;
        $this->outputTokens += $output;
        $this->totalCost += $cost;
    }

    /**
     * Combined token count.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Input token count.
     */
    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Output token count.
     */
    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    /**
     * Total cost in dollars.
     */
    public function totalCost(): float
    {
        return $this->totalCost;
    }

    /**
     * Reset all counters.
     */
    public function reset(): void
    {
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->totalCost = 0.0;
    }

    /**
     * Human-readable summary.
     */
    public function summary(): string
    {
        return sprintf(
            'Tokens: %d in / %d out | Cost: $%.4f',
            $this->inputTokens,
            $this->outputTokens,
            $this->totalCost
        );
    }
}
