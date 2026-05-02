<?php

declare(strict_types=1);

namespace CandyCore\Gloss\Tree;

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
 */
final class Tree
{
    private string $root = '';
    /** @var list<Tree|string> */
    private array $children = [];

    public static function new(): self
    {
        return new self();
    }

    public function root(string $r): self
    {
        $clone = clone $this;
        $clone->root = $r;
        return $clone;
    }

    public function child(self|string $c): self
    {
        $clone = clone $this;
        $clone->children = [...$this->children, $c];
        return $clone;
    }

    public function children(self|string ...$c): self
    {
        $clone = clone $this;
        foreach ($c as $entry) {
            $clone->children[] = $entry;
        }
        return $clone;
    }

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
        if ($this->root !== '') {
            $lines[] = $this->root;
        }
        $count = count($this->children);
        foreach ($this->children as $i => $child) {
            $isLast = $i === $count - 1;
            $branch = $isLast ? '└── ' : '├── ';
            $cont   = $isLast ? '    ' : '│   ';

            if ($child instanceof self) {
                $childLines = $child->renderLines();
                if ($childLines === []) {
                    continue;
                }
                $lines[] = $branch . $childLines[0];
                for ($j = 1; $j < count($childLines); $j++) {
                    $lines[] = $cont . $childLines[$j];
                }
                continue;
            }
            // Leaf string — may itself be multi-line.
            $leafLines = explode("\n", $child);
            $lines[] = $branch . $leafLines[0];
            for ($j = 1; $j < count($leafLines); $j++) {
                $lines[] = $cont . $leafLines[$j];
            }
        }
        return $lines;
    }
}
