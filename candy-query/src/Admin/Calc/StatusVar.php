<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Looks up a raw status variable value from the current snapshot.
 *
 * Mirrors the CRawValue expression from MySQL Workbench's dashboard calc
 * engine, which evaluates a single status variable key and returns its
 * current value (not a rate).
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard CRawValue
 */
final class StatusVar
{
    public function __construct(
        public readonly string $key,
    ) {}

    /**
     * Look up the current value of the status variable.
     *
     * @param array<string, string> $current Current status variables snapshot
     * @param array<string, string> $previous Previous status variables snapshot (unused for raw lookups)
     * @param float $elapsed Seconds elapsed (unused for raw lookups)
     * @return string The raw value, or empty string if not found
     */
    public function compute(array $current, array $previous, float $elapsed): string
    {
        return $current[$this->key] ?? '';
    }

    /**
     * Get the raw value as an integer.
     *
     * @param array<string, string> $current
     * @return int
     */
    public function computeInt(array $current, array $previous, float $elapsed): int
    {
        return (int) ($current[$this->key] ?? '0');
    }

    /**
     * Get the raw value as a float.
     *
     * @param array<string, string> $current
     * @return float
     */
    public function computeFloat(array $current, array $previous, float $elapsed): float
    {
        return (float) ($current[$this->key] ?? '0');
    }

    /**
     * Check if the key exists in the current snapshot.
     *
     * @param array<string, string> $current
     * @return bool
     */
    public function exists(array $current): bool
    {
        return isset($current[$this->key]);
    }
}
