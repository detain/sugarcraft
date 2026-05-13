<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Organizational chart layout style.
 */
enum OrgChartStyle: string
{
    case TopDown = 'topdown';
    case LeftRight = 'leftright';
    case Tree = 'tree';
}

/**
 * An organizational chart node with optional reports.
 */
final class OrgChartNode
{
    /** @var list<OrgChartNode> */
    public array $reports = [];

    public function __construct(
        public readonly string $name,
        public readonly ?string $title = null,
        public readonly ?string $department = null,
        public readonly ?Color $color = null,
        public readonly ?string $avatar = null,
    ) {}

    /**
     * Add a direct report.
     */
    public function withReport(OrgChartNode $report): self
    {
        $clone = clone $this;
        $clone->reports[] = $report;
        return $clone;
    }

    /**
     * Add a direct report by name.
     */
    public function withReportByName(string $name, ?string $title = null, ?string $department = null): self
    {
        $report = new OrgChartNode($name, $title, $department, $this->color);
        return $this->withReport($report);
    }
}

/**
 * An organizational chart component for hierarchy visualization.
 *
 * Features:
 * - Hierarchical org structure
 * - Department color coding
 * - Employee names and titles
 * - Multiple layout styles
 * - Collapsible branches
 *
 * Mirrors organizational chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class OrgChart implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    private OrgChartNode $root;
    private bool $collapsed = false;

    public function __construct(
        private readonly ?int $maxDepth = null,
        private readonly OrgChartStyle $style = OrgChartStyle::TopDown,
        private readonly ?Color $rootColor = null,
        private readonly ?Color $nodeColor = null,
        private readonly ?Color $lineColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly string $borderStyle = 'rounded',
    ) {
        $this->root = new OrgChartNode('CEO', 'Chief Executive Officer', null, $this->rootColor);
    }

    /**
     * Create a new org chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxDepth: null,
            style: OrgChartStyle::TopDown,
            rootColor: Color::hex('#A6E3A1'),
            nodeColor: Color::hex('#89B4FA'),
            lineColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            borderStyle: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this org chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set the root node.
     */
    public function withRoot(OrgChartNode $root): self
    {
        $clone = clone $this;
        $clone->root = $root;
        return $clone;
    }

    /**
     * Set the root node by name.
     */
    public function withRootByName(string $name, ?string $title = null): self
    {
        $clone = clone $this;
        $clone->root = new OrgChartNode($name, $title, null, $this->rootColor);
        return $clone;
    }

    /**
     * Add a direct report to the root.
     */
    public function withReport(string $name, ?string $title = null, ?string $department = null, ?Color $color = null): self
    {
        $clone = clone $this;
        $report = new OrgChartNode($name, $title, $department, $color ?? $this->nodeColor);
        $clone->root = $clone->root->withReport($report);
        return $clone;
    }

    /**
     * Set collapsed state.
     */
    public function withCollapsed(bool $collapsed): self
    {
        $clone = clone $this;
        $clone->collapsed = $collapsed;
        return $clone;
    }

    /**
     * Render the org chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 65;
        $useHeight = $this->height ?? 12;

        if ($useWidth < 20 || $useHeight < 5) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $rootColor = $this->rootColor ?? Color::hex('#A6E3A1');

        $result = '';

        // Title
        $title = 'Organization Chart';
        $result .= $tl . str_repeat($h, intval(($useWidth - 2 - strlen($title)) / 2));
        $result .= $title;
        $result .= str_repeat($h, $useWidth - 2 - strlen($title) - intval(($useWidth - 2 - strlen($title)) / 2));
        $result .= $tr . "\n";

        // Render org structure
        $content = $this->buildOrgContent($useWidth - 2);
        foreach ($content as $line) {
            $result .= $v . $line . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Build the org chart content.
     *
     * @return list<string>
     */
    private function buildOrgContent(int $width): array
    {
        $lines = [];

        // Root node
        $rootName = '◉ ' . $this->root->name;
        if ($this->root->title !== null) {
            $rootName .= ' (' . $this->root->title . ')';
        }
        $rootPadding = intval(($width - strlen($rootName)) / 2);
        $lines[] = str_repeat(' ', max(0, $rootPadding)) . $rootName;

        // Connection line down
        if (!$this->collapsed && $this->root->reports !== []) {
            $connectorX = intval($width / 2);
            $lines[] = str_repeat(' ', $connectorX) . '│';

            // Render reports
            $reportWidth = intval($width / count($this->root->reports));
            foreach ($this->root->reports as $index => $report) {
                $isLast = $index === count($this->root->reports) - 1;
                $reportX = $index * $reportWidth + intval($reportWidth / 2);

                $prefix = $isLast ? '└' : '├';
                $reportName = $prefix . '─ ' . $report->name;
                if ($report->title !== null) {
                    $reportName .= ' (' . $report->title . ')';
                }

                $reportPadding = intval(($reportWidth - strlen($reportName)) / 2);
                $line = str_repeat(' ', max(0, $index * $reportWidth + $reportPadding));
                $line .= $reportName;
                $line .= str_repeat(' ', max(0, $width - strlen($line)));

                $lines[] = mb_substr($line, 0, $width);

                // Vertical connector
                if (!$isLast) {
                    $vLine = str_repeat(' ', $reportX) . '│';
                    $lines[] = mb_substr($vLine . str_repeat(' ', $width - strlen($vLine)), 0, $width);
                }
            }
        }

        return $lines;
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->borderStyle) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this org chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 65;
        $height = $this->height ?? 12;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the layout style.
     */
    public function withStyle(OrgChartStyle $style): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $style,
            rootColor: $this->rootColor,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the root color.
     */
    public function withRootColor(?Color $color): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $this->style,
            rootColor: $color,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the node color.
     */
    public function withNodeColor(?Color $color): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $this->style,
            rootColor: $this->rootColor,
            nodeColor: $color,
            lineColor: $this->lineColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $this->style,
            rootColor: $this->rootColor,
            nodeColor: $this->nodeColor,
            lineColor: $color,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $this->style,
            rootColor: $this->rootColor,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            textColor: $color,
            backgroundColor: $this->backgroundColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $this->style,
            rootColor: $this->rootColor,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            textColor: $this->textColor,
            backgroundColor: $color,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the border style.
     */
    public function withBorderStyle(string $style): self
    {
        return new self(
            maxDepth: $this->maxDepth,
            style: $this->style,
            rootColor: $this->rootColor,
            nodeColor: $this->nodeColor,
            lineColor: $this->lineColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            borderStyle: $style,
        );
    }
}
