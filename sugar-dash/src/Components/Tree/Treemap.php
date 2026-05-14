<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A treemap visualization component.
 *
 * Displays hierarchical data as nested rectangles where the size of each
 * rectangle represents its value. Supports multiple color schemes and
 * border styles.
 *
 * Mirrors treemap visualization patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Treemap implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /** @var list<TreemapLeaf> */
    private array $leaves = [];

    private bool $showLabels = true;
    private bool $showValues = false;
    private bool $showBorders = true;
    private string $borderStyle = 'rounded';

    /**
     * Block characters for different fill densities.
     */
    private const FILL_CHARS = [' ', '░', '▒', '▓', '█'];

    public function __construct(
        private ?Color $borderColor = null,
        private ?Color $textColor = null,
        private ?Color $valueColor = null,
    ) {}

    /**
     * Create a new treemap with default styling.
     */
    public static function new(array $leaves = []): self
    {
        return (new self(
            borderColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            valueColor: Color::hex('#A6E3A1'),
        ))->withLeaves($leaves);
    }

    /**
     * Create a sample treemap for demonstration.
     */
    public static function sample(int $count = 8): self
    {
        $labels = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa'];
        $leaves = [];

        for ($i = 0; $i < $count; $i++) {
            $leaves[] = new TreemapLeaf(
                id: 'leaf_' . $i,
                label: $labels[$i % count($labels)],
                value: random_int(10, 100),
                color: self::getDefaultColor($i),
            );
        }

        return self::new($leaves);
    }

    /**
     * Get a default color based on index.
     */
    private static function getDefaultColor(int $index): Color
    {
        $colors = [
            '#89B4FA', '#A6E3A1', '#F38BA8', '#F9E2AF',
            '#CBA6F7', '#94E2D5', '#F5C2E7', '#B4BEFE',
        ];
        return Color::hex($colors[$index % count($colors)]);
    }

    /**
     * Set the allocated dimensions for this treemap.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Set all leaves at once.
     *
     * @param list<TreemapLeaf> $leaves
     */
    public function withLeaves(array $leaves): self
    {
        $clone = clone $this;
        $clone->leaves = $leaves;
        return $clone;
    }

    /**
     * Add a leaf to the treemap.
     */
    public function withLeaf(TreemapLeaf $leaf): self
    {
        $clone = clone $this;
        $clone->leaves[] = $leaf;
        return $clone;
    }

    /**
     * Add a leaf by parameters.
     */
    public function addLeaf(string $id, string $label, float $value, ?Color $color = null): self
    {
        return $this->withLeaf(new TreemapLeaf($id, $label, $value, $color));
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
     * Show or hide borders.
     */
    public function withShowBorders(bool $show): self
    {
        $clone = clone $this;
        $clone->showBorders = $show;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withBorderStyle(string $style): self
    {
        $clone = clone $this;
        $clone->borderStyle = $style;
        return $clone;
    }

    /**
     * Calculate total value of all leaves.
     */
    private function getTotalValue(): float
    {
        $total = 0.0;
        foreach ($this->leaves as $leaf) {
            $total += $leaf->value;
        }
        return $total > 0 ? $total : 1.0;
    }

    /**
     * Calculate the natural dimensions of this treemap.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 40;
        $height = $this->sizerHeight ?? max(4, count($this->leaves) + 2);

        return [$width, $height];
    }

    /**
     * Render the treemap as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 40;
        $useHeight = $this->sizerHeight ?? 15;

        if ($useWidth < 10 || $useHeight < 3 || empty($this->leaves)) {
            return '';
        }

        $result = '';
        $totalValue = $this->getTotalValue();

        // Sort leaves by value descending
        $sortedLeaves = $this->leaves;
        usort($sortedLeaves, fn(TreemapLeaf $a, TreemapLeaf $b) => $b->value <=> $a->value);

        // Layout algorithm: fill rows left-to-right, top-to-bottom
        $currentX = 0;
        $currentY = 0;
        $rowHeight = max(3, $useHeight - 1);

        foreach ($sortedLeaves as $leaf) {
            // Calculate width based on proportion of total
            $proportion = $leaf->value / $totalValue;
            $cellWidth = max(3, intval($proportion * $useWidth));

            // Ensure we don't exceed width
            if ($currentX + $cellWidth > $useWidth) {
                $currentX = 0;
                $currentY += $rowHeight;
                $rowHeight = max(3, $useHeight - $currentY - 1);
            }

            // Skip if we'd exceed height
            if ($currentY >= $useHeight - 1) {
                break;
            }

            // Clamp cell width to remaining width
            $cellWidth = min($cellWidth, $useWidth - $currentX);

            // Render the cell
            $cellHeight = min($rowHeight, $useHeight - $currentY - 1);

            $result .= $this->renderCell(
                $leaf,
                $currentX,
                $currentY,
                $cellWidth,
                $cellHeight,
                $useWidth,
                $useHeight,
            );

            $currentX += $cellWidth;
            if ($currentX >= $useWidth) {
                $currentX = 0;
                $currentY += $rowHeight;
            }
        }

        return $result;
    }

    /**
     * Render a single cell in the treemap.
     */
    private function renderCell(
        TreemapLeaf $leaf,
        int $x,
        int $y,
        int $width,
        int $height,
        int $totalWidth,
        int $totalHeight
    ): string {
        $result = '';
        $borderColor = $this->borderColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $color = $leaf->color ?? self::getDefaultColor(0);

        // Get style characters
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        // If cell is too small, just use fill
        if ($width < 3 || $height < 2) {
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat('█', min($width, $totalWidth - $x));
            if ($color !== null) {
                $result .= Ansi::reset();
            }
            return $result;
        }

        // Calculate inner area
        $innerWidth = $width - 2;
        $innerHeight = $height - 2;

        // Top border
        if ($x > 0) {
            $result .= $v;
        }
        if ($this->showBorders && $borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= $tl . str_repeat($h, $innerWidth) . $tr;
        if ($this->showBorders && $borderColor !== null) {
            $result .= Ansi::reset();
        }

        // Content rows
        $density = $leaf->value / $this->getTotalValue();
        $fillIndex = min(4, intval($density * 5));
        $fillChar = self::FILL_CHARS[$fillIndex];
        $labelRow = ($this->showLabels && $innerHeight > 0) ? intval($innerHeight / 2) : -1;

        for ($row = 0; $row < $innerHeight; $row++) {
            $result .= "\n";

            // Left border
            if ($x > 0) {
                $result .= $v;
            }

            // Left fill — replace middle row with the label
            if ($row === $labelRow) {
                $label = mb_substr($leaf->label, 0, $innerWidth, 'UTF-8');
                $padding = $innerWidth - mb_strlen($label, 'UTF-8');
                $leftPad = intval($padding / 2);
                $rightPad = $padding - $leftPad;

                if ($color !== null) {
                    $result .= $color->toFg(ColorProfile::TrueColor);
                }
                $result .= str_repeat($fillChar, $leftPad);
                if ($color !== null) {
                    $result .= Ansi::reset();
                }
                if ($textColor !== null) {
                    $result .= $textColor->toFg(ColorProfile::TrueColor);
                }
                $result .= $label;
                if ($textColor !== null) {
                    $result .= Ansi::reset();
                }
                if ($color !== null) {
                    $result .= $color->toFg(ColorProfile::TrueColor);
                }
                $result .= str_repeat($fillChar, $rightPad);
                if ($color !== null) {
                    $result .= Ansi::reset();
                }
            } else {
                if ($color !== null) {
                    $result .= $color->toFg(ColorProfile::TrueColor);
                }
                $result .= str_repeat($fillChar, $innerWidth);
                if ($color !== null) {
                    $result .= Ansi::reset();
                }
            }

            // Right border
            if ($this->showBorders && $borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $v;
            if ($this->showBorders && $borderColor !== null) {
                $result .= Ansi::reset();
            }
        }

        // Bottom border
        $result .= "\n";
        if ($x > 0) {
            $result .= $v;
        }
        if ($this->showBorders && $borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= $bl . str_repeat($h, $innerWidth) . $br;
        if ($this->showBorders && $borderColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
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

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->borderColor = $color;
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
     * Set the value color.
     */
    public function withValueColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->valueColor = $color;
        return $clone;
    }
}
