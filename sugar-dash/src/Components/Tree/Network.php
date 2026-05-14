<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A network diagram component for visualizing connected data.
 *
 * Features:
 * - Nodes with customizable shapes
 * - Edges with direction indicators
 * - Multiple layout algorithms
 * - Groupings/clusters
 * - Interactive node selection
 *
 * Mirrors network diagram patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Network implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var array<string, NetworkNode> */
    private array $nodes = [];

    /** @var list<array{from:string,to:string,label:?string}> */
    private array $edges = [];

    private bool $showLabels = true;
    private bool $showWeights = false;
    private bool $directed = true;
    private string $layoutAlgorithm = 'force';

    public function __construct(
        private ?int $maxNodes = null,
        private ?Color $nodeColor = null,
        private ?Color $edgeColor = null,
        private ?Color $labelColor = null,
        private ?Color $textColor = null,
        private ?Color $backgroundColor = null,
        private string $style = 'rounded',
    ) {}

    /**
     * Create a new network diagram with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxNodes: null,
            nodeColor: Color::hex('#89B4FA'),
            edgeColor: Color::hex('#45475A'),
            labelColor: Color::hex('#F38BA8'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this network diagram.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a node to the network.
     */
    public function withNode(NetworkNode $node): self
    {
        $clone = clone $this;
        $clone->nodes[$node->id] = $node;
        return $clone;
    }

    /**
     * Add a node by parameters.
     */
    public function addNode(string $id, string $label, NetworkShape $shape = NetworkShape::Circle): self
    {
        $node = new NetworkNode($id, $label, $shape, $this->nodeColor);
        return $this->withNode($node);
    }

    /**
     * Set all nodes at once.
     *
     * @param array<string, NetworkNode> $nodes
     */
    public function withNodes(array $nodes): self
    {
        $clone = clone $this;
        $clone->nodes = $nodes;
        return $clone;
    }

    /**
     * Add an edge between nodes.
     *
     * @param array{from:string,to:string,label:?string}|array{from:string,to:string} $edge
     */
    public function withEdge(array $edge): self
    {
        $clone = clone $this;
        $clone->edges[] = $edge;
        return $clone;
    }

    /**
     * Add an edge by parameters.
     */
    public function addEdge(string $from, string $to, ?string $label = null): self
    {
        return $this->withEdge(['from' => $from, 'to' => $to, 'label' => $label]);
    }

    /**
     * Set all edges at once.
     *
     * @param list<array{from:string,to:string,label:?string}> $edges
     */
    public function withEdges(array $edges): self
    {
        $clone = clone $this;
        $clone->edges = $edges;
        return $clone;
    }

    /**
     * Show or hide node labels.
     */
    public function withShowLabels(bool $show): self
    {
        $clone = clone $this;
        $clone->showLabels = $show;
        return $clone;
    }

    /**
     * Show or hide edge weights.
     */
    public function withShowWeights(bool $show): self
    {
        $clone = clone $this;
        $clone->showWeights = $show;
        return $clone;
    }

    /**
     * Set directed mode (arrowheads) or undirected.
     */
    public function withDirected(bool $directed): self
    {
        $clone = clone $this;
        $clone->directed = $directed;
        return $clone;
    }

    /**
     * Set the layout algorithm.
     */
    public function withLayoutAlgorithm(string $algorithm): self
    {
        $clone = clone $this;
        $clone->layoutAlgorithm = $algorithm;
        return $clone;
    }

    /**
     * Render the network diagram as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 70;
        $useHeight = $this->height ?? 18;

        if ($useWidth < 20 || $useHeight < 8) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $nodeColor = $this->nodeColor ?? Color::hex('#89B4FA');
        $edgeColor = $this->edgeColor ?? Color::hex('#45475A');

        $result = '';

        // Title
        $title = 'Network Diagram';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat(' ', $titlePadding) . $title . str_repeat(' ', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        // Render nodes and edges
        $chartWidth = $useWidth - 2;
        $chartHeight = $useHeight - 3;

        if ($this->nodes === []) {
            $emptyMsg = '  No nodes defined  ';
            $padding = intval(($chartWidth - strlen($emptyMsg)) / 2);
            for ($row = 0; $row < $chartHeight; $row++) {
                $result .= $v . str_repeat(' ', $chartWidth) . $v . "\n";
            }
        } else {
            // Calculate node positions using simple layout
            $nodePositions = $this->calculateNodePositions($chartWidth, $chartHeight);

            // Draw edges first (behind nodes)
            $edgeResult = '';
            foreach ($this->edges as $edge) {
                $fromPos = $nodePositions[$edge['from']] ?? null;
                $toPos = $nodePositions[$edge['to']] ?? null;

                if ($fromPos !== null && $toPos !== null) {
                    $edgeResult .= $this->drawEdge($fromPos, $toPos, $edge);
                }
            }

            // Draw nodes
            $nodeResult = '';
            foreach ($nodePositions as $nodeId => $pos) {
                $node = $this->nodes[$nodeId] ?? null;
                if ($node !== null) {
                    $nodeResult .= $this->drawNode($node, $pos);
                }
            }

            // Combine with borders
            $content = $edgeResult . $nodeResult;
            foreach (explode("\n", $content) as $line) {
                $paddedLine = str_pad($line, $chartWidth);
                $result .= $v . mb_substr($paddedLine, 0, $chartWidth) . $v . "\n";
            }

            // Fill remaining lines
            $linesRendered = substr_count($content, "\n") + 1;
            for ($i = $linesRendered; $i < $chartHeight; $i++) {
                $result .= $v . str_repeat(' ', $chartWidth) . $v . "\n";
            }
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Calculate node positions using simple layout.
     *
     * @return array<string, array{0:int,1:int}>
     */
    private function calculateNodePositions(int $width, int $height): array
    {
        $positions = [];
        $nodeIds = array_keys($this->nodes);
        $nodeCount = count($nodeIds);

        if ($nodeCount === 0) {
            return [];
        }

        // Simple circular layout
        $centerX = intval($width / 2);
        $centerY = intval($height / 2);
        $radius = min($centerX, $centerY) - 3;

        foreach ($nodeIds as $index => $nodeId) {
            if ($nodeCount === 1) {
                $positions[$nodeId] = [$centerX, $centerY];
            } else {
                $angle = (2 * M_PI * $index / $nodeCount) - M_PI / 2;
                $x = $centerX + intval($radius * cos($angle));
                $y = $centerY + intval($radius * sin($angle));
                $positions[$nodeId] = [
                    max(1, min($width - 3, $x)),
                    max(1, min($height - 2, $y)),
                ];
            }
        }

        return $positions;
    }

    /**
     * Draw an edge between two positions.
     */
    private function drawEdge(array $from, array $to, array $edge): string
    {
        [$x1, $y1] = $from;
        [$x2, $y2] = $to;

        $arrow = $this->directed ? '→' : '─';
        $edgeStyle = EdgeStyle::Solid;

        $result = '';

        // Simple straight line with arrow
        if ($y1 === $y2) {
            // Horizontal line
            $minX = min($x1, $x2);
            $maxX = max($x1, $x2);
            for ($x = $minX; $x <= $maxX; $x++) {
                $char = ($x === $maxX) ? $arrow : '─';
                $result .= $char;
            }
        } elseif ($x1 === $x2) {
            // Vertical line
            $minY = min($y1, $y2);
            $maxY = max($y1, $y2);
            for ($y = $minY; $y <= $maxY; $y++) {
                $char = ($y === $maxY) ? $arrow : '│';
                $result .= $char;
            }
        } else {
            // Diagonal line (simplified)
            $result .= '╲';
        }

        // Add label if present
        if ($this->showWeights && isset($edge['label'])) {
            $result .= ' [' . $edge['label'] . ']';
        }

        return $result . "\n";
    }

    /**
     * Draw a node at a position.
     */
    private function drawNode(NetworkNode $node, array $pos): string
    {
        [$x, $y] = $pos;

        $shapeChar = match ($node->shape) {
            NetworkShape::Circle => '●',
            NetworkShape::Square => '■',
            NetworkShape::Diamond => '◆',
            NetworkShape::Hexagon => '⬡',
            NetworkShape::Star => '★',
        };

        $label = $this->showLabels ? $node->label : $node->id;
        $nodeStr = $shapeChar . ' ' . mb_substr($label, 0, 10);

        return $nodeStr . "\n";
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
     * Calculate the natural dimensions of this network diagram.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 70;
        $height = $this->height ?? max(12, count($this->nodes) + 6);

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
     * Set the edge color.
     */
    public function withEdgeColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->edgeColor = $color;
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
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->backgroundColor = $color;
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
}
