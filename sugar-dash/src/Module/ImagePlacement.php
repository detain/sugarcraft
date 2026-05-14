<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Represents the placement of an image within a module's content.
 *
 * @readonly
 */
final class ImagePlacement
{
    public function __construct(
        public readonly string $path,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width = 0,
        public readonly int $height = 0,
    ) {}

    /**
     * Create a placement at the top-left corner.
     */
    public static function topLeft(string $path, int $x = 0, int $y = 0): self
    {
        return new self($path, $x, $y);
    }

    /**
     * Create a placement at the top-right corner.
     */
    public static function topRight(string $path, int $x = 0, int $y = 0): self
    {
        return new self($path, $x, $y);
    }

    /**
     * Create a placement at the bottom-left corner.
     */
    public static function bottomLeft(string $path, int $x = 0, int $y = 0): self
    {
        return new self($path, $x, $y);
    }

    /**
     * Create a placement at the bottom-right corner.
     */
    public static function bottomRight(string $path, int $x = 0, int $y = 0): self
    {
        return new self($path, $x, $y);
    }

    /**
     * Create a centered placement.
     */
    public static function center(string $path, int $width = 0, int $height = 0): self
    {
        return new self($path, 0, 0, $width, $height);
    }
}
