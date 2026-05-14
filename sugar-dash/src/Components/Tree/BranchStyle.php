<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * Style options for tree branches.
 */
final class BranchStyle
{
    public function __construct(
        public readonly ?Color $color = null,
        public readonly BranchPattern $pattern = BranchPattern::Solid,
        public readonly int $width = 1,
        public readonly bool $showArrow = true,
    ) {}

    /**
     * Create a solid branch style.
     */
    public static function solid(?Color $color = null): self
    {
        return new self(
            color: $color ?? Color::hex('#45475A'),
            pattern: BranchPattern::Solid,
            width: 1,
            showArrow: true,
        );
    }

    /**
     * Create a dashed branch style.
     */
    public static function dashed(?Color $color = null): self
    {
        return new self(
            color: $color ?? Color::hex('#45475A'),
            pattern: BranchPattern::Dashed,
            width: 1,
            showArrow: true,
        );
    }

    /**
     * Create a dotted branch style.
     */
    public static function dotted(?Color $color = null): self
    {
        return new self(
            color: $color ?? Color::hex('#45475A'),
            pattern: BranchPattern::Dotted,
            width: 1,
            showArrow: true,
        );
    }

    /**
     * Create a double-line branch style.
     */
    public static function double(?Color $color = null): self
    {
        return new self(
            color: $color ?? Color::hex('#45475A'),
            pattern: BranchPattern::Double,
            width: 2,
            showArrow: true,
        );
    }

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            color: $color,
            pattern: $this->pattern,
            width: $this->width,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Create a copy with a different pattern.
     */
    public function withPattern(BranchPattern $pattern): self
    {
        return new self(
            color: $this->color,
            pattern: $pattern,
            width: $this->width,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Create a copy with a different width.
     */
    public function withWidth(int $width): self
    {
        return new self(
            color: $this->color,
            pattern: $this->pattern,
            width: max(1, $width),
            showArrow: $this->showArrow,
        );
    }

    /**
     * Create a copy with or without arrow.
     */
    public function withArrow(bool $showArrow): self
    {
        return new self(
            color: $this->color,
            pattern: $this->pattern,
            width: $this->width,
            showArrow: $showArrow,
        );
    }

    /**
     * Get the line character(s) for this branch style.
     */
    public function getLineChar(): string
    {
        return match ($this->pattern) {
            BranchPattern::Solid => '─',
            BranchPattern::Dashed => '┅',
            BranchPattern::Dotted => '┄',
            BranchPattern::Double => '═',
        };
    }

    /**
     * Get the connector character for this branch style.
     */
    public function getConnectorChar(): string
    {
        return match ($this->pattern) {
            BranchPattern::Solid => '├',
            BranchPattern::Dashed => '╟',
            BranchPattern::Dotted => '╟',
            BranchPattern::Double => '╠',
        };
    }

    /**
     * Get the end connector character for this branch style.
     */
    public function getEndConnectorChar(): string
    {
        return match ($this->pattern) {
            BranchPattern::Solid => '└',
            BranchPattern::Dashed => '╙',
            BranchPattern::Dotted => '╙',
            BranchPattern::Double => '╚',
        };
    }
}

/**
 * Branch line patterns.
 */
enum BranchPattern: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Double = 'double';
}
