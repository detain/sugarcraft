<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A Dendrogram component for hierarchical clustering visualization.
 *
 * Features:
 * - Tree-based hierarchical display
 * - Vertical and horizontal orientations
 * - Proportional branch lengths based on values
 * - Color customization per node
 * - Collapsible subtrees
 * - Leaf node labels
 *
 * Mirrors dendrogram/hierarchical clustering patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Dendrogram implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    private ?DendrogramNode $root = null;
    private bool $showLabels = true;
    private bool $showValues = false;
    private bool $horizontal = false;
    private int $nodeWidth = 12;
    private int $nodeHeight = 3;
    private string $style = 'rounded';

    public function __construct(
        private ?Color $nodeColor = null,
        private ?Color $lineColor = null,
        private ?Color $textColor = null,
        private ?Color $leafColor = null,
    ) {}

    /**
     * Create a new dendrogram with default styling.
     */
    public static function new(): self
    {
        return new self(
            nodeColor: Color::hex('#89B4FA'),
            lineColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            leafColor: Color::hex('#A6E3A1'),
        );
    }

    /**
     * Create a sample dendrogram for demonstration.
     */
    public static function sample(): self
    {
        $leafA = new DendrogramNode('A', 'Alpha', 10, Color::hex('#89B4FA'));
        $leafB = new DendrogramNode('B', 'Beta', 15, Color::hex('#F38BA8'));
        $leafC = new DendrogramNode('C', 'Gamma', 8, Color::hex('#A6E3A1'));
        $leafD = new DendrogramNode('D', 'Delta', 12, Color::hex('#CBA6F7'));

        $branchA = (new DendrogramNode('AB', 'Group AB', 25))
            ->withChildren([$leafA, $leafB]);
        $branchB = (new DendrogramNode('CD', 'Group CD', 20))
            ->withChildren([$leafC, $leafD]);

        $root = (new DendrogramNode('root', 'Clusters', 45))
            ->withChildren([$branchA, $branchB]);

        return self::new()->withRoot($root);
    }

    /**
     * Set the allocated dimensions for this dendrogram.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set the root node.
     */
    public function withRoot(DendrogramNode $root): self
    {
        $clone = clone $this;
        $clone->root = $root;
        return $clone;
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        $clone = clone $this;
        $clone->showLabels = $show;
        return $clone;
    }

    /**
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        $clone = clone $this;
        $clone->showValues = $show;
        return $clone;
    }

    /**
     * Use horizontal orientation.
     */
    public function withHorizontal(bool $horizontal): self
    {
        $clone = clone $this;
        $clone->horizontal = $horizontal;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }

    /**
     * Set node dimensions.
     */
    public function withNodeSize(int $width, int $height): self
    {
        $clone = clone $this;
        $clone->nodeWidth = max(6, $width);
        $clone->nodeHeight = max(1, $height);
        return $clone;
    }

    /**
     * Render the dendrogram as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 20 || $useHeight < 8 || $this->root === null) {
            return '';
        }

        if ($this->horizontal) {
            return $this->renderHorizontal($useWidth, $useHeight);
        }

        return $this->renderVertical($useWidth, $useHeight);
    }

    /**
     * Render vertical dendrogram.
     */
    private function renderVertical(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $nodeColor = $this->nodeColor ?? Color::hex('#89B4FA');
        $lineColor = $this->lineColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $leafColor = $this->leafColor ?? Color::hex('#A6E3A1');

        $result = '';

        // Title
        $title = 'Dendrogram';
        $titlePadding = intval(($width - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titlePadding) . $title . str_repeat('─', $width - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $chartHeight = $height - 4;
        $chartWidth = $width - 4;

        // Calculate tree layout
        $depth = $this->root->getDepth();
        $levelHeight = max(2, intval($chartHeight / max(1, $depth)));

        // Render tree from top to bottom
        $this->renderTreeVertical($result, $this->root, 0, $chartWidth, 0, $levelHeight, $nodeColor, $lineColor, $textColor, $leafColor);

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Render vertical tree structure.
     */
    private function renderTreeVertical(
        string &$result,
        DendrogramNode $node,
        int $depth,
        int $maxWidth,
        int $startX,
        int $levelHeight,
        Color $nodeColor,
        Color $lineColor,
        Color $textColor,
        Color $leafColor,
    ): void {
        if ($depth > 10) {
            return; // Prevent infinite recursion
        }

        $isLeaf = count($node->children) === 0;
        $color = $node->color ?? ($isLeaf ? $leafColor : $nodeColor);

        // Calculate node position
        $nodeX = $startX + intval($maxWidth / 2);
        $nodeY = $depth * $levelHeight + 1;

        // Draw connecting lines to children
        if (!$isLeaf) {
            $childPositions = [];
            $childWidth = intval($maxWidth / count($node->children));

            foreach ($node->children as $index => $child) {
                $childX = $startX + intval(($index + 0.5) * $childWidth);
                $childPositions[] = $childX;

                // Draw vertical line from parent to children
                $childY = ($depth + 1) * $levelHeight + 1;

                if ($lineColor !== null) {
                    $result .= $lineColor->toFg(ColorProfile::TrueColor);
                }

                // Draw horizontal line at child level connecting all children
                $lineStartX = min($childPositions);
                $lineEndX = max($childPositions);
            }

            // Draw vertical connector from parent center to child line
            if (count($childPositions) > 0) {
                $midX = array_sum($childPositions) / count($childPositions);
                // Connector line (using box drawing chars)
            }
        }

        // Draw node box
        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        // Node representation
        $nodeLabel = $this->showLabels ? mb_substr($node->label, 0, $this->nodeWidth - 2, 'UTF-8') : '';
        $nodeWidth = $this->nodeWidth;

        $result .= str_repeat(' ', intval($nodeX - strlen($nodeLabel) / 2));
        $result .= '[' . str_pad($nodeLabel, $nodeWidth - 2, ' ', STR_PAD_BOTH) . ']';

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        // Draw child subtrees
        if (!$isLeaf) {
            $childWidth = intval($maxWidth / count($node->children));
            foreach ($node->children as $index => $child) {
                $childStartX = $startX + $index * $childWidth;
                $this->renderTreeVertical(
                    $result,
                    $child,
                    $depth + 1,
                    $childWidth,
                    $childStartX,
                    $levelHeight,
                    $nodeColor,
                    $lineColor,
                    $textColor,
                    $leafColor,
                );
            }
        }
    }

    /**
     * Render horizontal dendrogram.
     */
    private function renderHorizontal(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $nodeColor = $this->nodeColor ?? Color::hex('#89B4FA');
        $lineColor = $this->lineColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $leafColor = $this->leafColor ?? Color::hex('#A6E3A1');

        $result = '';

        // Title
        $title = 'Dendrogram';
        $result .= $tl . str_repeat('─', intval(($width - strlen($title)) / 2)) . $title . str_repeat('─', $width - intval(($width - strlen($title)) / 2) - strlen($title)) . $tr . "\n";

        $chartHeight = $height - 4;
        $chartWidth = $width - 4;

        // Render leaves on the right
        $leaves = $this->collectLeaves($this->root);
        $leafSpacing = max(1, intval($chartHeight / (count($leaves) + 1)));

        // Draw root on the left and leaves on the right
        $this->renderTreeHorizontal($result, $this->root, 2, $chartWidth - 4, 1, $leafSpacing, $nodeColor, $lineColor, $textColor, $leafColor, $leaves, 0);

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Collect all leaf nodes.
     *
     * @return list<DendrogramNode>
     */
    private function collectLeaves(?DendrogramNode $node): array
    {
        if ($node === null) {
            return [];
        }

        if (count($node->children) === 0) {
            return [$node];
        }

        $leaves = [];
        foreach ($node->children as $child) {
            $leaves = array_merge($leaves, $this->collectLeaves($child));
        }
        return $leaves;
    }

    /**
     * Render horizontal tree structure.
     */
    private function renderTreeHorizontal(
        string &$result,
        ?DendrogramNode $node,
        int $depth,
        int $maxWidth,
        int $startY,
        int $leafSpacing,
        Color $nodeColor,
        Color $lineColor,
        Color $textColor,
        Color $leafColor,
        array $allLeaves,
        int $level,
    ): void {
        if ($node === null || $depth > 10) {
            return;
        }

        $isLeaf = count($node->children) === 0;
        $color = $node->color ?? ($isLeaf ? $leafColor : $nodeColor);

        // Calculate position
        $nodeX = 2 + $level * 4;

        if ($isLeaf) {
            // Find leaf index
            $leafIndex = array_search($node, $allLeaves, true);
            $nodeY = $startY + $leafIndex * $leafSpacing;

            // Draw leaf node
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat(' ', $nodeX) . '●─ ' . $node->label;
            if ($this->showValues) {
                $result .= ' (' . sprintf('%.1f', $node->value) . ')';
            }
            if ($color !== null) {
                $result .= Ansi::reset();
            }
            $result .= "\n";
        } else {
            // Draw branch
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat(' ', $nodeX) . '◆ ' . $node->label . "\n";
            if ($color !== null) {
                $result .= Ansi::reset();
            }

            // Draw connector lines
            $childLeaves = $this->collectLeaves($node);
            if (count($childLeaves) > 0) {
                $firstLeafIndex = array_search($childLeaves[0], $allLeaves, true);
                $lastLeafIndex = array_search($childLeaves[count($childLeaves) - 1], $allLeaves, true);

                $topY = $startY + $firstLeafIndex * $leafSpacing;
                $bottomY = $startY + $lastLeafIndex * $leafSpacing;

                if ($lineColor !== null) {
                    $result .= $lineColor->toFg(ColorProfile::TrueColor);
                }

                // Vertical line connecting children
                for ($y = $topY; $y <= $bottomY; $y++) {
                    $result .= str_repeat(' ', $nodeX + 2) . ($y === intval(($topY + $bottomY) / 2) ? '├' : '│') . "\n";
                }

                // Horizontal line from parent
                $result .= str_repeat(' ', $nodeX + 2) . '┤' . "\n";

                if ($lineColor !== null) {
                    $result .= Ansi::reset();
                }
            }

            // Render children
            foreach ($node->children as $child) {
                $this->renderTreeHorizontal(
                    $result,
                    $child,
                    $depth + 1,
                    $maxWidth,
                    $startY,
                    $leafSpacing,
                    $nodeColor,
                    $lineColor,
                    $textColor,
                    $leafColor,
                    $allLeaves,
                    $level + 1,
                );
            }
        }
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this dendrogram.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 20;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the node color.
     */
    public function withNodeColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->nodeColor = $color;
        return $clone;
    }

    /**
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->lineColor = $color;
        return $clone;
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the leaf color.
     */
    public function withLeafColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->leafColor = $color;
        return $clone;
    }
}
