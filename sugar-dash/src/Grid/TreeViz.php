<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A tree visualization component for displaying hierarchical data.
 *
 * Features:
 * - Node-based tree structure
 * - Customizable node styling
 * - Expand/collapse support (via rendering options)
 * - Connection lines between nodes
 * - Multiple layout orientations
 *
 * Mirrors tree/dendrogram patterns adapted to PHP with wither-style immutable setters.
 */
final class TreeViz implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<TreeVizNode> $nodes
     */
    public function __construct(
        private readonly array $nodes = [],
        private readonly bool $showLines = true,
        private readonly bool $showLabels = true,
        private readonly ?Color $nodeColor = null,
        private readonly ?Color $lineColor = null,
        private readonly ?Color $labelColor = null,
        private readonly string $orientation = 'top-down',
    ) {}

    /**
     * Create a new tree visualization.
     *
     * @param list<TreeVizNode> $nodes
     */
    public static function new(array $nodes = []): self
    {
        return new self(
            nodes: $nodes,
            showLines: true,
            showLabels: true,
            nodeColor: Color::hex('#89B4FA'),
            lineColor: Color::hex('#45475A'),
            labelColor: Color::hex('#CDD6F4'),
            orientation: 'top-down',
        );
    }

    /**
     * Create a sample tree for demonstration.
     */
    public static function sample(): self
    {
        $leaf1 = new TreeVizNode('Leaf A', 'leaf');
        $leaf2 = new TreeVizNode('Leaf B', 'leaf');
        $leaf3 = new TreeVizNode('Leaf C', 'leaf');
        $branch1 = new TreeVizNode('Branch 1', 'branch', [$leaf1, $leaf2]);
        $branch2 = new TreeVizNode('Branch 2', 'branch', [$leaf3]);

        return self::new([new TreeVizNode('Root', 'root', [$branch1, $branch2])]);
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
     * Render the tree.
     */
    public function render(): string
    {
        if (empty($this->nodes)) {
            return '';
        }

        $result = '';

        foreach ($this->nodes as $node) {
            $result .= $this->renderNode($node, 0, '', true);
        }

        return rtrim($result, "\n");
    }

    /**
     * Render a single node and its children.
     *
     * @param TreeVizNode $node
     */
    private function renderNode(TreeVizNode $node, int $depth, string $prefix, bool $isLast): string
    {
        $result = '';
        $nodeColor = $this->nodeColor ?? Color::hex('#89B4FA');
        $lineColor = $this->lineColor ?? Color::hex('#45475A');
        $labelColor = $this->labelColor ?? Color::hex('#CDD6F4');

        // Connector line to parent
        if ($depth > 0) {
            if ($this->showLines) {
                $result .= $lineColor->toFg(ColorProfile::TrueColor);
                $result .= $isLast ? '└── ' : '├── ';
                $result .= Ansi::reset();
            }
        }

        // Node indicator
        if ($this->showLabels) {
            $nodeChar = match ($node->type) {
                'root' => '●',
                'branch' => '○',
                'leaf' => '•',
                default => '○',
            };
            $result .= $nodeColor->toFg(ColorProfile::TrueColor);
            $result .= $nodeChar . ' ';
            $result .= Ansi::reset();

            // Label
            $result .= $labelColor->toFg(ColorProfile::TrueColor);
            $result .= $node->label;
            $result .= Ansi::reset();
        } else {
            $result .= $nodeColor->toFg(ColorProfile::TrueColor);
            $result .= match ($node->type) {
                'root' => '● ' . $node->label,
                'branch' => '○ ' . $node->label,
                'leaf' => '• ' . $node->label,
                default => '○ ' . $node->label,
            };
            $result .= Ansi::reset();
        }

        $result .= "\n";

        // Render children
        if (!empty($node->children)) {
            $childPrefix = $prefix;
            if ($depth > 0) {
                $childPrefix .= $isLast ? '    ' : '│   ';
            }

            foreach ($node->children as $index => $child) {
                $isLastChild = $index === count($node->children) - 1;
                $result .= $childPrefix;
                $result .= $this->renderNode($child, $depth + 1, $childPrefix, $isLastChild);
            }
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this tree.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->nodes)) {
            return [0, 0];
        }

        $width = 0;
        $height = 0;

        foreach ($this->nodes as $node) {
            [$nodeWidth, $nodeHeight] = $this->measureNode($node, 1);
            $width = max($width, $nodeWidth);
            $height += $nodeHeight;
        }

        return [$width + 4, $height + 1];
    }

    /**
     * Measure a node and its subtree.
     *
     * @param TreeVizNode $node
     * @param int $depth
     * @return array{0:int, 1:int} [width, height]
     */
    private function measureNode(TreeVizNode $node, int $depth): array
    {
        $labelWidth = mb_strlen($node->label, 'UTF-8') + 4;
        $height = 1;

        if (empty($node->children)) {
            return [$labelWidth, $height];
        }

        $childHeight = 0;
        foreach ($node->children as $child) {
            [, $childH] = $this->measureNode($child, $depth + 1);
            $childHeight += $childH;
        }

        return [$labelWidth, max(1, $childHeight)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the nodes.
     *
     * @param list<TreeVizNode> $nodes
     */
    public function withNodes(array $nodes): self
    {
        return new self(
            nodes: $nodes,
            showLines: $this->showLines,
            showLabels: $this->showLabels,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            labelColor: $this->labelColor,
            orientation: $this->orientation,
        );
    }

    /**
     * Show or hide connection lines.
     */
    public function withShowLines(bool $show): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $show,
            showLabels: $this->showLabels,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            labelColor: $this->labelColor,
            orientation: $this->orientation,
        );
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            showLabels: $show,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            labelColor: $this->labelColor,
            orientation: $this->orientation,
        );
    }

    /**
     * Set the orientation.
     */
    public function withOrientation(string $orientation): self
    {
        return new self(
            nodes: $this->nodes,
            showLines: $this->showLines,
            showLabels: $this->showLabels,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            labelColor: $this->labelColor,
            orientation: $orientation,
        );
    }
}

/**
 * A node in a tree visualization.
 */
final readonly class TreeVizNode
{
    /**
     * @param list<TreeVizNode> $children
     */
    public function __construct(
        public string $label,
        public string $type = 'leaf',
        public array $children = [],
    ) {}

    /**
     * Create a copy with children.
     *
     * @param list<TreeVizNode> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            label: $this->label,
            type: $this->type,
            children: $children,
        );
    }
}
