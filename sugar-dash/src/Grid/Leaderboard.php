<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A leaderboard component for displaying ranked items.
 *
 * Features:
 * - Ranked list with position indicators
 * - Configurable rank symbols (numbers, medals)
 * - Highlight top N items
 * - Value display with formatting
 * - Medal colors for top 3
 *
 * Mirrors leaderboard/ranking patterns adapted to PHP with wither-style immutable setters.
 */
final class Leaderboard implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, value: float|string, rank?: int}> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly string $rankStyle = 'number',
        private readonly int $topHighlight = 3,
        private readonly bool $showValue = true,
        private readonly bool $showTrend = false,
        private readonly ?Color $highlightColor = null,
        private readonly ?Color $valueColor = null,
        private readonly string $valueFormat = 'number',
    ) {}

    /**
     * Create a new leaderboard.
     *
     * @param list<array{label: string, value: float|string}> $items
     */
    public static function new(array $items = []): self
    {
        // Sort items by value descending and assign ranks
        $sorted = $items;
        usort($sorted, function ($a, $b) {
            $aVal = is_array($a) ? ($a['value'] ?? 0) : $a['value'];
            $bVal = is_array($b) ? ($b['value'] ?? 0) : $b['value'];
            return $bVal <=> $aVal;
        });

        $ranked = [];
        foreach ($sorted as $index => $item) {
            $ranked[] = array_merge($item, ['rank' => $index + 1]);
        }

        return new self(
            items: $ranked,
            rankStyle: 'number',
            topHighlight: 3,
            showValue: true,
            showTrend: false,
            highlightColor: Color::hex('#F9E2AF'),
            valueColor: Color::hex('#A6E3A1'),
            valueFormat: 'number',
        );
    }

    /**
     * Create a sample leaderboard for demonstration.
     */
    public static function sample(): self
    {
        return self::new([
            ['label' => 'Alice', 'value' => 9520],
            ['label' => 'Bob', 'value' => 8740],
            ['label' => 'Charlie', 'value' => 7980],
            ['label' => 'Diana', 'value' => 7230],
            ['label' => 'Eve', 'value' => 6510],
            ['label' => 'Frank', 'value' => 5890],
            ['label' => 'Grace', 'value' => 5140],
            ['label' => 'Henry', 'value' => 4820],
        ]);
    }

    /**
     * Set the allocated dimensions for this leaderboard.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the rank display string.
     */
    private function getRankDisplay(int $rank): string
    {
        return match ($this->rankStyle) {
            'number' => sprintf('%2d.', $rank),
            'medal' => match ($rank) {
                1 => '🥇',
                2 => '🥈',
                3 => '🥉',
                default => sprintf('%2d.', $rank),
            },
            'diamond' => match ($rank) {
                1 => '◆①',
                2 => '◆②',
                3 => '◆③',
                default => sprintf('%2d.', $rank),
            },
            'circle' => match ($rank) {
                1 => ' ①',
                2 => ' ②',
                3 => ' ③',
                default => sprintf('%2d.', $rank),
            },
            default => sprintf('%2d.', $rank),
        };
    }

    /**
     * Get the color for a given rank.
     */
    private function getRankColor(int $rank): ?Color
    {
        if ($rank > $this->topHighlight) {
            return null;
        }

        return match ($rank) {
            1 => Color::hex('#F9E2AF'), // Gold
            2 => Color::hex('#94E2D5'), // Silver
            3 => Color::hex('#CBA6F7'), // Bronze
            default => null,
        };
    }

    /**
     * Format a value for display.
     */
    private function formatValue(float|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return match ($this->valueFormat) {
            'number' => number_format((int) $value),
            'decimal' => number_format($value, 2),
            'percent' => number_format($value, 1) . '%',
            'currency' => '$' . number_format($value, 2),
            default => (string) $value,
        };
    }

    /**
     * Render the leaderboard.
     */
    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $result = '';
        $useWidth = $this->width ?? 40;

        foreach ($this->items as $index => $item) {
            $label = $item['label'] ?? '';
            $value = $item['value'] ?? 0;
            $rank = $item['rank'] ?? ($index + 1);

            $rankStr = $this->getRankDisplay($rank);
            $rankColor = $this->getRankColor($rank);

            // Build the line
            $line = '';

            // Rank
            if ($rankColor !== null) {
                $line .= $rankColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $rankStr . ' ';
            if ($rankColor !== null) {
                $line .= Ansi::reset();
            }

            // Label
            $line .= $label;

            // Value
            if ($this->showValue) {
                $valueStr = $this->formatValue($value);
                $labelLen = mb_strlen($label, 'UTF-8');
                $rankLen = mb_strlen($rankStr, 'UTF-8') + 1;
                $padding = $useWidth - $labelLen - $rankLen - mb_strlen($valueStr, 'UTF-8');
                $line .= str_repeat(' ', max(1, $padding));

                if ($this->valueColor !== null) {
                    $line .= $this->valueColor->toFg(ColorProfile::TrueColor);
                }
                $line .= $valueStr;
                if ($this->valueColor !== null) {
                    $line .= Ansi::reset();
                }
            }

            $result .= mb_substr($line, 0, $useWidth, 'UTF-8');
            if ($index < count($this->items) - 1) {
                $result .= "\n";
            }
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this leaderboard.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->items)) {
            return [0, 0];
        }

        $width = 0;
        foreach ($this->items as $item) {
            $label = $item['label'] ?? '';
            $value = $item['value'] ?? '';
            $rankLen = 4; // rank display width

            $itemWidth = $rankLen + mb_strlen($label, 'UTF-8') + 1;
            if ($this->showValue) {
                $itemWidth += mb_strlen((string) $value, 'UTF-8') + 1;
            }
            $width = max($width, $itemWidth);
        }

        return [$width, count($this->items)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the rank style.
     */
    public function withRankStyle(string $style): self
    {
        return new self(
            items: $this->items,
            rankStyle: $style,
            topHighlight: $this->topHighlight,
            showValue: $this->showValue,
            showTrend: $this->showTrend,
            highlightColor: $this->highlightColor,
            valueColor: $this->valueColor,
            valueFormat: $this->valueFormat,
        );
    }

    /**
     * Set the top highlight count.
     */
    public function withTopHighlight(int $count): self
    {
        return new self(
            items: $this->items,
            rankStyle: $this->rankStyle,
            topHighlight: $count,
            showValue: $this->showValue,
            showTrend: $this->showTrend,
            highlightColor: $this->highlightColor,
            valueColor: $this->valueColor,
            valueFormat: $this->valueFormat,
        );
    }

    /**
     * Set the value format.
     */
    public function withValueFormat(string $format): self
    {
        return new self(
            items: $this->items,
            rankStyle: $this->rankStyle,
            topHighlight: $this->topHighlight,
            showValue: $this->showValue,
            showTrend: $this->showTrend,
            highlightColor: $this->highlightColor,
            valueColor: $this->valueColor,
            valueFormat: $format,
        );
    }

    /**
     * Show or hide values.
     */
    public function withShowValue(bool $show): self
    {
        return new self(
            items: $this->items,
            rankStyle: $this->rankStyle,
            topHighlight: $this->topHighlight,
            showValue: $show,
            showTrend: $this->showTrend,
            highlightColor: $this->highlightColor,
            valueColor: $this->valueColor,
            valueFormat: $this->valueFormat,
        );
    }
}
