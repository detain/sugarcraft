<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

use SugarCraft\Flexbox\Support\Sgr;

/**
 * A single item inside a FlexBox.
 *
 * Holds content + layout constraints (ratio, basis).
 * Uses ratio-based sizing (like CSS flex-grow) for space distribution.
 */
final class FlexItem
{
    public readonly string $content;

    /** Relative size ratio — items with higher ratios take more space. */
    public readonly int $ratio;

    /**
     * Fixed main-axis reservation in cells (row: width, column: height).
     * 0 means NO fixed reservation — the item then shares free space by
     * ratio; a positive basis excludes the item from the flex pool.
     */
    public readonly int $basis;

    /** Style applied to this item (ANSI string). */
    public readonly string $style;

    private function __construct(
        string $content,
        int $ratio = 1,
        int $basis = 0,
        string $style = '',
    ) {
        $this->content = $content;
        $this->ratio   = $ratio;
        $this->basis   = $basis;
        $this->style   = $style;
    }

    public static function new(string $content = ''): self
    {
        return new self($content);
    }

    public function withContent(string $content): self
    {
        return new self($content, $this->ratio, $this->basis, $this->style);
    }

    /**
     * @param int $ratio Growth weight, >= 0 (mirrors CSS, where a negative
     *                   flex-grow is invalid). 0 is legal and means "never
     *                   grow": the item then keeps a 1-cell floor even
     *                   beside flex siblings.
     */
    public function withRatio(int $ratio): self
    {
        if ($ratio < 0) {
            throw new \InvalidArgumentException(
                'Ratio must be >= 0; got ' . $ratio . '.',
            );
        }
        return new self($this->content, $ratio, $this->basis, $this->style);
    }

    /**
     * @param int $basis Fixed main-axis reservation in cells, >= 0. A
     *                   positive basis excludes the item from the flex pool
     *                   and is clamped to the container size at render
     *                   time, so an oversized value shrinks instead of
     *                   storming memory.
     */
    public function withBasis(int $basis): self
    {
        if ($basis < 0) {
            throw new \InvalidArgumentException(
                'Basis must be >= 0 cells; got ' . $basis . '.',
            );
        }
        return new self($this->content, $this->ratio, $basis, $this->style);
    }

    public function withStyle(string $ansiStyle): self
    {
        $style = Sgr::normalize($ansiStyle, 'FlexItem style');
        return new self($this->content, $this->ratio, $this->basis, $style);
    }

    /**
     * Alias of withBasis(): the fixed main-axis reservation. Named for the
     * row case — in a column box this same value is the item's HEIGHT in
     * cells, not its width.
     */
    public function withWidth(int $width): self
    {
        return $this->withBasis($width);
    }
}
