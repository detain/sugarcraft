<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tree;

/**
 * A single node in a {@see Tree}.
 *
 * Immutable. Use {@see withExpanded()} / {@see withChildren()} to
 * derive new nodes when state changes; the parent {@see Tree} keeps
 * the canonical list and re-derives ancestors as needed.
 */
final class Node
{
    /**
     * @param string                $label    Visible label (single-line).
     * @param mixed                 $value    Caller-defined payload returned by {@see Tree::selectedValue()}.
     * @param list<self>            $children Child nodes (may be empty for leaves).
     * @param bool                  $expanded Children visible when true. Default true so simple flat trees Just Work.
     */
    public function __construct(
        public readonly string $label,
        public readonly mixed $value = null,
        public readonly array $children = [],
        public readonly bool $expanded = true,
    ) {}

    /** Convenience factory — same shape as the constructor with a friendlier name. */
    public static function leaf(string $label, mixed $value = null): self
    {
        return new self($label, $value);
    }

    /**
     * Convenience factory for branch nodes — variadic children.
     *
     * @param self ...$children
     */
    public static function branch(string $label, self ...$children): self
    {
        return new self($label, null, array_values($children));
    }

    /** @return self with expansion flipped or set to `$on`. */
    public function withExpanded(bool $on): self
    {
        return new self($this->label, $this->value, $this->children, $on);
    }

    /**
     * @param list<self> $children
     * @return self     New node carrying the new child list.
     */
    public function withChildren(array $children): self
    {
        return new self($this->label, $this->value, array_values($children), $this->expanded);
    }

    /** True iff this node has no children (a leaf). */
    public function isLeaf(): bool
    {
        return $this->children === [];
    }
}
