<?php

declare(strict_types=1);

namespace SugarCraft\Query;

/**
 * A single parsed row from EXPLAIN output.
 *
 * @readonly
 */
final class ExplainRow
{
    public function __construct(
        public readonly string $detail,
        public readonly int $depth,
        public readonly string $tag,
        public readonly string $indent,
        public readonly int $line,
    ) {}
}
