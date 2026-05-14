<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Feedback;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A loading skeleton component.
 *
 * Features:
 * - Animated placeholder bars
 * - Configurable number of lines
 * - Variable line widths
 * - Customizable colors
 * - Shimmer effect simulation via character patterns
 *
 * Mirrors skeleton/placeholder UI patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Skeleton implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<int> */
    private array $lineWidths;

    public function __construct(
        private readonly int $lines = 3,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $foregroundColor = null,
        array $lineWidths = [],
        private readonly string $fillChar = '█',
        private readonly string $emptyChar = ' ',
    ) {
        $this->lineWidths = $lineWidths;
    }

    /**
     * Create a new skeleton with default settings.
     */
    public static function new(int $lines = 3): self
    {
        return new self(
            lines: $lines,
            backgroundColor: Color::hex('#E5E7EB'),
            foregroundColor: Color::hex('#9CA3AF'),
            lineWidths: [],
            fillChar: '█',
            emptyChar: ' ',
        );
    }

    /**
     * Create a text-like skeleton.
     */
    public static function text(int $lines = 3): self
    {
        // Varying widths to simulate real text
        $widths = [];
        for ($i = 0; $i < $lines; $i++) {
            $widths[] = match ($i) {
                0 => 90,
                $lines - 1 => 60,
                default => 75 + ($i * 5) % 20,
            };
        }

        return new self(
            lines: $lines,
            backgroundColor: Color::hex('#E5E7EB'),
            foregroundColor: Color::hex('#9CA3AF'),
            lineWidths: $widths,
            fillChar: '▓',
            emptyChar: ' ',
        );
    }

    /**
     * Create an avatar skeleton.
     */
    public static function avatar(): self
    {
        return new self(
            lines: 1,
            backgroundColor: Color::hex('#E5E7EB'),
            foregroundColor: Color::hex('#9CA3AF'),
            lineWidths: [5],
            fillChar: '█',
            emptyChar: ' ',
        );
    }

    /**
     * Create a card skeleton.
     */
    public static function card(): self
    {
        return new self(
            lines: 4,
            backgroundColor: Color::hex('#E5E7EB'),
            foregroundColor: Color::hex('#9CA3AF'),
            lineWidths: [100, 80, 60, 90],
            fillChar: '█',
            emptyChar: ' ',
        );
    }

    /**
     * Set the allocated dimensions for this skeleton.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the skeleton as a string.
     */
    public function render(): string
    {
        $maxWidth = $this->width ?? 80;
        $lines = [];

        for ($i = 0; $i < $this->lines; $i++) {
            $targetWidth = $this->width ?? $this->getLineWidth($i, $maxWidth);

            // Apply colors
            $line = '';
            if ($this->backgroundColor !== null) {
                $line .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->foregroundColor !== null) {
                $line .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }

            // Generate the skeleton bar with varying fill
            $filledWidth = (int) floor($targetWidth * $this->getShimmerProgress($i));
            $line .= $this->generateShimmerLine($filledWidth, $targetWidth);

            // Reset ANSI
            if ($this->backgroundColor !== null || $this->foregroundColor !== null) {
                $line .= Ansi::reset();
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Get the width for a specific line.
     */
    private function getLineWidth(int $index, int $maxWidth): int
    {
        if (isset($this->lineWidths[$index])) {
            return (int) min($this->lineWidths[$index], $maxWidth);
        }

        // Default: last line is shorter
        if ($index === $this->lines - 1 && $this->lines > 1) {
            return (int) floor($maxWidth * 0.6);
        }

        return $maxWidth;
    }

    /**
     * Simulate shimmer effect with varying fill amount.
     */
    private function getShimmerProgress(int $lineIndex): float
    {
        // Create a pseudo-random but consistent fill percentage per line
        // Lines are slightly different to simulate natural loading
        $base = 0.7 + ($lineIndex * 0.05);
        $variation = ($lineIndex % 3) * 0.08;

        return min(1.0, $base - $variation + 0.15);
    }

    /**
     * Generate a line with shimmer effect.
     */
    private function generateShimmerLine(int $filledWidth, int $totalWidth): string
    {
        if ($filledWidth >= $totalWidth) {
            return str_repeat($this->fillChar, $totalWidth);
        }

        if ($filledWidth <= 0) {
            return str_repeat($this->emptyChar, $totalWidth);
        }

        // Create a gradient-like effect using different characters
        $line = '';
        for ($i = 0; $i < $totalWidth; $i++) {
            $position = $i / $totalWidth;
            $fillPosition = $filledWidth / $totalWidth;

            if ($position < $fillPosition * 0.7) {
                $line .= $this->fillChar;
            } elseif ($position < $fillPosition) {
                // Gradient transition
                $line .= '▒';
            } else {
                $line .= $this->emptyChar;
            }
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this skeleton.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = $this->width ?? 80;

        $width = $maxWidth;
        $height = $this->lines;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the number of lines.
     */
    public function withLines(int $lines): self
    {
        return new self(
            lines: $lines,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            lineWidths: $this->lineWidths,
            fillChar: $this->fillChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set specific line widths.
     *
     * @param list<int> $widths
     */
    public function withLineWidths(array $widths): self
    {
        return new self(
            lines: $this->lines,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            lineWidths: $widths,
            fillChar: $this->fillChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            lines: $this->lines,
            backgroundColor: $color,
            foregroundColor: $this->foregroundColor,
            lineWidths: $this->lineWidths,
            fillChar: $this->fillChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the foreground (fill) color.
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self(
            lines: $this->lines,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $color,
            lineWidths: $this->lineWidths,
            fillChar: $this->fillChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the fill character.
     */
    public function withFillChar(string $char): self
    {
        return new self(
            lines: $this->lines,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            lineWidths: $this->lineWidths,
            fillChar: $char,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the empty character.
     */
    public function withEmptyChar(string $char): self
    {
        return new self(
            lines: $this->lines,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            lineWidths: $this->lineWidths,
            fillChar: $this->fillChar,
            emptyChar: $char,
        );
    }
}
