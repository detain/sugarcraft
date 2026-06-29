<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when an async query execution completes.
 *
 * Carries the executed SQL label (for history), either the result rows
 * or an error message. Enables the Ctrl+R run-query path to run on the
 * React loop for MySQL/Postgres without blocking the TUI, matching the
 * resilience shape of {@see \SugarCraft\Query\Core\Msg\TableRowsLoadedMsg}.
 */
final readonly class QueryRowsLoadedMsg implements Msg
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(
        public string $sql,
        public array $rows,
        public ?string $error = null,
    ) {}
}
