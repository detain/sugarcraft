<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Geometry description for one window-chrome style.
 * Centralizes the position and size calculations so each
 * renderer only implements the primitive-drawing loop.
 */
final class WindowChromeGeometry
{
    /**
     * @param int $cy Center Y for traffic light circles
     * @param int $base Left edge X for first traffic light
     * @param int $r Radius for traffic light circles
     * @param int $gap Horizontal spacing between traffic lights
     * @param array<empty, empty, empty> $colors Placeholder for theme colors (unused in geometry)
     * @param int $titleBarHeight Height of title bar (0 for traffic-light styles)
     * @param int $buttonSize Size of Windows-style buttons
     * @param int $buttonGap Gap between Windows-style buttons
     * @param int $frameWidth Width of the frame content area
     */
    public function __construct(
        public readonly int $cy,
        public readonly int $base,
        public readonly int $r,
        public readonly int $gap,
        public readonly array $colors,
        public readonly int $titleBarHeight = 0,
        public readonly int $buttonSize = 0,
        public readonly int $buttonGap = 0,
        public readonly int $frameWidth = 0,
    ) {}

    /**
     * macOS-style traffic lights.
     */
    public static function macos(int $shadowMargin): self
    {
        $cy = $shadowMargin + 18;
        $base = $shadowMargin + 18;
        return new self(cy: $cy, base: $base, r: 6, gap: 18, colors: []);
    }

    /**
     * iTerm2-style smaller traffic lights.
     */
    public static function iterm2(int $shadowMargin): self
    {
        $cy = $shadowMargin + 14;
        $base = $shadowMargin + 14;
        return new self(cy: $cy, base: $base, r: 4, gap: 14, colors: []);
    }

    /**
     * Hyper-style title bar with traffic lights.
     */
    public static function hyper(int $shadowMargin, int $contentWidth): self
    {
        $titleBarHeight = 24;
        $titleBarY = $shadowMargin;
        $r = 5;
        $gap = 16;
        $cy = $titleBarY + ($titleBarHeight - $r * 2) / 2;
        $base = $shadowMargin + 12;
        return new self(
            cy: $cy,
            base: $base,
            r: $r,
            gap: $gap,
            colors: [],
            titleBarHeight: $titleBarHeight,
            frameWidth: $contentWidth,
        );
    }

    /**
     * Windows Terminal-style title bar with buttons.
     */
    public static function windowsTerminal(int $shadowMargin, int $contentWidth): self
    {
        $titleBarHeight = 28;
        return new self(
            cy: 0,
            base: 0,
            r: 0,
            gap: 0,
            colors: [],
            titleBarHeight: $titleBarHeight,
            buttonSize: 14,
            buttonGap: 8,
            frameWidth: $contentWidth,
        );
    }
}
