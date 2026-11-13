<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tree;

use SugarCraft\Widgets\Lang;

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
    ) {
        // The 2-arg new Node($label, $children) footgun: a subtree (or any
        // iterable of nodes) parked in the payload slot renders FLAT and
        // silent — the classic misuse the leaf()/branch() factories exist
        // to prevent, now refused at construction (W-44/069). A plain
        // scalar/array payload the caller actually means still stores.
        if (
            $value instanceof \Traversable
            || (is_array($value) && $value !== [] && array_is_list($value) && reset($value) instanceof self)
        ) {
            throw new \InvalidArgumentException(Lang::t('tree.use_branch_for_children'));
        }
    }

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
        return $this->copy(children: $this->children, expanded: $on);
    }

    /**
     * The construction funnel (W-32): every copy of an existing node
     * routes through here, so `new self(` lives in the ctor, the
     * factories, and this one method only.
     */
    private function copy(array $children, bool $expanded): self
    {
        return new self($this->label, $this->value, $children, $expanded);
    }

    /**
     * @param list<self> $children
     * @return self     New node carrying the new child list.
     */
    public function withChildren(array $children): self
    {
        return $this->copy(array_values($children), $this->expanded);
    }

    /** True iff this node has no children (a leaf). */
    public function isLeaf(): bool
    {
        return $this->children === [];
    }
}
