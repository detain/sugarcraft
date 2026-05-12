<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A hierarchical tree view component.
 *
 * Displays a tree structure with:
 * - Expandable/collapsible nodes
 * - Indentation based on depth level
 * - Branch and end markers
 * - Custom colors for expanded/collapsed states
 *
 * Mirrors the tree concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Tree implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, TreeNode> $nodes
     */
    public function __construct(
        private readonly array $nodes,
        private readonly bool $showLines = true,
        private readonly ?Color $nodeColor = null,
        private readonly ?Color $collapsedColor = null,
        private readonly string $branchChar = '├──',
        private readonly string $endChar = '└──',
        private readonly string $verticalChar = '│  ',
        private readonly string $spaceChar = '   ',
    ) {}

    /**
     * Create a new tree with default styling.
     *
     * Default: purple nodes, shows lines.
     */
    public static function new(array $nodes): self
    {
        return new self(
            nodes: $nodes,
            showLines: true,
            nodeColor: Color::hex('#874BFD'),
            collapsedColor: Color::ansi(8),
            branchChar: '├──',
            endChar: '└──',
            verticalChar: '│  ',
            spaceChar: '   ',
        );
    }

    /**
     * Set the allocated dimensions for this tree.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the tree component.
     */
    public function render(): string
    {
        if (empty($this->nodes)) {
            return '';
        }

        $lines = $this->renderNodes($this->nodes, [], false);

        $result = implode("\n", $lines);

        if ($this->nodeColor !== null || $this->collapsedColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a list of nodes with their ancestors' state.
     *
     * @param array<int, TreeNode> $nodes
     * @param array<bool> $ancestorIsLast Array indicating if each ancestor is the last child
     * @return array<int, string>
     */
    private function renderNodes(array $nodes, array $ancestorIsLast, bool $ancestorCollapsed): array
    {
        if ($ancestorCollapsed) {
            return [];
        }

        $lines = [];
        $nodeCount = count($nodes);

        foreach ($nodes as $index => $node) {
            $isLast = ($index === $nodeCount - 1);

            // Build prefix based on ancestors
            $prefix = $this->buildPrefix($ancestorIsLast, $isLast, $node->collapsed);

            // Render this node
            $nodeStr = $this->renderNode($node, $prefix);
            $lines[] = $nodeStr;

            // Render children if not collapsed
            if (!$node->collapsed && !empty($node->children)) {
                $childAncestorIsLast = [...$ancestorIsLast, $isLast];
                $childLines = $this->renderNodes($node->children, $childAncestorIsLast, false);
                $lines = [...$lines, ...$childLines];
            }
        }

        return $lines;
    }

    /**
     * Build the line prefix for a node based on tree structure.
     */
    private function buildPrefix(array $ancestorIsLast, bool $isLast, bool $collapsed): string
    {
        if (!$this->showLines) {
            return '';
        }

        $prefix = '';
        foreach ($ancestorIsLast as $last) {
            $prefix .= $last ? $this->spaceChar : $this->verticalChar;
        }

        // Add branch or end character
        $prefix .= $isLast ? $this->endChar : $this->branchChar;

        return $prefix;
    }

    /**
     * Render a single tree node.
     */
    private function renderNode(TreeNode $node, string $prefix): string
    {
        $label = $node->label;
        $color = $node->collapsed ? $this->collapsedColor : $this->nodeColor;
        $collapsedIndicator = $node->collapsed ? ' [+]' : '';

        $line = $prefix . $label . $collapsedIndicator;

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this tree.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = 0;
        $totalHeight = 0;

        foreach ($this->nodes as $node) {
            [$w, $h] = $this->measureNode($node, 0);
            if ($w > $maxWidth) {
                $maxWidth = $w;
            }
            $totalHeight += $h;
        }

        return [$maxWidth, $totalHeight];
    }

    /**
     * Measure a node and its children.
     *
     * @return array{0:int,1:int} [width, height]
     */
    private function measureNode(TreeNode $node, int $depth): array
    {
        $indentWidth = $this->showLines ? $depth * 4 : 0;
        $branchWidth = $this->showLines ? 4 : 0;
        $labelWidth = Width::string($node->label);
        $collapsedWidth = $node->collapsed ? 4 : 0; // [+]

        $nodeWidth = $indentWidth + $branchWidth + $labelWidth + $collapsedWidth;
        $height = 1;

        if (!$node->collapsed && !empty($node->children)) {
            foreach ($node->children as $child) {
                [, $childHeight] = $this->measureNode($child, $depth + 1);
                $height += $childHeight;
            }
        }

        return [max($nodeWidth, $indentWidth + $branchWidth), $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Toggle line display.
     */
    public function withShowLines(bool $show): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $show,
            nodeColor: $this->nodeColor,
            collapsedColor: $this->collapsedColor,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
    }

    /**
     * Set the color for expanded nodes.
     */
    public function withNodeColor(?Color $color): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            nodeColor: $color,
            collapsedColor: $this->collapsedColor,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
    }

    /**
     * Set the color for collapsed nodes.
     */
    public function withCollapsedColor(?Color $color): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            nodeColor: $this->nodeColor,
            collapsedColor: $color,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
    }

    /**
     * Set custom branch characters.
     */
    public function withBranchChars(string $branch, string $end): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            nodeColor: $this->nodeColor,
            collapsedColor: $this->collapsedColor,
            branchChar: $branch,
            endChar: $end,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
    }

    /**
     * Toggle a node's collapsed state by label.
     *
     * @param string $label The label of the node to toggle
     */
    public function withNodeCollapsed(string $label, bool $collapsed): self
    {
        $nodes = $this->toggleNodeCollapsed($this->nodes, $label, $collapsed);
        return new self(
            nodes: $nodes,
            showLines: $this->showLines,
            nodeColor: $this->nodeColor,
            collapsedColor: $this->collapsedColor,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
    }

    /**
     * Recursively toggle a node's collapsed state.
     *
     * @param array<int, TreeNode> $nodes
     * @return array<int, TreeNode>
     */
    private function toggleNodeCollapsed(array $nodes, string $label, bool $collapsed): array
    {
        return array_map(function (TreeNode $node) use ($label, $collapsed): TreeNode {
            if ($node->label === $label) {
                return $node->withCollapsed($collapsed);
            }
            if (!empty($node->children)) {
                return $node->withChildren(
                    $this->toggleNodeCollapsed($node->children, $label, $collapsed)
                );
            }
            return $node;
        }, $nodes);
    }
}
