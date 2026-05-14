<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Column definition for data grid tables.
 *
 * Defines a single column's header, width, alignment, and sort/filter behavior.
 */
final class Column
{
    public function __construct(
        private readonly string $header = '',
        private readonly int $width = 10,
        private readonly ?string $key = null,
    ) {}

    public static function new(string $header = '', int $width = 10, ?string $key = null): self
    {
        return new self(header: $header, width: $width, key: $key);
    }

    public function getHeader(): string
    {
        return $this->header;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getKey(): ?string
    {
        return $this->key ?? $this->header;
    }
}
