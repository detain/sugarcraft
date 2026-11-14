<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

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
final class Tree implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    /** Painted after a collapsed node's label; shared by render and measure (D-140). */
    private const COLLAPSED_INDICATOR = ' [+]';

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
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
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
        $collapsedIndicator = $node->collapsed ? self::COLLAPSED_INDICATOR : '';

        $line = $prefix . $label . $collapsedIndicator;

        if ($color !== null) {
            return $color->toFg(ColorMode::profile()) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this tree.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $maxWidth = 0;
        $totalHeight = 0;

        $nodeCount = count($this->nodes);
        foreach ($this->nodes as $index => $node) {
            [$w, $h] = $this->measureNode($node, 0, $index === $nodeCount - 1);
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
     * Indent is computed from the CONFIGURED branch drawing (D-140), not
     * a hardcoded cell count: the ancestor column contributes
     * vertical/space glyphs and the node line contributes branch/end
     * glyphs, each measured with Width::string. The old depth*4+4 shape
     * disagreed with the shipped 3-cell default drawing by one cell and
     * ignored custom branch chars entirely.
     *
     * @return array{0:int,1:int} [width, height]
     */
    private function measureNode(TreeNode $node, int $ancestorWidth, bool $isLast): array
    {
        $prefixWidth = $this->showLines
            ? $ancestorWidth + Width::string($isLast ? $this->endChar : $this->branchChar)
            : 0;
        $labelWidth = Width::string($node->label);
        $collapsedWidth = $node->collapsed ? Width::string(self::COLLAPSED_INDICATOR) : 0;

        $nodeWidth = $prefixWidth + $labelWidth + $collapsedWidth;
        $height = 1;

        if (!$node->collapsed && !empty($node->children)) {
            $childAncestorWidth = $this->showLines
                ? $ancestorWidth + Width::string($isLast ? $this->spaceChar : $this->verticalChar)
                : 0;
            $childCount = count($node->children);
            foreach ($node->children as $index => $child) {
                [$childWidth, $childHeight] = $this->measureNode($child, $childAncestorWidth, $index === $childCount - 1);
                $height += $childHeight;
                if ($childWidth > $nodeWidth) {
                    $nodeWidth = $childWidth;
                }
            }
        }

        return [$nodeWidth, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Toggle line display.
     */
    public function withShowLines(bool $show): self
    {
        // D-17: the allocation lives in setSize-owned fields outside
        // the ctor; rebuilds must re-apply it or the box is forgotten.
        $carried = new self(
            nodes: $this->nodes,
            showLines: $show,
            nodeColor: $this->nodeColor,
            collapsedColor: $this->collapsedColor,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
        if ($this->width !== null && $this->height !== null) {
            return $carried->setSize($this->width, $this->height);
        }
        return $carried;
    }

    /**
     * Set the color for expanded nodes.
     */
    public function withNodeColor(?Color $color): self
    {
        $carried = new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            nodeColor: $color,
            collapsedColor: $this->collapsedColor,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
        if ($this->width !== null && $this->height !== null) {
            return $carried->setSize($this->width, $this->height);
        }
        return $carried;
    }

    /**
     * Set the color for collapsed nodes.
     */
    public function withCollapsedColor(?Color $color): self
    {
        $carried = new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            nodeColor: $this->nodeColor,
            collapsedColor: $color,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
        if ($this->width !== null && $this->height !== null) {
            return $carried->setSize($this->width, $this->height);
        }
        return $carried;
    }

    /**
     * Set custom branch characters.
     */
    public function withBranchChars(string $branch, string $end): self
    {
        $carried = new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            nodeColor: $this->nodeColor,
            collapsedColor: $this->collapsedColor,
            branchChar: $branch,
            endChar: $end,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
        if ($this->width !== null && $this->height !== null) {
            return $carried->setSize($this->width, $this->height);
        }
        return $carried;
    }

    /**
     * Toggle a node's collapsed state.
     *
     * The key is the node's ID (D-140): `TreeNode::$id` finally drives
     * the lookup, so two 'Details' nodes toggle independently instead of
     * in lockstep. Nodes created without an id (TreeNode::new) keep the
     * legacy label-keyed contract as a documented fallback: pass the
     * label and every node carrying it toggles, as before.
     *
     * @param string $key A TreeNode id, or a label for id-less legacy nodes
     */
    public function withNodeCollapsed(string $key, bool $collapsed): self
    {
        $nodes = $this->toggleNodeCollapsed($this->nodes, $key, $collapsed);
        $carried = new self(
            nodes: $nodes,
            showLines: $this->showLines,
            nodeColor: $this->nodeColor,
            collapsedColor: $this->collapsedColor,
            branchChar: $this->branchChar,
            endChar: $this->endChar,
            verticalChar: $this->verticalChar,
            spaceChar: $this->spaceChar,
        );
        if ($this->width !== null && $this->height !== null) {
            return $carried->setSize($this->width, $this->height);
        }
        return $carried;
    }

    /**
     * Recursively toggle a node's collapsed state.
     *
     * @param array<int, TreeNode> $nodes
     * @return array<int, TreeNode>
     */
    private function toggleNodeCollapsed(array $nodes, string $key, bool $collapsed): array
    {
        return array_map(function (TreeNode $node) use ($key, $collapsed): TreeNode {
            if ($this->nodeCarries($node, $key)) {
                return $node->withCollapsed($collapsed);
            }
            if (!empty($node->children)) {
                return $node->withChildren(
                    $this->toggleNodeCollapsed($node->children, $key, $collapsed)
                );
            }
            return $node;
        }, $nodes);
    }

    /**
     * Does this node answer to the toggle key?
     *
     * An id is the natural key (unique per provider contract); the label
     * path exists so id-less TreeNode::new() call sites keep working —
     * all nodes sharing that label answer, which is the legacy behavior.
     */
    private function nodeCarries(TreeNode $node, string $key): bool
    {
        if ($node->id !== '') {
            return $node->id === $key;
        }
        return $node->label === $key;
    }
}