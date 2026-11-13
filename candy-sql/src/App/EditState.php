<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Sql\Db\DmlStatement;

/**
 * The in-grid editing slice of the {@see \SugarCraft\Sql\App} model (SQL-O03).
 *
 * Stages: `closed` -> `cell` (typing a new value for one cell) or `insert`
 * (walking the columns of a new row) -> `confirm` (the exact statement is
 * on the status line awaiting Enter). A delete skips straight to `confirm`.
 * While a stage other than `closed` is active the edit line owns the
 * keyboard, the same law as the SQL-O07 file line and the SQL-O10 filter.
 */
final class EditState
{
    use Mutable;

    public const STAGE_CLOSED = 'closed';
    public const STAGE_CELL = 'cell';
    public const STAGE_INSERT = 'insert';
    public const STAGE_CONFIRM = 'confirm';

    /**
     * @param array<string, mixed>   $key           primary key of the row being edited/deleted
     * @param list<string>           $insertColumns columns an insert walk prompts, in order
     * @param array<string, string>  $insertTypes   column => declared type (prompt hint)
     * @param array<string, ?string> $values        collected insert values (absent = DEFAULT)
     */
    public function __construct(
        public readonly string $stage = self::STAGE_CLOSED,
        public readonly string $table = '',
        public readonly string $column = '',
        public readonly array $key = [],
        public readonly string $buffer = '',
        public readonly bool $isNull = false,
        public readonly ?string $original = null,
        public readonly array $insertColumns = [],
        public readonly array $insertTypes = [],
        public readonly int $insertIndex = 0,
        public readonly array $values = [],
        public readonly ?DmlStatement $statement = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /** Whether an edit line is open and should own the keyboard. */
    public function isOpen(): bool
    {
        return $this->stage !== self::STAGE_CLOSED;
    }

    public function closed(): self
    {
        return new self();
    }

    public function withStage(string $stage): self
    {
        return $this->mutate(['stage' => $stage]);
    }

    /** Replace the typed value; typing always leaves the NULL toggle. */
    public function withBuffer(string $buffer): self
    {
        return $this->mutate(['buffer' => $buffer, 'isNull' => false]);
    }

    public function withNull(bool $isNull): self
    {
        return $this->mutate(['isNull' => $isNull, 'buffer' => $isNull ? '' : $this->buffer]);
    }

    public function withStatement(?DmlStatement $statement): self
    {
        return $this->mutate(['statement' => $statement]);
    }

    /** @param array<string, ?string> $values */
    public function withValues(array $values): self
    {
        return $this->mutate(['values' => $values]);
    }

    public function withInsertIndex(int $insertIndex): self
    {
        return $this->mutate(['insertIndex' => $insertIndex]);
    }
}
