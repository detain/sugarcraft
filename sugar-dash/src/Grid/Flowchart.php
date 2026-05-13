<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A flowchart component for visualizing processes and decisions.
 *
 * Features:
 * - Multiple node types (process, decision, start/end, I/O)
 * - Directional flow with arrows
 * - Yes/No branches for decisions
 * - Auto-layout
 * - Collapsible sub-processes
 *
 * Mirrors flowchart diagram patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Flowchart implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var array<string, FlowchartNode> */
    private array $nodes = [];

    private string $startNodeId = '';
    private bool $showArrows = true;
    private bool $showLabels = true;
    private string $flowDirection = 'top-bottom';

    public function __construct(
        private readonly ?int $maxNodes = null,
        private readonly ?Color $processColor = null,
        private readonly ?Color $decisionColor = null,
        private readonly ?Color $startEndColor = null,
        private readonly ?Color $ioColor = null,
        private readonly ?Color $arrowColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly string $style = 'rounded',
    ) {}

    /**
     * Create a new flowchart with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxNodes: null,
            processColor: Color::hex('#89B4FA'),
            decisionColor: Color::hex('#CBA6F7'),
            startEndColor: Color::hex('#A6E3A1'),
            ioColor: Color::hex('#F9E2AF'),
            arrowColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this flowchart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a node to the flowchart.
     */
    public function withNode(FlowchartNode $node): self
    {
        $clone = clone $this;
        $clone->nodes[$node->id] = $node;
        if ($node->type === FlowchartNodeType::StartEnd && $clone->startNodeId === '') {
            $clone->startNodeId = $node->id;
        }
        return $clone;
    }

    /**
     * Add a node by parameters.
     */
    public function addNode(string $id, string $label, FlowchartNodeType $type = FlowchartNodeType::Process): self
    {
        $node = new FlowchartNode($id, $label, $type);
        return $this->withNode($node);
    }

    /**
     * Set all nodes at once.
     *
     * @param array<string, FlowchartNode> $nodes
     */
    public function withNodes(array $nodes): self
    {
        $clone = clone $this;
        $clone->nodes = $nodes;
        return $clone;
    }

    /**
     * Connect two nodes.
     */
    public function withConnection(string $fromId, string $toId): self
    {
        $clone = clone $this;
        if (isset($clone->nodes[$fromId])) {
            $clone->nodes[$fromId] = $clone->nodes[$fromId]->withNext($toId);
        }
        return $clone;
    }

    /**
     * Set the start node ID.
     */
    public function withStartNode(string $nodeId): self
    {
        $clone = clone $this;
        $clone->startNodeId = $nodeId;
        return $clone;
    }

    /**
     * Show or hide arrows.
     */
    public function withShowArrows(bool $show): self
    {
        $clone = clone $this;
        $clone->showArrows = $show;
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
     * Set the flow direction.
     */
    public function withFlowDirection(string $direction): self
    {
        $clone = clone $this;
        $clone->flowDirection = $direction;
        return $clone;
    }

    /**
     * Render the flowchart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 65;
        $useHeight = $this->height ?? 18;

        if ($useWidth < 20 || $useHeight < 8) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'Flowchart';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat(' ', $titlePadding) . $title . str_repeat(' ', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $chartWidth = $useWidth - 2;
        $chartHeight = $useHeight - 3;

        if ($this->nodes === []) {
            $emptyMsg = '  No nodes defined  ';
            $padding = intval(($chartWidth - strlen($emptyMsg)) / 2);
            for ($row = 0; $row < $chartHeight; $row++) {
                $result .= $v . str_repeat(' ', $chartWidth) . $v . "\n";
            }
        } else {
            // Render nodes in flow order
            $content = $this->renderFlow($chartWidth);

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
     * Render the flow of nodes.
     */
    private function renderFlow(int $width): string
    {
        $result = '';

        // Get ordered nodes
        $orderedIds = $this->getOrderedNodes();

        foreach ($orderedIds as $nodeId) {
            $node = $this->nodes[$nodeId] ?? null;
            if ($node === null) {
                continue;
            }

            // Draw connector/arrow before node
            if ($this->showArrows && $result !== '') {
                $result .= $this->renderArrow($width);
            }

            // Draw the node
            $result .= $this->renderFlowNode($node, $width);

            // Draw branches for decision nodes
            if ($node->type === FlowchartNodeType::Decision && count($node->nextIds) > 1) {
                $result .= $this->renderDecisionBranches($node, $width);
            }
        }

        return $result;
    }

    /**
     * Get nodes in topological order.
     *
     * @return list<string>
     */
    private function getOrderedNodes(): array
    {
        if ($this->startNodeId !== '' && isset($this->nodes[$this->startNodeId])) {
            // Start from the designated start node
            $visited = [];
            $this->visitNode($this->startNodeId, $visited);
            return $visited;
        }

        // Fallback: return all node IDs
        return array_keys($this->nodes);
    }

    /**
     * Visit a node recursively (DFS).
     *
     * @param list<string> $visited
     */
    private function visitNode(string $nodeId, array &$visited): void
    {
        if (in_array($nodeId, $visited, true)) {
            return;
        }

        $visited[] = $nodeId;

        $node = $this->nodes[$nodeId] ?? null;
        if ($node !== null) {
            foreach ($node->nextIds as $nextId) {
                $this->visitNode($nextId, $visited);
            }
        }
    }

    /**
     * Render an arrow connector.
     */
    private function renderArrow(int $width): string
    {
        $arrowBody = str_repeat('─', max(0, $width - 5));
        return $arrowBody . " ↓\n";
    }

    /**
     * Render a single flowchart node.
     */
    private function renderFlowNode(FlowchartNode $node, int $width): string
    {
        $label = $this->showLabels ? $node->label : $node->id;
        $shortLabel = mb_substr($label, 0, $width - 4);

        $box = match ($node->type) {
            FlowchartNodeType::Process => '┌' . str_repeat('─', $width - 2) . "┐\n│ " . str_pad($shortLabel, $width - 4) . " │\n└" . str_repeat('─', $width - 2) . '┘',
            FlowchartNodeType::Decision => '┌' . str_repeat('─', $width - 2) . "┐\n│◆ " . str_pad($shortLabel, $width - 5) . " │\n└" . str_repeat('─', $width - 2) . '┘',
            FlowchartNodeType::StartEnd => '╭' . str_repeat('─', $width - 2) . "╮\n│ " . str_pad($shortLabel, $width - 4) . " │\n╰" . str_repeat('─', $width - 2) . '╯',
            FlowchartNodeType::InputOutput => '┌' . str_repeat('─', $width - 2) . "┐\n│/ " . str_pad($shortLabel, $width - 5) . " /│\n└" . str_repeat('─', $width - 2) . '┘',
            FlowchartNodeType::Connector => '○ ' . str_pad($shortLabel, $width - 4) . ' ',
            FlowchartNodeType::Data => '┌' . str_repeat('─', $width - 2) . "┐\n│▤ " . str_pad($shortLabel, $width - 5) . " │\n└" . str_repeat('─', $width - 2) . '┘',
        };

        return $box . "\n";
    }

    /**
     * Render decision branches.
     */
    private function renderDecisionBranches(FlowchartNode $node, int $width): string
    {
        $result = '';
        $branchWidth = intval(($width - 4) / count($node->nextIds));

        $branchLabels = ['Y', 'N'];
        $i = 0;
        foreach ($node->nextIds as $nextId) {
            $label = $branchLabels[$i] ?? ('→' . ($i + 1));
            $branchText = '┌' . str_repeat('─', $branchWidth - 2) . '┐│' . str_pad($label, $branchWidth - 2) . '│└' . str_repeat('─', $branchWidth - 2) . '┘';
            $result .= $branchText;
            $i++;
        }

        return $result . "\n";
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
     * Calculate the natural dimensions of this flowchart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 65;
        $height = $this->height ?? max(10, count($this->nodes) * 3 + 4);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the process color.
     */
    public function withProcessColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $color,
            decisionColor: $this->decisionColor,
            startEndColor: $this->startEndColor,
            ioColor: $this->ioColor,
            arrowColor: $this->arrowColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the decision color.
     */
    public function withDecisionColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $color,
            startEndColor: $this->startEndColor,
            ioColor: $this->ioColor,
            arrowColor: $this->arrowColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the start/end color.
     */
    public function withStartEndColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $this->decisionColor,
            startEndColor: $color,
            ioColor: $this->ioColor,
            arrowColor: $this->arrowColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the I/O color.
     */
    public function withIoColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $this->decisionColor,
            startEndColor: $this->startEndColor,
            ioColor: $color,
            arrowColor: $this->arrowColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the arrow color.
     */
    public function withArrowColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $this->decisionColor,
            startEndColor: $this->startEndColor,
            ioColor: $this->ioColor,
            arrowColor: $color,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $this->decisionColor,
            startEndColor: $this->startEndColor,
            ioColor: $this->ioColor,
            arrowColor: $this->arrowColor,
            textColor: $color,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $this->decisionColor,
            startEndColor: $this->startEndColor,
            ioColor: $this->ioColor,
            arrowColor: $this->arrowColor,
            textColor: $this->textColor,
            backgroundColor: $color,
            style: $this->style,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            maxNodes: $this->maxNodes,
            processColor: $this->processColor,
            decisionColor: $this->decisionColor,
            startEndColor: $this->startEndColor,
            ioColor: $this->ioColor,
            arrowColor: $this->arrowColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $style,
        );
    }
}
