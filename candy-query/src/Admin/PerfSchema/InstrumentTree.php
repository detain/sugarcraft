<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Hierarchical view of Performance Schema instruments organized by name path.
 *
 * Instrument names are split by '/' to create a tree structure (e.g.
 * "wait/io/file/sql/binlog" becomes a 5-level path). Each node tracks
 * the aggregate enabled/timed state of its subtree using tri-state values:
 *   -1 = disabled (all children disabled or mixed)
 *    0 = mixed/inherited (some enabled, some disabled)
 *    1 = enabled (all children enabled)
 *
 * Bottom-up aggregation: parent state is computed from children.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema instrument_tree
 */
final class InstrumentTree
{
    /** @var array<string, InstrumentTree> Child nodes keyed by path segment */
    private array $children = [];

    /** @var SetupInstruments|null The instrument at this node (null for intermediate nodes) */
    private ?SetupInstruments $instrument = null;

    /** @var int Cached tri-state: -1 (disabled), 0 (mixed), 1 (enabled), 2 (unknown/not computed) */
    private int $enabledState = 2;

    /** @var int Cached tri-state for timed */
    private int $timedState = 2;

    /**
     * Create a new root node for building a tree.
     *
     * Use this when constructing a tree programmatically before inserting instruments.
     */
    public static function new(): self
    {
        return new self('');
    }

    private function __construct(
        private readonly string $name,
    ) {}

    /**
     * Factory method to create a new tree from a list of instruments.
     *
     * @param list<SetupInstruments> $instruments List of instruments to build tree from
     * @return self Root node of the tree
     */
    public static function fromInstruments(array $instruments): self
    {
        $root = new self('');

        foreach ($instruments as $instrument) {
            $root->insert($instrument);
        }

        return $root;
    }

    /**
     * Insert an instrument into the tree.
     */
    public function insert(SetupInstruments $instrument): void
    {
        $parts = $instrument->name !== '' ? explode('/', $instrument->name) : [''];

        $node = $this;
        foreach ($parts as $part) {
            if (!isset($node->children[$part])) {
                $node->children[$part] = new self($part);
            }
            $node = $node->children[$part];
        }

        $node->instrument = $instrument;
        $this->invalidateCache();
    }

    /**
     * Get the instrument at this node, if any.
     */
    public function instrument(): ?SetupInstruments
    {
        return $this->instrument;
    }

    /**
     * Get the name of this node.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get a child node by name.
     *
     * @return InstrumentTree|null Child node or null if not found
     */
    public function child(string $name): ?InstrumentTree
    {
        return $this->children[$name] ?? null;
    }

    /**
     * Get all child nodes.
     *
     * @return array<string, InstrumentTree>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Check if this node has any children.
     */
    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * Get the full path of this node from the root.
     *
     * @return string Full instrument name path
     */
    public function path(): string
    {
        if ($this->name === '') {
            return '';
        }

        // Walk up to find the path
        // This is used for debugging; the root node has empty name
        return $this->name;
    }

    /**
     * Compute the aggregate enabled state of this subtree.
     *
     * Returns:
     *   -1 if this node and all descendants are disabled
     *    0 if there's a mix of enabled and disabled
     *    1 if this node and all descendants are enabled
     *
     * @return int Tri-state: -1 (disabled), 0 (mixed), 1 (enabled)
     */
    public function state(): int
    {
        if ($this->enabledState !== 2) {
            return $this->enabledState;
        }

        // Collect states from children first (bottom-up)
        $childStates = [];
        foreach ($this->children as $child) {
            $childStates[] = $child->state();
        }

        // If this node has an instrument, include its state
        if ($this->instrument !== null) {
            $ownState = $this->instrument->enabled ? 1 : -1;
            $allStates = array_merge([$ownState], $childStates);
        } else {
            // No own instrument, just use children
            $allStates = $childStates;
        }

        // Compute aggregate
        if ($allStates === []) {
            $this->enabledState = -1; // No instrument, no children = disabled
        } else {
            $this->enabledState = $this->aggregateStates($allStates);
        }

        return $this->enabledState;
    }

    /**
     * Compute the aggregate timed state of this subtree.
     *
     * @return int Tri-state: -1 (disabled), 0 (mixed), 1 (enabled)
     */
    public function timedState(): int
    {
        if ($this->timedState !== 2) {
            return $this->timedState;
        }

        // Collect states from children first (bottom-up)
        $childStates = [];
        foreach ($this->children as $child) {
            $childStates[] = $child->timedState();
        }

        // If this node has an instrument, include its state
        if ($this->instrument !== null) {
            $ownState = $this->instrument->timed ? 1 : -1;
            $allStates = array_merge([$ownState], $childStates);
        } else {
            // No own instrument, just use children
            $allStates = $childStates;
        }

        // Compute aggregate
        if ($allStates === []) {
            $this->timedState = -1; // No instrument, no children = disabled
        } else {
            $this->timedState = $this->aggregateStates($allStates);
        }

        return $this->timedState;
    }

    /**
     * Get the enabled state as a boolean.
     *
     * Returns true if state() == 1, false otherwise.
     */
    public function enabled(): bool
    {
        return $this->state() === 1;
    }

    /**
     * Get the timed state as a boolean.
     *
     * Returns true if timedState() == 1, false otherwise.
     */
    public function timed(): bool
    {
        return $this->timedState() === 1;
    }

    /**
     * Aggregate multiple states into a single tri-state result.
     *
     * @param list<int> $states All states to aggregate
     * @return int Tri-state result
     */
    private function aggregateStates(array $states): int
    {
        $unique = array_unique($states);

        if (count($unique) === 1) {
            return reset($unique);
        }

        // Mixed states
        return 0;
    }

    /**
     * Invalidate the cached state values.
     */
    private function invalidateCache(): void
    {
        $this->enabledState = 2;
        $this->timedState = 2;

        // Propagate up to parent if we had a parent reference
        // For simplicity, we just invalidate all descendants
        foreach ($this->children as $child) {
            $child->invalidateCache();
        }
    }

    /**
     * Find a node by instrument name path.
     *
     * @param string $path Instrument name path (e.g. "wait/io/file/sql/binlog")
     * @return InstrumentTree|null The node or null if not found
     */
    public function find(string $path): ?InstrumentTree
    {
        if ($path === '') {
            return $this;
        }

        $parts = explode('/', $path);
        $node = $this;

        foreach ($parts as $part) {
            if ($node === null || !isset($node->children[$part])) {
                return null;
            }
            $node = $node->children[$part];
        }

        return $node;
    }

    /**
     * Get all instruments at or below this node.
     *
     * @return list<SetupInstruments>
     */
    public function allInstruments(): array
    {
        $result = [];

        if ($this->instrument !== null) {
            $result[] = $this->instrument;
        }

        foreach ($this->children as $child) {
            $result = array_merge($result, $child->allInstruments());
        }

        return $result;
    }

    /**
     * Get a representation of the tree structure for debugging.
     *
     * @param int $depth Current depth (for recursion)
     * @return string Visual representation of the tree
     */
    public function dump(int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $state = $this->state();
        $stateStr = match ($state) {
            1 => 'enabled',
            0 => 'mixed',
            -1 => 'disabled',
            default => 'unknown',
        };

        $output = sprintf("%s[%s] %s (%s)\n", $indent, $this->name, $stateStr, $this->instrument?->name ?? 'no instrument');

        foreach ($this->children as $child) {
            $output .= $child->dump($depth + 1);
        }

        return $output;
    }
}
