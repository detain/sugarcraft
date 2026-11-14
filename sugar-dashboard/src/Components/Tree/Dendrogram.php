<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Foundation\ColorMode;

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
final class Dendrogram implements \SugarCraft\Dashboard\Foundation\SizedItem
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
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
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
        $leafColor = $this->leafColor ?? Color::hex('#A6E3A1');

        $result = '';

        // Title
        $title = 'Dendrogram';
        $titlePadding = intval(($width - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titlePadding) . $title . str_repeat('─', $width - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $chartHeight = $height - 4;
        $chartWidth = $width - 4;

        // Tree layout (finding sugar-dashboard-157): the old walk computed
        // child coordinates ($_childY/$_lineStartX/$_lineEndX) and then
        // never emitted them - the whole tree poured out as one flat stream
        // of space-padded boxes with no rows at all. The vertical path now
        // paints a row canvas: leaves take equal slots left to right, an
        // internal node centres over its children, and every parent gap is
        // bridged with a corner-span-tee row plus vertical drops onto the
        // children (the class name finally promises what it renders).
        $depth = $this->root->depth();
        $levelHeight = max(2, intval($chartHeight / max(1, $depth)));

        // Tee glyph where the parent stub lands on the sibling span; the
        // frame corners already destructured above double as the down-face
        // ends of the span in every style.
        $tee = match ($this->style) {
            'double' => '╥',
            'bold' => '┯',
            'empty' => ' ',
            default => '┬',
        };

        /** @var array<int, list<array{0:int,1:string,2:?Color}>> $canvas */
        $canvas = [];
        $leafCursor = 0;
        $this->paintVerticalTree(
            $this->root,
            0,
            $levelHeight,
            $chartWidth / max(1, count($this->collectLeaves($this->root))),
            $leafCursor,
            $canvas,
            $chartWidth,
            $nodeColor,
            $lineColor,
            $leafColor,
            $tl,
            $tr,
            $h,
            $v,
            $tee,
        );

        ksort($canvas);
        foreach ($canvas as $rowSegments) {
            $result .= $this->paintCanvasRow($rowSegments, $chartWidth) . "\n";
        }

        // Bottom border (finding 085 forensics: the tree walk leaves no
        // trailing break on its last row, so the border used to glue
        // itself onto the final content line - a 60x20 box painted a
        // 98-cell line).
        if (!str_ends_with($result, "\n")) {
            $result .= "\n";
        }
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Paint one subtree onto the vertical row canvas, returning the column
     * (in cells) the node centres on.
     *
     * Leaves consume equal slots left to right; an internal node centres
     * over its children and gets its bridge row - down-face corner, span,
     * tee where the parent stub lands - plus vertical drops from the bridge
     * down to each child's row. Segments are appended in any order; the row
     * composer sorts them, skips forward over overlaps and clips the chart.
     *
     * @param array<int, list<array{0:int,1:string,2:?\SugarCraft\Core\Util\Color}>> $canvas
     */
    private function paintVerticalTree(
        DendrogramNode $node,
        int $depth,
        int $levelHeight,
        float $slotWidth,
        int &$leafCursor,
        array &$canvas,
        int $chartWidth,
        \SugarCraft\Core\Util\Color $nodeColor,
        \SugarCraft\Core\Util\Color $lineColor,
        \SugarCraft\Core\Util\Color $leafColor,
        string $tl,
        string $tr,
        string $h,
        string $v,
        string $tee,
    ): float {
        if ($depth > 10) {
            return 0.0; // Depth guard, kept from the old walk
        }

        $isLeaf = count($node->children) === 0;

        if ($isLeaf) {
            $col = ($leafCursor + 0.5) * $slotWidth;
            $leafCursor++;
            $childCols = [];
        } else {
            $childCols = [];
            foreach ($node->children as $child) {
                $childCols[] = $this->paintVerticalTree(
                    $child,
                    $depth + 1,
                    $levelHeight,
                    $slotWidth,
                    $leafCursor,
                    $canvas,
                    $chartWidth,
                    $nodeColor,
                    $lineColor,
                    $leafColor,
                    $tl,
                    $tr,
                    $h,
                    $v,
                    $tee,
                );
            }
            $col = array_sum($childCols) / count($childCols);
        }

        $row = $depth * $levelHeight;
        $half = intdiv($this->nodeWidth, 2);
        $boxX = max(0, min((int) round($col) - $half, max(0, $chartWidth - $this->nodeWidth)));

        $label = $this->showLabels ? mb_substr($node->label, 0, $this->nodeWidth - 2, 'UTF-8') : '';
        $box = '[' . str_pad($label, $this->nodeWidth - 2, ' ', STR_PAD_BOTH) . ']';
        $canvas[$row][] = [$boxX, $box, $node->color ?? ($isLeaf ? $leafColor : $nodeColor)];

        if ($isLeaf || count($childCols) === 0) {
            return $col;
        }

        $childRow = $row + $levelHeight;
        $bridge = $row + 1;
        if ($bridge >= $childRow) {
            return $col; // No gap row left; children sit directly under the parent
        }

        if (count($node->children) === 1) {
            $mid = (int) round($col);
            for ($r = $bridge; $r < $childRow; $r++) {
                $canvas[$r][] = [$mid, $v, $lineColor];
            }
            return $col;
        }

        $left = (int) round(min($childCols));
        $right = (int) round(max($childCols));
        $mid = (int) round($col);

        $span = '';
        for ($x = max(0, $left); $x <= min($chartWidth - 1, $right); $x++) {
            $span .= match (true) {
                $x === max(0, $left) => $tl,
                $x === min($chartWidth - 1, $right) => $tr,
                $x === $mid => $tee,
                default => $h,
            };
        }
        $canvas[$bridge][] = [max(0, $left), $span, $lineColor];

        for ($r = $bridge + 1; $r < $childRow; $r++) {
            foreach ($childCols as $childCol) {
                $canvas[$r][] = [(int) round($childCol), $v, $lineColor];
            }
        }

        return $col;
    }

    /**
     * Compose one canvas row from its segments: left to right with gaps as
     * spaces, overlaps skipped forward, everything past the chart clipped.
     *
     * @param list<array{0:int,1:string,2:?\SugarCraft\Core\Util\Color}> $segments
     */
    private function paintCanvasRow(array $segments, int $chartWidth): string
    {
        usort($segments, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $line = '';
        $cursor = 0;

        foreach ($segments as [$x, $text, $color]) {
            $x = max($x, $cursor);
            if ($x >= $chartWidth) {
                break;
            }
            if ($x > $cursor) {
                $line .= str_repeat(' ', $x - $cursor);
            }
            $chunk = mb_substr($text, 0, $chartWidth - $x);
            $line .= $color !== null
                ? $color->toFg(ColorMode::profile()) . $chunk . Ansi::reset()
                : $chunk;
            $cursor = $x + mb_strlen($chunk);
        }

        return rtrim($line, ' ');
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

        // Bottom border (same trailing-break gap as the vertical path).
        if (!str_ends_with($result, "\n")) {
            $result .= "\n";
        }
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
            $_nodeY = $startY + $leafIndex * $leafSpacing;

            // Draw leaf node
            if ($color !== null) {
                $result .= $color->toFg(ColorMode::profile());
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
                $result .= $color->toFg(ColorMode::profile());
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
                    $result .= $lineColor->toFg(ColorMode::profile());
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
    public function innerSize(): array
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
