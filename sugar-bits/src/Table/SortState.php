<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Table;

/**
 * Immutable list of sort criteria — each entry is a (column index, direction) pair.
 * Applied in order: first entry is primary sort, second is tiebreaker, etc.
 */
final readonly class SortState
{
    /**
     * @param list<array{0: int, 1: SortDirection}> $criteria
     */
    public function __construct(
        public array $criteria = [],
    ) {}

    /**
     * Add a new sort criterion at the end of the chain.
     */
    public function withCriterion(int $column, SortDirection $direction): self
    {
        $criteria = $this->criteria;
        $criteria[] = [$column, $direction];
        return new self($criteria);
    }

    /**
     * Return a new SortState with no sort criteria.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->criteria === [];
    }
}
