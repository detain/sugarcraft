<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Flex;

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

    /** Fixed basis (0 = fill available space). */
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

    public function withRatio(int $ratio): self
    {
        return new self($this->content, $ratio, $this->basis, $this->style);
    }

    public function withBasis(int $basis): self
    {
        return new self($this->content, $this->ratio, $basis, $this->style);
    }

    public function withStyle(string $ansiStyle): self
    {
        return new self($this->content, $this->ratio, $this->basis, $ansiStyle);
    }

    public function withWidth(int $width): self
    {
        return $this->withBasis($width);
    }
}
