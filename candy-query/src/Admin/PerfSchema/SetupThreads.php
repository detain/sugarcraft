<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Mutable representation of a Performance Schema thread.
 *
 * Threads represent server threads or connections. This model tracks
 * the INSTRUMENTED flag which can be toggled via update statements.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema threads
 */
final readonly class SetupThreads
{
    use Mutable;

    /**
     * @param int         $threadId          Unique thread identifier
     * @param string      $name              Thread name (e.g. "thread/sql/main")
     * @param string      $type              Thread type (e.g. "FOREGROUND", "BACKGROUND")
     * @param int|null    $processlistId     Processlist ID (connection thread ID)
     * @param string|null $processlistUser   User from the processlist
     * @param string|null $processlistCommand Current command being executed
     * @param string|null $processlistInfo  Current SQL statement being executed
     * @param bool        $instrumented      Whether this thread is instrumented
     * @param bool        $dirty             Whether this thread has unsaved changes
     */
    public function __construct(
        public int $threadId,
        public string $name,
        public string $type,
        public ?int $processlistId = null,
        public ?string $processlistUser = null,
        public ?string $processlistCommand = null,
        public ?string $processlistInfo = null,
        public bool $instrumented = true,
        private bool $dirty = false,
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
        bool $instrumented = true,
    ): self {
        return new self(
            $threadId,
            $name,
            $type,
            $processlistId,
            $processlistUser,
            $processlistCommand,
            $processlistInfo,
            $instrumented,
            false,
        );
    }

    /**
     * Return a new instance with the instrumented state changed.
     *
     * @param bool $instrumented New instrumented state
     * @return static New instance
     */
    public function withInstrumented(bool $instrumented): static
    {
        if ($this->instrumented === $instrumented) {
            return $this;
        }

        return $this->mutate(['instrumented' => $instrumented, 'dirty' => true]);
    }

    /**
     * Check if this thread has unsaved changes.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
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
     * Generates UPDATE statement for threads INSTRUMENTED flag.
     * Note: the actual batch UPDATE with IN() clause is handled by CommitPlanner.
     *
     * @return list<string> SQL statements to execute
     */
    public function commitStatements(): array
    {
        // Individual thread doesn't generate its own statement;
        // CommitPlanner handles the batch IN() update.
        return [];
    }

    /**
     * Generate a partial SQL fragment for this thread's INSTRUMENTED update.
     *
     * Used by CommitPlanner to build the IN() clause.
     *
     * @return string SQL fragment: THREAD_ID = {id} AND INSTRUMENTED = {value}
     */
    public function instrumentedFragment(): string
    {
        $enabled = $this->instrumented ? "'YES'" : "'NO'";
        return sprintf('`THREAD_ID` = %d AND `INSTRUMENTED` = %s', $this->threadId, $enabled);
    }
}
