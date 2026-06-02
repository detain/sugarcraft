<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Immutable representation of a Performance Schema timer.
 *
 * Timers define the timing source and scale factor for event measurements.
 * This is a read-only model as timers are determined by the server build.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema performance_timers
 */
final readonly class SetupTimers
{
    /**
     * @param string $name        Timer name (e.g. "CYCLE", "NANOSECOND", "MICROSECOND", "MILLISECOND")
     * @param string $timerName   Timer specification (implementation-specific)
     * @param float  $scaleFactor Scale factor for converting timer units to picoseconds
     */
    public function __construct(
        public string $name,
        public string $timerName,
        public float $scaleFactor,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        string $name = '',
        string $timerName = '',
        float $scaleFactor = 1.0,
    ): self {
        return new self($name, $timerName, $scaleFactor);
    }

    /**
     * Get the timer name in uppercase.
     */
    public function nameUpper(): string
    {
        return strtoupper($this->name);
    }

    /**
     * Check if this is the CYCLE timer (uses CPU cycles).
     */
    public function isCycle(): bool
    {
        return strtoupper($this->name) === 'CYCLE';
    }
}
