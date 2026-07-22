<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tree;

use SugarCraft\Sprinkles\Lang;
use SugarCraft\Sprinkles\Style;

/**
 * Renders a hierarchical tree using box-drawing connectors.
 *
 * ```php
 * echo Tree::new()
 *     ->root('Documents')
 *     ->child(
 *         Tree::new()
 *             ->root('Travel')
 *             ->child('Italy.md')
 *             ->child('Japan.md'),
 *     )
 *     ->child('Resume.pdf')
 *     ->render();
 * ```
 *
 * ```text
 * Documents
 * ├── Travel
 * │   ├── Italy.md
 * │   └── Japan.md
 * └── Resume.pdf
 * ```
 *
 * Children are either strings (leaves) or nested {@see Tree} instances.
 *
 * Customisation:
 *  - {@see enumerator()} swaps the connector characters (default,
 *    rounded, ascii — see {@see Enumerator}).
 *  - {@see indenter()} replaces the continuation prefixes ("│   " /
 *    "    ") for tighter or wider indentation.
 *  - {@see rootStyle()} / {@see itemStyle()} / {@see enumeratorStyle()}
 *    apply lipgloss-style colour overrides on root / leaves / connectors.
 *  - {@see hide()} suppresses the root from rendering — useful when
 *    composing trees as siblings under a parent root.
 */
final class Tree
{
    private string $root = '';
    /** @var list<Tree|string> */
    private array $children = [];
    private bool $hidden = false;
    private ?Enumerator $enumerator = null;
    private ?Style $rootStyle = null;
    private ?Style $itemStyle = null;
    private ?Style $enumeratorStyle = null;
    private ?\Closure $indenter = null;
    private int $offsetStart = 0;
    private int $offsetEnd   = 0;

    public static function new(): self
    {
        return new self();
    }

    /**
     * Named-ctor variant of `Tree::new()->root($label)`. Mirrors
     * lipgloss's `tree.Root(label)` package-level constructor.
     */
    public static function rootOf(string $label): self
    {
        return self::new()->root($label);
    }

    public function root(string $r): self
    {
        $clone = clone $this;
        $clone->root = $r;
        return $clone;
    }

    /** Read-only accessor for the configured root label. */
    public function value(): string { return $this->root; }

    public function child(self|string $c): self
    {
        $clone = clone $this;
        $clone->children = [...$this->children, $c];
        return $clone;
    }

    /**
     * Render only children in the half-open `[start, end)` range. Pass
     * `0` for either bound to mean "no clamp on that end" — i.e.
     * `offset(2, 0)` skips the first two children and renders the rest;
     * `offset(0, 5)` renders only the first five. Mirrors lipgloss's
     * `Tree::Offset(start, end)` / `OffsetStart` / `OffsetEnd`.
     */
    public function offset(int $start, int $end): self
    {
        if ($start < 0 || $end < 0) {
            throw new \InvalidArgumentException(Lang::t('tree.offset_nonneg'));
        }
        $clone = clone $this;
        $clone->offsetStart = $start;
        $clone->offsetEnd   = $end;
        return $clone;
    }

    /** Convenience: drop the first `$start` children. */
    public function offsetStart(int $start): self
    {
        return $this->offset($start, $this->offsetEnd);
    }

    /** Convenience: keep only the first `$end` children. */
    public function offsetEnd(int $end): self
    {
        return $this->offset($this->offsetStart, $end);
    }

    public function children(self|string ...$c): self
    {
        $clone = clone $this;
        foreach ($c as $entry) {
            $clone->children[] = $entry;
        }
        return $clone;
    }

    /**
     * Hide this tree's root in the output. Useful when nesting trees
     * as siblings under a single parent — the inner tree's root is
     * already supplied by the parent.
     */
    public function hide(bool $on = true): self
    {
        $c = clone $this;
        $c->hidden = $on;
        return $c;
    }

    /**
     * Swap the connector character set. Pass `Enumerator::default()`
     * (├──/└──), `Enumerator::rounded()` (├──/╰──), or
     * `Enumerator::ascii()` (|--/`--).
     */
    public function enumerator(Enumerator $e): self
    {
        $c = clone $this;
        $c->enumerator = $e;
        return $c;
    }

    /**
     * Replace the continuation indenter. `$fn(bool $isLast): string`
     * receives whether the current branch is the last child and
     * returns the continuation prefix used for descendant lines.
     * Default: `'│   '` for non-last and `'    '` for last.
     *
     * @param ?\Closure(bool $isLast): string $fn
     */
    public function indenter(?\Closure $fn): self
    {
        $c = clone $this;
        $c->indenter = $fn;
        return $c;
    }

    public function rootStyle(?Style $s): self       { $c = clone $this; $c->rootStyle = $s;       return $c; }
    public function itemStyle(?Style $s): self       { $c = clone $this; $c->itemStyle = $s;       return $c; }
    public function enumeratorStyle(?Style $s): self { $c = clone $this; $c->enumeratorStyle = $s; return $c; }

    public function render(): string
    {
        return implode("\n", $this->renderLines());
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /** @return list<string> */
    private function renderLines(): array
    {
        $lines = [];
        $enum = $this->enumerator ?? Enumerator::default();
        if (!$this->hidden && $this->root !== '') {
            $lines[] = $this->rootStyle !== null
                ? $this->rootStyle->render($this->root)
                : $this->root;
        }
        // Apply Offset(start, end) — half-open. End=0 means "no upper
        // clamp"; start=0 means "no lower clamp" (already implicit).
        $children = $this->children;
        $end = $this->offsetEnd > 0 ? $this->offsetEnd : count($children);
        if ($this->offsetStart > 0 || $end < count($children)) {
            $children = array_slice(
                $children,
                $this->offsetStart,
                max(0, $end - $this->offsetStart),
            );
        }
        $count = count($children);
        foreach ($children as $i => $child) {
            $isLast = $i === $count - 1;
            $branchRaw = $isLast ? $enum->lastBranch : $enum->branch;
            $contRaw   = $this->indenter !== null
                ? ($this->indenter)($isLast)
                : ($isLast ? $enum->lastIndent : $enum->indent);

            $branch = $this->enumeratorStyle !== null
                ? $this->enumeratorStyle->render($branchRaw)
                : $branchRaw;

            if ($child instanceof self) {
                // Inherit unset styles + enumerator from parent so a leaf's
                // own configuration takes precedence.
                $resolved = $child;
                if ($resolved->enumerator === null && $this->enumerator !== null) {
                    $resolved = $resolved->enumerator($this->enumerator);
                }
                if ($resolved->indenter === null && $this->indenter !== null) {
                    $resolved = $resolved->indenter($this->indenter);
                }
                if ($resolved->rootStyle === null && $this->itemStyle !== null) {
                    $resolved = $resolved->rootStyle($this->itemStyle);
                }
                if ($resolved->itemStyle === null && $this->itemStyle !== null) {
                    $resolved = $resolved->itemStyle($this->itemStyle);
                }
                if ($resolved->enumeratorStyle === null && $this->enumeratorStyle !== null) {
                    $resolved = $resolved->enumeratorStyle($this->enumeratorStyle);
                }
                $childLines = $resolved->renderLines();
                if ($childLines === []) {
                    continue;
                }
                $lines[] = $branch . $childLines[0];
                $childLineCount = count($childLines);
                for ($j = 1; $j < $childLineCount; $j++) {
                    $lines[] = $contRaw . $childLines[$j];
                }
                continue;
            }
            $leafText = $this->itemStyle !== null
                ? $this->itemStyle->render($child)
                : $child;
            $leafLines = explode("\n", $leafText);
            $lines[] = $branch . $leafLines[0];
            $leafLineCount = count($leafLines);
            for ($j = 1; $j < $leafLineCount; $j++) {
                $lines[] = $contRaw . $leafLines[$j];
            }
        }
        return $lines;
    }
}
