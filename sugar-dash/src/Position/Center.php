<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Position;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * ANSI-aware centering calculations.
 *
 * Provides functions for centering content within a given width,
 * accounting for ANSI escape codes which don't contribute to
 * visible character count.
 *
 * Mirrors the teafields position helper pattern.
 */
final class Center
{
    /**
     * Calculate the horizontal offset to center content.
     *
     * @param string $content The content to center
     * @param int $width The total width to center within
     * @return int The left offset
     */
    public static function calculateOffsetX(string $content, int $width): int
    {
        $contentWidth = Width::string($content);
        if ($contentWidth >= $width) {
            return 0;
        }
        return (int) floor(($width - $contentWidth) / 2);
    }

    /**
     * Calculate the vertical offset to center content.
     *
     * @param int $contentHeight The content height
     * @param int $height The total height to center within
     * @return int The top offset
     */
    public static function calculateOffsetY(int $contentHeight, int $height): int
    {
        if ($contentHeight >= $height) {
            return 0;
        }
        return (int) floor(($height - $contentHeight) / 2);
    }

    /**
     * Measure the rendered view dimensions.
     *
     * @param string $content The rendered content
     * @return array{width:int,height:int} The dimensions
     */
    public static function measureRenderedView(string $content): array
    {
        $lines = explode("\n", $content);
        $width = 0;

        foreach ($lines as $line) {
            $width = max($width, Width::string($line));
        }

        return [
            'width' => $width,
            'height' => count($lines),
        ];
    }

    /**
     * Center content within given dimensions.
     *
     * @param string $content The content to center
     * @param int $width Total width
     * @param int $height Total height
     * @return string Centered content with padding
     */
    public static function center(string $content, int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return $content;
        }

        // Strip ANSI from content so padding is calculated on clean text
        // and the final output contains no escape sequences.
        $cleanContent = Ansi::strip($content);

        $measured = self::measureRenderedView($cleanContent);
        $offsetX = self::calculateOffsetX($cleanContent, $width);
        $offsetY = self::calculateOffsetY($measured['height'], $height);

        $lines = explode("\n", $cleanContent);
        $result = [];

        // Add top padding
        for ($i = 0; $i < $offsetY; $i++) {
            $result[] = str_repeat(' ', $width);
        }

        // Center each line
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            $leftPad = $offsetX;
            $rightPad = max(0, $width - $leftPad - $lineWidth);
            $result[] = str_repeat(' ', $leftPad) . $line . str_repeat(' ', $rightPad);
        }

        // Add bottom padding
        while (count($result) < $height) {
            $result[] = str_repeat(' ', $width);
        }

        return implode("\n", array_slice($result, 0, $height));
    }
}
