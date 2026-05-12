<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A vertical timeline component for displaying chronological events.
 *
 * Features:
 * - Vertical timeline with optional nodes
 * - Each node has a label and content
 * - Connector lines between nodes
 * - Customizable colors for nodes and connectors
 * - Indentation for content
 *
 * Mirrors timeline UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Timeline implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param TimelineNode[] $nodes
     */
    public function __construct(
        private readonly array $nodes = [],
        private readonly ?Color $nodeColor = null,
        private readonly ?Color $connectorColor = null,
        private readonly ?Color $contentColor = null,
        private readonly string $nodeChar = '●',
        private readonly string $connectorChar = '│',
    ) {}

    /**
     * Create a new empty timeline.
     */
    public static function new(): self
    {
        return new self(
            nodes: [],
            nodeColor: Color::hex('#3B82F6'),
            connectorColor: Color::hex('#9CA3AF'),
            contentColor: null,
            nodeChar: '●',
            connectorChar: '│',
        );
    }

    /**
     * Create a timeline with the given nodes.
     *
     * @param TimelineNode[] $nodes
     */
    public static function withNodes(array $nodes): self
    {
        return new self(
            nodes: $nodes,
            nodeColor: Color::hex('#3B82F6'),
            connectorColor: Color::hex('#9CA3AF'),
            contentColor: null,
            nodeChar: '●',
            connectorChar: '│',
        );
    }

    /**
     * Set the allocated dimensions for this timeline.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the timeline as a string.
     */
    public function render(): string
    {
        if ($this->nodes === []) {
            return '';
        }

        $result = [];
        $totalNodes = count($this->nodes);

        foreach ($this->nodes as $index => $node) {
            $isLast = $index === $totalNodes - 1;

            // Render this node
            $result[] = $this->renderNode($node, $isLast);
        }

        return implode("\n", $result);
    }

    /**
     * Render a single timeline node.
     */
    private function renderNode(TimelineNode $node, bool $isLast): string
    {
        $nodeStr = '';
        $connectorStr = '';

        // Apply node color
        if ($this->nodeColor !== null) {
            $nodeStr .= $this->nodeColor->toFg(ColorProfile::TrueColor);
        }
        $nodeStr .= $this->nodeChar;

        // Apply connector color
        if ($this->connectorColor !== null) {
            $connectorStr .= $this->connectorColor->toFg(ColorProfile::TrueColor);
        }

        // Build the connector: vertical line down if not last
        $connector = '';
        if (!$isLast) {
            $connector = ' ' . $connectorStr . $this->connectorChar;
        } else {
            $connector = ' ' . $connectorStr . ' ';
        }

        // Build the content
        $content = '';
        if ($this->contentColor !== null) {
            $content .= $this->contentColor->toFg(ColorProfile::TrueColor);
        }

        $labelWidth = Width::string($node->label);
        $content .= ' ' . $node->label;

        // Add description on new line if present
        if ($node->description !== null && $node->description !== '') {
            $padding = $labelWidth + 3; // node char + space + label
            $descLines = explode("\n", $node->description);
            foreach ($descLines as $i => $descLine) {
                if ($i === 0) {
                    $content .= ' ' . $descLine;
                } else {
                    $content .= "\n" . str_repeat(' ', $padding) . $descLine;
                }
            }
        }

        // Apply color reset
        if ($this->nodeColor !== null || $this->contentColor !== null) {
            $content .= Ansi::reset();
        }

        return $nodeStr . $connector . $content;
    }

    /**
     * Calculate the natural dimensions of this timeline.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->nodes === []) {
            return [0, 0];
        }

        $maxWidth = 0;
        $totalHeight = 0;

        foreach ($this->nodes as $index => $node) {
            $nodeWidth = Width::string($this->nodeChar) + 1; // node + space
            $nodeWidth += Width::string($node->label);

            if ($node->description !== null && $node->description !== '') {
                $nodeWidth += 1 + Width::string($node->description);
                $descLines = explode("\n", $node->description);
                $nodeWidth = max($nodeWidth, max(array_map(fn($l) => Width::string($l), $descLines)) + 3);
            }

            if ($nodeWidth > $maxWidth) {
                $maxWidth = $nodeWidth;
            }

            // Each node takes at least 1 line, plus description lines
            $totalHeight++;
            if ($node->description !== null && $node->description !== '') {
                $totalHeight += count(explode("\n", $node->description)) - 1;
            }
        }

        return [$maxWidth, $totalHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the timeline nodes.
     *
     * @param TimelineNode[] $nodes
     */
    public function withNodes(array $nodes): self
    {
        return new self(
            nodes: $nodes,
            nodeColor: $this->nodeColor,
            connectorColor: $this->connectorColor,
            contentColor: $this->contentColor,
            nodeChar: $this->nodeChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Add a node to the timeline.
     */
    public function addNode(TimelineNode $node): self
    {
        return new self(
            nodes: [...$this->nodes, $node],
            nodeColor: $this->nodeColor,
            connectorColor: $this->connectorColor,
            contentColor: $this->contentColor,
            nodeChar: $this->nodeChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the node color.
     */
    public function withNodeColor(?Color $color): self
    {
        return new self(
            nodes: $this->nodes,
            nodeColor: $color,
            connectorColor: $this->connectorColor,
            contentColor: $this->contentColor,
            nodeChar: $this->nodeChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the connector color.
     */
    public function withConnectorColor(?Color $color): self
    {
        return new self(
            nodes: $this->nodes,
            nodeColor: $this->nodeColor,
            connectorColor: $color,
            contentColor: $this->contentColor,
            nodeChar: $this->nodeChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the content color.
     */
    public function withContentColor(?Color $color): self
    {
        return new self(
            nodes: $this->nodes,
            nodeColor: $this->nodeColor,
            connectorColor: $this->connectorColor,
            contentColor: $color,
            nodeChar: $this->nodeChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the node character.
     */
    public function withNodeChar(string $char): self
    {
        return new self(
            nodes: $this->nodes,
            nodeColor: $this->nodeColor,
            connectorColor: $this->connectorColor,
            contentColor: $this->contentColor,
            nodeChar: $char,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the connector character.
     */
    public function withConnectorChar(string $char): self
    {
        return new self(
            nodes: $this->nodes,
            nodeColor: $this->nodeColor,
            connectorColor: $this->connectorColor,
            contentColor: $this->contentColor,
            nodeChar: $this->nodeChar,
            connectorChar: $char,
        );
    }
}
