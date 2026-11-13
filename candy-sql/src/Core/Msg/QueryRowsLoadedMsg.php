<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when an async query execution completes.
 *
 * Carries the executed SQL label (for history), either the result rows
 * or an error message. Enables the Ctrl+R run-query path to run on the
 * React loop for MySQL/Postgres without blocking the TUI, matching the
 * resilience shape of {@see \SugarCraft\Sql\Core\Msg\TableRowsLoadedMsg}.
 */
final readonly class QueryRowsLoadedMsg implements Msg
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param int $queryGen The QueryState generation this dispatch was issued
     *        under (client-012); the fold drops any reply that does not carry
     *        the current generation, so a superseded in-flight leg can never
     *        settle over a fresher result — text comparison could not see it.
     */
    public function __construct(
        public string $sql,
        public array $rows,
        public ?string $error,
        public int $queryGen,
    ) {}
}
