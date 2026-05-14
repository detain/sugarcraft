<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Current sort state of the grid.
 */
final class SortState
{
    public function __construct(
        public readonly ?string $key = null,
        public readonly SortDirection $direction = SortDirection::Asc,
    ) {}

    /**
     * Toggle direction if same key, otherwise reset to Asc.
     */
    public function toggle(?string $key): self
    {
        if ($key === null) {
            return new self(key: null, direction: SortDirection::Asc);
        }

        if ($this->key === $key) {
            return new self(
                key: $key,
                direction: $this->direction === SortDirection::Asc
                    ? SortDirection::Desc
                    : SortDirection::Asc,
            );
        }

        return new self(key: $key, direction: SortDirection::Asc);
    }

    public function isSortedBy(string $key): bool
    {
        return $this->key === $key;
    }
}
