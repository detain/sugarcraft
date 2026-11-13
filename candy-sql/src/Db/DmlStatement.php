<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * One parameterised write the in-grid editor is about to run (SQL-O03).
 *
 * `sql` carries `?` placeholders only — every VALUE travels in `params` and
 * is bound, never spliced. `display` is the same statement with the values
 * rendered as literals for the confirmation line: it is shown to the human,
 * NEVER executed, so its quoting only has to be honest, not injection-proof.
 */
final class DmlStatement
{
    public const UPDATE = 'update';
    public const INSERT = 'insert';
    public const DELETE = 'delete';

    /**
     * @param list<int|float|string|null> $params
     * @param int  $expect    Rows the statement must touch to commit.
     * @param bool $allowZero Whether 0 touched rows is a benign outcome
     *                        (an UPDATE writing the value already there reports 0 on MySQL).
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $sql,
        public readonly array $params,
        public readonly string $display,
        public readonly int $expect = 1,
        public readonly bool $allowZero = false,
    ) {}
}
