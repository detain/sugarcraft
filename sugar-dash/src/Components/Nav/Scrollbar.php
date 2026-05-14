<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A scrollbar component for terminal content.
 *
 * Features:
 * - Vertical scrollbar with thumb position based on ratio
 * - Customizable thumb and track characters
 * - Scroll position (0.0 to 1.0) representing visible viewport
 * - Thumb size based on viewport ratio (how much is visible)
 * - Optional arrow buttons at top/bottom
 *
 * Mirrors scrollbar concepts adapted to PHP with wither-style immutable setters.
 */
final class Scrollbar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $position = 0.0,
        private readonly float $viewportRatio = 0.3,
        private readonly int $heightConstraint = 10,
        private readonly ?Color $thumbColor = null,
        private readonly ?Color $trackColor = null,
        private readonly bool $showArrows = true,
        private readonly string $thumbChar = '█',
        private readonly string $trackChar = '│',
        private readonly string $arrowUp = '▲',
        private readonly string $arrowDown = '▼',
    ) {}

    /**
     * Create a new scrollbar with default styling.
     *
     * Default: purple thumb, gray track, 10 rows tall.
     */
    public static function new(float $position, float $viewportRatio = 0.3): self
    {
        return new self(
            position: max(0.0, min(1.0, $position)),
            viewportRatio: max(0.1, min(1.0, $viewportRatio)),
            heightConstraint: 10,
            thumbColor: Color::hex('#874BFD'),
            trackColor: Color::ansi(8),
            showArrows: true,
            thumbChar: '█',
            trackChar: '│',
            arrowUp: '▲',
            arrowDown: '▼',
        );
    }

    /**
     * Set the allocated dimensions for this scrollbar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the scrollbar as a string.
     */
    public function render(): string
    {
        $height = $this->getHeight();

        if ($height <= 0) {
            return '';
        }

        $position = max(0.0, min(1.0, $this->position));
        $viewportRatio = max(0.1, min(1.0, $this->viewportRatio));

        // Calculate thumb size and position
        $trackHeight = $height;
        if ($this->showArrows) {
            $trackHeight -= 2; // Reserve space for arrows
        }

        if ($trackHeight <= 0) {
            return str_repeat(' ', $this->width ?? 1);
        }

        // Thumb height: minimum 1, scaled by viewport ratio
        $thumbHeight = max(1, (int) floor($trackHeight * $viewportRatio));
        $thumbHeight = min($thumbHeight, $trackHeight);

        // Thumb position: 0 to (trackHeight - thumbHeight)
        $maxThumbPos = $trackHeight - $thumbHeight;
        $thumbTop = (int) floor($position * $maxThumbPos);

        // Build the track
        $lines = [];

        // Top arrow
        if ($this->showArrows) {
            if ($position > 0) {
                $lines[] = $this->renderArrow($this->arrowUp, true);
            } else {
                $lines[] = $this->renderArrow($this->arrowUp, false);
            }
        }

        // Track with thumb
        for ($i = 0; $i < $trackHeight; $i++) {
            $isThumb = ($i >= $thumbTop && $i < $thumbTop + $thumbHeight);

            if ($isThumb && $this->thumbColor !== null) {
                $line = $this->thumbColor->toFg(ColorProfile::TrueColor);
                $line .= $this->thumbChar;
                $line .= Ansi::reset();
            } elseif ($this->trackColor !== null) {
                $line = $this->trackColor->toFg(ColorProfile::TrueColor);
                $line .= $this->trackChar;
                $line .= Ansi::reset();
            } else {
                $line = $this->trackChar;
            }

            $lines[] = $line;
        }

        // Bottom arrow
        if ($this->showArrows) {
            if ($position < 1.0) {
                $lines[] = $this->renderArrow($this->arrowDown, true);
            } else {
                $lines[] = $this->renderArrow($this->arrowDown, false);
            }
        }

        $result = implode("\n", $lines);

        // Pad to allocated width if needed. str_pad counts bytes, so for
        // ANSI-prefixed lines we have to budget by visible width (strip the
        // escape sequences first) and append spaces manually.
        $useWidth = $this->width ?? 1;
        if ($useWidth > 1) {
            $resultLines = explode("\n", $result);
            $resultLines = array_map(function (string $line) use ($useWidth): string {
                $visible = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
                $visibleWidth = mb_strlen($visible, 'UTF-8');
                $pad = max(0, $useWidth - $visibleWidth);
                return $line . str_repeat(' ', $pad);
            }, $resultLines);
            $result = implode("\n", $resultLines);
        }

        return $result;
    }

    /**
     * Render an arrow character.
     */
    private function renderArrow(string $arrow, bool $enabled): string
    {
        if ($enabled && $this->thumbColor !== null) {
            return $this->thumbColor->toFg(ColorProfile::TrueColor) . $arrow . Ansi::reset();
        } elseif (!$enabled && $this->trackColor !== null) {
            return $this->trackColor->toFg(ColorProfile::TrueColor) . $arrow . Ansi::reset();
        }
        return $arrow;
    }

    /**
     * Get the height to use for the scrollbar.
     */
    private function getHeight(): int
    {
        if ($this->height !== null && $this->height > 0) {
            return $this->height;
        }
        return $this->heightConstraint;
    }

    /**
     * Calculate the natural dimensions of this scrollbar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $height = $this->getHeight();
        $width = 1;

        // Width is at least the thumb/track character width
        $width = $this->width ?? 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the scroll position (0.0 to 1.0).
     */
    public function withPosition(float $position): self
    {
        return new self(
            position: max(0.0, min(1.0, $position)),
            viewportRatio: $this->viewportRatio,
            heightConstraint: $this->heightConstraint,
            thumbColor: $this->thumbColor,
            trackColor: $this->trackColor,
            showArrows: $this->showArrows,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Set the viewport ratio (how much of the content is visible, 0.1 to 1.0).
     */
    public function withViewportRatio(float $ratio): self
    {
        return new self(
            position: $this->position,
            viewportRatio: max(0.1, min(1.0, $ratio)),
            heightConstraint: $this->heightConstraint,
            thumbColor: $this->thumbColor,
            trackColor: $this->trackColor,
            showArrows: $this->showArrows,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Set the height constraint.
     */
    public function withHeight(int $height): self
    {
        return new self(
            position: $this->position,
            viewportRatio: $this->viewportRatio,
            heightConstraint: max(3, $height),
            thumbColor: $this->thumbColor,
            trackColor: $this->trackColor,
            showArrows: $this->showArrows,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Set the thumb color.
     */
    public function withThumbColor(?Color $color): self
    {
        return new self(
            position: $this->position,
            viewportRatio: $this->viewportRatio,
            heightConstraint: $this->heightConstraint,
            thumbColor: $color,
            trackColor: $this->trackColor,
            showArrows: $this->showArrows,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Set the track color.
     */
    public function withTrackColor(?Color $color): self
    {
        return new self(
            position: $this->position,
            viewportRatio: $this->viewportRatio,
            heightConstraint: $this->heightConstraint,
            thumbColor: $this->thumbColor,
            trackColor: $color,
            showArrows: $this->showArrows,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Show or hide arrow buttons.
     */
    public function withShowArrows(bool $show): self
    {
        return new self(
            position: $this->position,
            viewportRatio: $this->viewportRatio,
            heightConstraint: $this->heightConstraint,
            thumbColor: $this->thumbColor,
            trackColor: $this->trackColor,
            showArrows: $show,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Set custom thumb and track characters.
     */
    public function withChars(string $thumb, string $track): self
    {
        return new self(
            position: $this->position,
            viewportRatio: $this->viewportRatio,
            heightConstraint: $this->heightConstraint,
            thumbColor: $this->thumbColor,
            trackColor: $this->trackColor,
            showArrows: $this->showArrows,
            thumbChar: $thumb,
            trackChar: $track,
            arrowUp: $this->arrowUp,
            arrowDown: $this->arrowDown,
        );
    }

    /**
     * Set custom arrow characters.
     */
    public function withArrows(string $up, string $down): self
    {
        return new self(
            position: $this->position,
            viewportRatio: $this->viewportRatio,
            heightConstraint: $this->heightConstraint,
            thumbColor: $this->thumbColor,
            trackColor: $this->trackColor,
            showArrows: $this->showArrows,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
            arrowUp: $up,
            arrowDown: $down,
        );
    }
}