<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A node in a Sankey diagram.
 */
final class SankeyNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->value,
            $color,
        );
    }
}

/**
 * A flow connection between two nodes in a Sankey diagram.
 */
final class SankeyFlow
{
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            $this->source,
            $this->target,
            $this->value,
            $color,
        );
    }
}

/**
 * A Sankey diagram component for flow visualization.
 *
 * Features:
 * - Flow connections between nodes
 * - Variable width flows proportional to values
 * - Color customization for nodes and flows
 * - Vertical or horizontal layout
 *
 * Mirrors Sankey diagram patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Sankey implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<SankeyNode> */
    private array $nodes = [];

    /** @var list<SankeyFlow> */
    private array $flows = [];

    private bool $horizontal = true;
    private bool $showLabels = true;
    private bool $showValues = false;
    private int $nodeWidth = 2;
    private int $nodeSpacing = 3;

    public function __construct(
        private ?Color $nodeColor = null,
        private ?Color $flowColor = null,
        private ?Color $labelColor = null,
        private string $style = 'rounded',
    ) {}

    /**
     * Create a new Sankey diagram with default styling.
     */
    public static function new(): self
    {
        return new self(
            nodeColor: Color::hex('#89B4FA'),
            flowColor: Color::hex('#45475A'),
            labelColor: Color::hex('#CDD6F4'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this Sankey diagram.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a node to the diagram.
     */
    public function withNode(SankeyNode $node): self
    {
        $clone = clone $this;
        $clone->nodes[] = $node;
        return $clone;
    }

    /**
     * Add a node by parameters.
     */
    public function addNode(string $id, string $label, float $value, ?Color $color = null): self
    {
        return $this->withNode(new SankeyNode($id, $label, $value, $color ?? $this->nodeColor));
    }

    /**
     * Add all nodes at once.
     *
     * @param list<SankeyNode> $nodes
     */
    public function withNodes(array $nodes): self
    {
        $clone = clone $this;
        $clone->nodes = $nodes;
        return $clone;
    }

    /**
     * Add a flow to the diagram.
     */
    public function withFlow(SankeyFlow $flow): self
    {
        $clone = clone $this;
        $clone->flows[] = $flow;
        return $clone;
    }

    /**
     * Add a flow by parameters.
     */
    public function addFlow(string $source, string $target, float $value, ?Color $color = null): self
    {
        return $this->withFlow(new SankeyFlow($source, $target, $value, $color ?? $this->flowColor));
    }

    /**
     * Add all flows at once.
     *
     * @param list<SankeyFlow> $flows
     */
    public function withFlows(array $flows): self
    {
        $clone = clone $this;
        $clone->flows = $flows;
        return $clone;
    }

    /**
     * Set horizontal or vertical layout.
     */
    public function withHorizontal(bool $horizontal): self
    {
        $clone = clone $this;
        $clone->horizontal = $horizontal;
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
     * Render the Sankey diagram as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? max(20, count($this->nodes) * ($this->nodeSpacing + 5) + 8);

        if ($useWidth < 20 || $useHeight < 5 || empty($this->nodes)) {
            return '';
        }

        if ($this->horizontal) {
            return $this->renderHorizontal($useWidth, $useHeight);
        }

        return $this->renderVertical($useWidth, $useHeight);
    }

    /**
     * Render horizontal layout.
     */
    private function renderHorizontal(int $width, int $height): string
    {
        $labelColor = $this->labelColor ?? Color::hex('#CDD6F4');
        $result = '';

        // Calculate total value for proportional sizing
        $totalValue = array_sum(array_map(fn(SankeyNode $n) => $n->value, $this->nodes));
        if ($totalValue <= 0) {
            $totalValue = 1;
        }

        // Build node index
        $nodeMap = [];
        foreach ($this->nodes as $node) {
            $nodeMap[$node->id] = $node;
        }

        // Calculate node heights (proportional to values)
        $chartHeight = $height - 4; // Account for borders and labels
        $nodeHeights = [];
        $currentY = 1;

        foreach ($this->nodes as $node) {
            $nodeHeight = max(1, intval(($node->value / $totalValue) * $chartHeight));
            $nodeHeights[$node->id] = [
                'node' => $node,
                'y' => $currentY,
                'height' => $nodeHeight,
            ];
            $currentY += $nodeHeight + $this->nodeSpacing;
        }

        // Left column of nodes
        $nodeAreaWidth = $width - 20;
        foreach ($nodeHeights as $id => $info) {
            $node = $info['node'];
            $y = $info['y'];
            $nodeHeight = $info['height'];

            $color = $node->color ?? $this->nodeColor;

            // Draw node bar
            for ($row = 0; $row < $nodeHeight; $row++) {
                $lineY = $y + $row;
                if ($lineY >= 1 && $lineY < $height - 1) {
                    $line = str_repeat(' ', 8);
                    if ($color !== null) {
                        $line .= $color->toFg(ColorProfile::TrueColor);
                    }
                    $line .= str_repeat('█', $this->nodeWidth);
                    if ($color !== null) {
                        $line .= Ansi::reset();
                    }

                    // Draw label
                    if ($row === 0 && $this->showLabels) {
                        $line .= ' ' . mb_substr($node->label, 0, 12);
                        if ($this->showValues) {
                            $line .= ' (' . $this->formatValue($node->value) . ')';
                        }
                    }

                    $result .= $line . "\n";
                }
            }
        }

        // Draw flows
        foreach ($this->flows as $flow) {
            if (!isset($nodeHeights[$flow->source], $nodeHeights[$flow->target])) {
                continue;
            }

            $sourceInfo = $nodeHeights[$flow->source];
            $targetInfo = $nodeHeights[$flow->target];

            $flowColor = $flow->color ?? $this->flowColor;
            $sourceY = $sourceInfo['y'] + intval($sourceInfo['height'] / 2);
            $targetY = $targetInfo['y'] + intval($targetInfo['height'] / 2);

            // Draw simple flow line
            $flowWidth = max(1, intval(($flow->value / $totalValue) * $nodeAreaWidth));

            for ($i = 0; $i < $flowWidth; $i++) {
                $x = 10 + $i;
                if ($flowColor !== null) {
                    $result .= $flowColor->toFg(ColorProfile::TrueColor);
                }
                $result .= '─';
                if ($flowColor !== null) {
                    $result .= Ansi::reset();
                }
            }
            $result .= '▶';
            $result .= "\n";
        }

        return $result;
    }

    /**
     * Render vertical layout.
     */
    private function renderVertical(int $width, int $height): string
    {
        $labelColor = $this->labelColor ?? Color::hex('#CDD6F4');
        $result = '';

        // Calculate total value for proportional sizing
        $totalValue = array_sum(array_map(fn(SankeyNode $n) => $n->value, $this->nodes));
        if ($totalValue <= 0) {
            $totalValue = 1;
        }

        // Calculate node widths (proportional to values)
        $chartWidth = $width - 4;
        $currentX = 2;
        $nodeWidths = [];

        foreach ($this->nodes as $node) {
            $nodeWidth = max(2, intval(($node->value / $totalValue) * $chartWidth));
            $nodeWidths[$node->id] = [
                'node' => $node,
                'x' => $currentX,
                'width' => $nodeWidth,
            ];
            $currentX += $nodeWidth + $this->nodeSpacing;
        }

        // Draw top border
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();
        $result .= $tl . str_repeat($h, $width - 2) . $tr . "\n";

        // Draw nodes row
        $nodesLine = $v;
        foreach ($nodeWidths as $id => $info) {
            $node = $info['node'];
            $nodeWidth = $info['width'];
            $color = $node->color ?? $this->nodeColor;

            if ($color !== null) {
                $nodesLine .= $color->toFg(ColorProfile::TrueColor);
            }
            $nodesLine .= str_repeat('█', $nodeWidth);
            if ($color !== null) {
                $nodesLine .= Ansi::reset();
            }
            $nodesLine .= ' ';
        }
        $nodesLine .= $v;
        $result .= $nodesLine . "\n";

        // Draw labels
        if ($this->showLabels) {
            $labelsLine = $v . ' ';
            foreach ($nodeWidths as $id => $info) {
                $node = $info['node'];
                $nodeWidth = $info['width'];
                $label = mb_substr($node->label, 0, $nodeWidth - 1);
                $labelsLine .= str_pad($label, $nodeWidth);
                $labelsLine .= ' ';
            }
            $labelsLine .= $v;
            if ($labelColor !== null) {
                $labelsLine = $labelColor->toFg(ColorProfile::TrueColor) . $labelsLine . Ansi::reset();
            }
            $result .= $labelsLine . "\n";
        }

        // Draw bottom border
        $result .= $bl . str_repeat($h, $width - 2) . $br;

        return $result;
    }

    /**
     * Format a value for display.
     */
    private function formatValue(float $value): string
    {
        if (abs($value) >= 1000000) {
            return sprintf('%.1fM', $value / 1000000);
        }
        if (abs($value) >= 1000) {
            return sprintf('%.1fK', $value / 1000);
        }
        if ($value === floor($value)) {
            return sprintf('%.0f', $value);
        }
        return sprintf('%.1f', $value);
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
     * Calculate the natural dimensions of this Sankey diagram.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? max(10, count($this->nodes) * 3 + 4);

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
     * Set the flow color.
     */
    public function withFlowColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->flowColor = $color;
        return $clone;
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->labelColor = $color;
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
     * Set the node width.
     */
    public function withNodeWidth(int $width): self
    {
        $clone = clone $this;
        $clone->nodeWidth = max(1, $width);
        return $clone;
    }

    /**
     * Set the node spacing.
     */
    public function withNodeSpacing(int $spacing): self
    {
        $clone = clone $this;
        $clone->nodeSpacing = max(0, $spacing);
        return $clone;
    }
}
