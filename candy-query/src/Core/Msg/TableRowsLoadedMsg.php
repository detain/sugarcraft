<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when an async table-row browse fetch completes.
 *
 * Carries the table it was for so a result that arrives after the user has
 * already cursored onto a different table can be discarded as stale, plus
 * either the fetched rows or an error message. Only emitted on the async
 * (MySQL/Postgres) browse path; SQLite loads rows synchronously.
 */
final readonly class TableRowsLoadedMsg implements Msg
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(
        public string $table,
        public array $rows,
        public ?string $error = null,
    ) {}
}
