<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Immutable representation of a Performance Schema thread.
 *
 * Threads represent server threads or connections. This is a read-only
 * model as threads cannot be modified directly - they are derived from
 * the server's internal thread table.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema threads
 */
final readonly class SetupThreads
{
    /**
     * @param int         $threadId          Unique thread identifier
     * @param string      $name              Thread name (e.g. "thread/sql/main")
     * @param string      $type              Thread type (e.g. "FOREGROUND", "BACKGROUND")
     * @param int|null    $processlistId     Processlist ID (connection thread ID)
     * @param string|null $processlistUser   User from the processlist
     * @param string|null $processlistCommand Current command being executed
     * @param string|null $processlistInfo  Current SQL statement being executed
     */
    public function __construct(
        public int $threadId,
        public string $name,
        public string $type,
        public ?int $processlistId = null,
        public ?string $processlistUser = null,
        public ?string $processlistCommand = null,
        public ?string $processlistInfo = null,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        int $threadId = 0,
        string $name = '',
        string $type = 'FOREGROUND',
        ?int $processlistId = null,
        ?string $processlistUser = null,
        ?string $processlistCommand = null,
        ?string $processlistInfo = null,
    ): self {
        return new self(
            $threadId,
            $name,
            $type,
            $processlistId,
            $processlistUser,
            $processlistCommand,
            $processlistInfo,
        );
    }

    /**
     * Check if this is a foreground thread (user connection).
     */
    public function isForeground(): bool
    {
        return $this->type === 'FOREGROUND';
    }

    /**
     * Check if this is a background thread (internal server thread).
     */
    public function isBackground(): bool
    {
        return $this->type === 'BACKGROUND';
    }

    /**
     * Check if this thread has an active processlist entry.
     */
    public function hasProcesslist(): bool
    {
        return $this->processlistId !== null;
    }

    /**
     * Generate SQL statement(s) to commit changes.
     *
     * Threads are read-only - they cannot be modified directly.
     *
     * @return list<string> Empty array - no statements generated
     */
    public function commitStatements(): array
    {
        return [];
    }
}
