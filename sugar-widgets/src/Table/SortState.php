<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Table;

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
        return $this->withCriteria($criteria);
    }

    /**
     * The construction funnel (W-32): copies of an existing state route
     * through here; `new self(` lives in the ctor, empty(), and this one
     * method only.
     */
    private function withCriteria(array $criteria): self
    {
        return new self($criteria);
    }

    /**
     * Return a new SortState with no sort criteria.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Drop criteria whose column index is out of range for a table that now
     * has $columnCount columns (M4: setHeaders()/setColumns() replace the
     * header vector, and a stale index silently sorts the wrong column — or
     * none — once the table shrinks and later widens again).
     */
    public function withoutColumnsBeyond(int $columnCount): self
    {
        $kept = array_values(array_filter(
            $this->criteria,
            static fn(array $c): bool => $c[0] < $columnCount,
        ));
        return count($kept) === count($this->criteria) ? $this : $this->withCriteria($kept);
    }

    public function isEmpty(): bool
    {
        return $this->criteria === [];
    }
}
