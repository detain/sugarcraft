<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable representation of a Performance Schema object setup entry.
 *
 * Objects define default instrument settings for specific object types.
 * Can be enabled/disabled at runtime via withEnabled() / withTimed().
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema setup_objects
 */
final readonly class SetupObjects
{
    use Mutable;

    /**
     * Change type constants for commitStatements().
     */
    public const CHANGE_INSERT = 'insert';
    public const CHANGE_UPDATE = 'update';
    public const CHANGE_DELETE = 'delete';
    public const CHANGE_NONE = 'none';

    /**
     * @param string $objectType   Object type (e.g. "EVENT", "FUNCTION", "TABLE")
     * @param string $objectSchema Object schema pattern (e.g. "'%'" for all schemas)
     * @param string $objectName   Object name pattern (e.g. "'%'" for all objects)
     * @param bool   $enabled      Whether instrumentation is currently enabled
     * @param bool   $timed        Whether timing is currently enabled
     * @param bool   $dirty       Whether this object has unsaved changes
     * @param string $changeType  Type of change: insert, update, delete, none
     */
    public function __construct(
        public string $objectType,
        public string $objectSchema,
        public string $objectName,
        public bool $enabled,
        public bool $timed,
        private bool $dirty = false,
        private string $changeType = self::CHANGE_NONE,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        string $objectType = '',
        string $objectSchema = '',
        string $objectName = '',
        bool $enabled = false,
        bool $timed = false,
    ): self {
        return new self($objectType, $objectSchema, $objectName, $enabled, $timed, false, self::CHANGE_NONE);
    }

    /**
     * Return a new instance with the enabled state changed.
     *
     * @param bool $enabled New enabled state
     * @return static New instance
     */
    public function withEnabled(bool $enabled): static
    {
        if ($this->enabled === $enabled) {
            return $this;
        }

        $changeType = match ($this->changeType) {
            self::CHANGE_DELETE => self::CHANGE_DELETE,
            self::CHANGE_INSERT => self::CHANGE_INSERT,
            default => self::CHANGE_UPDATE,
        };

        return $this->mutate(['enabled' => $enabled, 'dirty' => true, 'changeType' => $changeType]);
    }

    /**
     * Return a new instance with the timed state changed.
     *
     * @param bool $timed New timed state
     * @return static New instance
     */
    public function withTimed(bool $timed): static
    {
        if ($this->timed === $timed) {
            return $this;
        }

        $changeType = match ($this->changeType) {
            self::CHANGE_DELETE => self::CHANGE_DELETE,
            self::CHANGE_INSERT => self::CHANGE_INSERT,
            default => self::CHANGE_UPDATE,
        };

        return $this->mutate(['timed' => $timed, 'dirty' => true, 'changeType' => $changeType]);
    }

    /**
     * Mark this object for deletion.
     */
    public function markForDeletion(): static
    {
        if ($this->changeType === self::CHANGE_INSERT) {
            return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_NONE]);
        }

        return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_DELETE]);
    }

    /**
     * Mark this object as a new insertion.
     */
    public function markForInsertion(): static
    {
        if ($this->changeType === self::CHANGE_DELETE) {
            return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_UPDATE]);
        }

        return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_INSERT]);
    }

    /**
     * Check if this object has unsaved changes.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Mark this object as clean (no pending changes).
     */
    public function markClean(): void
    {
        // Immutable - this method exists for API compatibility but has no effect
    }

    /**
     * Return a clean copy of this object.
     */
    public function asClean(): static
    {
        if (!$this->dirty) {
            return $this;
        }

        return new self(
            objectType: $this->objectType,
            objectSchema: $this->objectSchema,
            objectName: $this->objectName,
            enabled: $this->enabled,
            timed: $this->timed,
            dirty: false,
            changeType: self::CHANGE_NONE,
        );
    }

    /**
     * Get the type of change this object has.
     */
    public function getChangeType(): string
    {
        return $this->changeType;
    }

    /**
     * Check if this applies to all schemas.
     */
    public function isGlobalSchema(): bool
    {
        return $this->objectSchema === "'%'" || $this->objectSchema === '%';
    }

    /**
     * Check if this applies to all objects.
     */
    public function isGlobalObject(): bool
    {
        return $this->objectName === "'%'" || $this->objectName === '%';
    }

    /**
     * Check if this is a catch-all entry (matches everything).
     */
    public function isCatchAll(): bool
    {
        return $this->isGlobalSchema() && $this->isGlobalObject();
    }

    /**
     * Generate SQL statement(s) to commit changes.
     *
     * @return list<string> SQL statements to execute
     */
    public function commitStatements(): array
    {
        if (!$this->dirty || $this->changeType === self::CHANGE_NONE) {
            return [];
        }

        // Backtick-escape values for safety
        $escapedType = $this->quote($this->objectType);
        $escapedSchema = $this->quote($this->objectSchema);
        $escapedName = $this->quote($this->objectName);

        return match ($this->changeType) {
            self::CHANGE_INSERT => [
                sprintf(
                    'INSERT INTO `performance_schema`.`setup_objects` (`OBJECT_TYPE`, `OBJECT_SCHEMA`, `OBJECT_NAME`, `ENABLED`, `TIMED`) VALUES (%s, %s, %s, %s, %s)',
                    $escapedType,
                    $escapedSchema,
                    $escapedName,
                    $this->enabled ? "'YES'" : "'NO'",
                    $this->timed ? "'YES'" : "'NO'"
                ),
            ],

            self::CHANGE_UPDATE => [
                sprintf(
                    'UPDATE `performance_schema`.`setup_objects` SET `ENABLED` = %s, `TIMED` = %s WHERE `OBJECT_TYPE` = %s AND `OBJECT_SCHEMA` = %s AND `OBJECT_NAME` = %s',
                    $this->enabled ? "'YES'" : "'NO'",
                    $this->timed ? "'YES'" : "'NO'",
                    $escapedType,
                    $escapedSchema,
                    $escapedName
                ),
            ],

            self::CHANGE_DELETE => [
                sprintf(
                    'DELETE FROM `performance_schema`.`setup_objects` WHERE `OBJECT_TYPE` = %s AND `OBJECT_SCHEMA` = %s AND `OBJECT_NAME` = %s',
                    $escapedType,
                    $escapedSchema,
                    $escapedName
                ),
            ],

            default => [],
        };
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
