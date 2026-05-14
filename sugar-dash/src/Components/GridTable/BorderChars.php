<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Border character set for data grid tables.
 *
 * Defines the Unicode characters used to render grid borders.
 */
final class BorderChars
{
    public function __construct(
        private readonly string $horizontal = '─',
        private readonly string $vertical = '│',
        private readonly string $topLeft = '┌',
        private readonly string $topRight = '┐',
        private readonly string $bottomLeft = '└',
        private readonly string $bottomRight = '┘',
        private readonly string $cross = '┼',
        private readonly string $topCross = '┬',
        private readonly string $bottomCross = '┴',
        private readonly string $leftCross = '├',
        private readonly string $rightCross = '┤',
    ) {}

    public static function new(
        string $horizontal = '─',
        string $vertical = '│',
        string $topLeft = '┌',
        string $topRight = '┐',
        string $bottomLeft = '└',
        string $bottomRight = '┘',
        string $cross = '┼',
        string $topCross = '┬',
        string $bottomCross = '┴',
        string $leftCross = '├',
        string $rightCross = '┤',
    ): self {
        return new self(
            horizontal: $horizontal,
            vertical: $vertical,
            topLeft: $topLeft,
            topRight: $topRight,
            bottomLeft: $bottomLeft,
            bottomRight: $bottomRight,
            cross: $cross,
            topCross: $topCross,
            bottomCross: $bottomCross,
            leftCross: $leftCross,
            rightCross: $rightCross,
        );
    }

    public static function rounded(): self
    {
        return new self(
            horizontal: '─',
            vertical: '│',
            topLeft: '╭',
            topRight: '╮',
            bottomLeft: '╰',
            bottomRight: '╯',
            cross: '┼',
            topCross: '┬',
            bottomCross: '┴',
            leftCross: '├',
            rightCross: '┤',
        );
    }

    public function getHorizontal(): string
    {
        return $this->horizontal;
    }

    public function getVertical(): string
    {
        return $this->vertical;
    }

    public function getTopLeft(): string
    {
        return $this->topLeft;
    }

    public function getTopRight(): string
    {
        return $this->topRight;
    }

    public function getBottomLeft(): string
    {
        return $this->bottomLeft;
    }

    public function getBottomRight(): string
    {
        return $this->bottomRight;
    }

    public function getCross(): string
    {
        return $this->cross;
    }

    public function getTopCross(): string
    {
        return $this->topCross;
    }

    public function getBottomCross(): string
    {
        return $this->bottomCross;
    }

    public function getLeftCross(): string
    {
        return $this->leftCross;
    }

    public function getRightCross(): string
    {
        return $this->rightCross;
    }
}
