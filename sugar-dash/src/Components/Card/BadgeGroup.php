<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A group of badges displayed in a row.
 *
 * Features:
 * - Display multiple badges in a horizontal row
 * - Configurable gap between badges
 * - Support wrapping to multiple lines
 * - Support size configuration for all badges in group
 *
 * Mirrors badge-group UI concepts adapted to PHP with wither-style immutable setters.
 */
final class BadgeGroup implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<Badge> $badges
     */
    public function __construct(
        private readonly array $badges = [],
        private readonly int $gap = 1,
        private readonly bool $wrap = false,
    ) {}

    /**
     * Create a new badge group from a list of labels.
     *
     * @param list<string> $labels
     */
    public static function fromLabels(array $labels): self
    {
        $badges = array_map(
            fn(string $label): Badge => Badge::new($label),
            $labels
        );

        return new self(
            badges: $badges,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled badge group with success badges.
     *
     * @param list<string> $labels
     */
    public static function success(array $labels): self
    {
        $badges = array_map(
            fn(string $label): Badge => Badge::success($label),
            $labels
        );

        return new self(
            badges: $badges,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled badge group with warning badges.
     *
     * @param list<string> $labels
     */
    public static function warning(array $labels): self
    {
        $badges = array_map(
            fn(string $label): Badge => Badge::warning($label),
            $labels
        );

        return new self(
            badges: $badges,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled badge group with danger badges.
     *
     * @param list<string> $labels
     */
    public static function danger(array $labels): self
    {
        $badges = array_map(
            fn(string $label): Badge => Badge::danger($label),
            $labels
        );

        return new self(
            badges: $badges,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled badge group with info badges.
     *
     * @param list<string> $labels
     */
    public static function info(array $labels): self
    {
        $badges = array_map(
            fn(string $label): Badge => Badge::info($label),
            $labels
        );

        return new self(
            badges: $badges,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Set the allocated dimensions for this badge group.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the badge group as a string.
     */
    public function render(): string
    {
        if ($this->badges === []) {
            return '';
        }

        if ($this->wrap) {
            return $this->renderWrapped();
        }

        return $this->renderLinear();
    }

    /**
     * Render badges in a single horizontal row.
     */
    private function renderLinear(): string
    {
        $result = '';

        for ($i = 0; $i < count($this->badges); $i++) {
            $result .= $this->badges[$i]->render();

            if ($i < count($this->badges) - 1) {
                $result .= str_repeat(' ', max(0, $this->gap));
            }
        }

        return $result;
    }

    /**
     * Render badges with wrapping to multiple lines.
     */
    private function renderWrapped(): string
    {
        $availableWidth = $this->width ?? $this->calculateNaturalWidth();
        $result = '';
        $currentLine = '';
        $currentLineWidth = 0;
        $isFirstBadgeOnLine = true;

        for ($i = 0; $i < count($this->badges); $i++) {
            $badge = $this->badges[$i];
            [$badgeWidth, ] = $badge->getInnerSize();
            $badgeRendered = $badge->render();

            // Check if we need to wrap to a new line
            if (!$isFirstBadgeOnLine && $currentLineWidth + $this->gap + $badgeWidth > $availableWidth) {
                // Finish current line
                $result .= $currentLine . "\n";
                $currentLine = '';
                $currentLineWidth = 0;
                $isFirstBadgeOnLine = true;
            }

            // Add gap before badge (except for first badge on line)
            if (!$isFirstBadgeOnLine) {
                $currentLine .= str_repeat(' ', max(0, $this->gap));
                $currentLineWidth += $this->gap;
            }

            $currentLine .= $badgeRendered;
            $currentLineWidth += $badgeWidth;
            $isFirstBadgeOnLine = false;
        }

        // Append the last line
        if ($currentLine !== '') {
            $result .= $currentLine;
        }

        // Trim trailing newline
        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural width without wrapping.
     */
    private function calculateNaturalWidth(): int
    {
        if ($this->badges === []) {
            return 0;
        }

        $totalWidth = 0;
        for ($i = 0; $i < count($this->badges); $i++) {
            [$w, ] = $this->badges[$i]->getInnerSize();
            $totalWidth += $w;
            if ($i < count($this->badges) - 1) {
                $totalWidth += $this->gap;
            }
        }

        return $totalWidth;
    }

    /**
     * Calculate the natural dimensions of this badge group.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->badges === []) {
            return [0, 0];
        }

        if ($this->wrap) {
            return $this->calculateWrappedSize();
        }

        $width = $this->calculateNaturalWidth();
        $height = $this->getMaxBadgeHeight();

        return [$width, $height];
    }

    /**
     * Calculate size when wrapping is enabled.
     *
     * @return array{0:int,1:int} [width, height]
     */
    private function calculateWrappedSize(): array
    {
        $availableWidth = $this->width ?? $this->calculateNaturalWidth();
        $lineCount = 1;
        $currentLineWidth = 0;
        $maxLineWidth = 0;
        $isFirstBadgeOnLine = true;

        for ($i = 0; $i < count($this->badges); $i++) {
            [$w, ] = $this->badges[$i]->getInnerSize();

            if (!$isFirstBadgeOnLine && $currentLineWidth + $this->gap + $w > $availableWidth) {
                $maxLineWidth = max($maxLineWidth, $currentLineWidth);
                $lineCount++;
                $currentLineWidth = $w;
                $isFirstBadgeOnLine = false;
            } else {
                if (!$isFirstBadgeOnLine) {
                    $currentLineWidth += $this->gap;
                }
                $currentLineWidth += $w;
                $isFirstBadgeOnLine = false;
            }
        }

        $maxLineWidth = max($maxLineWidth, $currentLineWidth);

        // Get the max badge height (outlined badges have height 3)
        $maxBadgeHeight = $this->getMaxBadgeHeight();

        return [$maxLineWidth, $lineCount * $maxBadgeHeight];
    }

    /**
     * Get the maximum height among all badges.
     */
    private function getMaxBadgeHeight(): int
    {
        $maxHeight = 1;
        foreach ($this->badges as $badge) {
            [, $h] = $badge->getInnerSize();
            $maxHeight = max($maxHeight, $h);
        }
        return $maxHeight;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the badges in this group.
     *
     * @param list<Badge> $badges
     */
    public function withBadges(array $badges): self
    {
        return new self(
            badges: $badges,
            gap: $this->gap,
            wrap: $this->wrap,
        );
    }

    /**
     * Add a badge to this group.
     */
    public function withAppended(Badge $badge): self
    {
        return new self(
            badges: [...$this->badges, $badge],
            gap: $this->gap,
            wrap: $this->wrap,
        );
    }

    /**
     * Set the gap between badges.
     */
    public function withGap(int $gap): self
    {
        return new self(
            badges: $this->badges,
            gap: max(0, $gap),
            wrap: $this->wrap,
        );
    }

    /**
     * Set the wrap behavior.
     */
    public function withWrap(bool $wrap): self
    {
        return new self(
            badges: $this->badges,
            gap: $this->gap,
            wrap: $wrap,
        );
    }
}
