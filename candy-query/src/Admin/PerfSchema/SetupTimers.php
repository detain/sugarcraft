<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Mutable representation of a Performance Schema timer entry from setup_timers.
 *
 * Timers define the timing source for event measurements. On MySQL <8.0, the
 * setup_timers table controls which timer is used for each event name category.
 * On MySQL >=8.0, timer selection is fixed at server build time.
 *
 * This model represents an entry from the performance_schema.setup_timers table
 * (available on MySQL <8.0 only) and can be modified to change timer assignments.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema setup_timers
 */
final readonly class SetupTimers
{
    use Mutable;

    /**
     * Change type constants for commitStatements().
     */
    public const CHANGE_UPDATE = 'update';
    public const CHANGE_NONE = 'none';

    /**
     * @param string $name        Timer name (e.g. "CYCLE", "NANOSECOND", "MICROSECOND", "MILLISECOND")
     * @param string $timerName   Currently selected timer for this name
     * @param bool   $dirty       Whether this timer has unsaved changes
     * @param string $changeType  Type of change: update, none
     */
    public function __construct(
        public string $name,
        public string $timerName,
        private bool $dirty = false,
        private string $changeType = self::CHANGE_NONE,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        string $name = '',
        string $timerName = '',
    ): self {
        return new self($name, $timerName, false, self::CHANGE_NONE);
    }

    /**
     * Return a new instance with a different timer selected.
     *
     * @param string $timerName New timer name
     * @return static New instance
     */
    public function withTimerName(string $timerName): static
    {
        if ($this->timerName === $timerName) {
            return $this;
        }

        return $this->mutate(['timerName' => $timerName, 'dirty' => true, 'changeType' => self::CHANGE_UPDATE]);
    }

    /**
     * Check if this timer has unsaved changes.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Get the type of change this timer has.
     */
    public function getChangeType(): string
    {
        return $this->changeType;
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

    /**
     * Generate SQL statement(s) to commit changes.
     *
     * Generates UPDATE statement for setup_timers table.
     *
     * @return list<string> SQL statements to execute
     */
    public function commitStatements(): array
    {
        if (!$this->dirty || $this->changeType !== self::CHANGE_UPDATE) {
            return [];
        }

        // Use quote() to safely escape the timer name - both NAME and TIMER_NAME
        // are enum values that come from performance_timers, not user input
        return [
            sprintf(
                'UPDATE `performance_schema`.`setup_timers` SET `TIMER_NAME` = %s WHERE `NAME` = %s',
                $this->quote($this->timerName),
                $this->quote($this->name)
            ),
        ];
    }

    /**
     * Quote a string value for SQL.
     */
    private function quote(string $value): string
    {
        $escaped = str_replace("'", "''", $value);
        return "'" . $escaped . "'";
    }
}
