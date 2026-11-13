<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

/**
 * One completion answer: what is being completed and what it can become.
 *
 * `kind` is `keyword|table|column|qualified|none` (none: caret inside a
 * string literal or comment, where completing would corrupt the text).
 * `prefix` is the already-typed text the insertion REPLACES (codepoint
 * length is what the caller deletes); `candidates` are the spellings to
 * insert — identifiers already quoted when their spelling needs it.
 */
final class Completion
{
    /**
     * @param list<string> $candidates
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $prefix,
        public readonly array $candidates,
        public readonly string $qualifier = '',
        public readonly bool $inQuote = false,
        public readonly string $closeQuote = '',
    ) {}
}
