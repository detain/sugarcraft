<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Immutable snapshot of SHOW GLOBAL STATUS at a point in time.
 *
 * Used by the Calc engine to compute rates via delta/elapsed.
 */
final readonly class StatusSnapshot
{
    /**
     * @param array<string, string> $variables  Key-value pairs from SHOW GLOBAL STATUS
     * @param float $ts                          Unix timestamp when snapshot was taken
     */
    public function __construct(
        public array $variables,
        public float $ts,
    ) {}

    /**
     * Get a variable value or null if not present.
     */
    public function get(string $key): ?string
    {
        return $this->variables[$key] ?? null;
    }

    /**
     * Get a variable as int, or null if not present or not numeric.
     */
    public function getInt(string $key): ?int
    {
        $v = $this->variables[$key] ?? null;
        return $v !== null && is_numeric($v) ? (int) $v : null;
    }

    /**
     * Get a variable as float, or null if not present or not numeric.
     */
    public function getFloat(string $key): ?float
    {
        $v = $this->variables[$key] ?? null;
        return $v !== null && is_numeric($v) ? (float) $v : null;
    }

    /**
     * Check if a variable exists in this snapshot.
     */
    public function has(string $key): bool
    {
        return isset($this->variables[$key]);
    }

    /**
     * Compute elapsed time between this snapshot and another.
     */
    public function elapsedSince(StatusSnapshot $older): float
    {
        return $this->ts - $older->ts;
    }

    /**
     * Create a delta snapshot (current - previous) for numeric values.
     *
     * @return array<string, float>
     */
    public function delta(StatusSnapshot $previous): array
    {
        $out = [];
        foreach ($this->variables as $k => $v) {
            $oldVal = $previous->variables[$k] ?? null;
            if ($oldVal === null) {
                continue;
            }
            $newNum = is_numeric($v) ? (float) $v : null;
            $oldNum = is_numeric($oldVal) ? (float) $oldVal : null;
            if ($newNum !== null && $oldNum !== null) {
                $out[$k] = $newNum - $oldNum;
            }
        }
        return $out;
    }
}
