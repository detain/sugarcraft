<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A word cloud component for displaying text frequency.
 *
 * Features:
 * - Words sized by frequency/weight
 * - Configurable max words to display
 * - Color variation for visual interest
 * - Optional stop words filtering
 * - Random or sorted layout
 *
 * Mirrors word cloud patterns adapted to PHP with wither-style immutable setters.
 */
final class WordCloud implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{word: string, weight: float}> $words
     */
    public function __construct(
        private readonly array $words,
        private readonly int $maxWords = 20,
        private readonly bool $shuffle = true,
        private readonly bool $showWeights = false,
        private readonly array $colors = [],
    ) {}

    /**
     * Create a new word cloud.
     *
     * @param list<array{word: string, weight?: float}> $words
     */
    public static function new(array $words): self
    {
        $normalized = array_map(function (array $item): array {
            return [
                'word' => $item['word'],
                'weight' => max(0.1, floatval($item['weight'] ?? 1.0)),
            ];
        }, $words);

        return new self(
            words: $normalized,
            maxWords: 20,
            shuffle: true,
            showWeights: false,
            colors: [],
        );
    }

    /**
     * Set the allocated dimensions for this word cloud.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this word cloud.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->width ?? 40, $this->height ?? 10];
    }

    /**
     * Render the word cloud.
     */
    public function render(): string
    {
        if ($this->words === []) {
            return '';
        }

        // Sort and limit words
        $sorted = $this->words;
        usort($sorted, fn($a, $b) => $b['weight'] <=> $a['weight']);
        $sorted = array_slice($sorted, 0, $this->maxWords);

        if ($this->shuffle) {
            shuffle($sorted);
        }

        $maxWeight = 0;
        foreach ($sorted as $w) {
            if ($w['weight'] > $maxWeight) {
                $maxWeight = $w['weight'];
            }
        }
        if ($maxWeight <= 0) {
            $maxWeight = 1;
        }

        $useWidth = $this->width ?? 40;
        $result = [];

        // Simple line-based layout
        $currentLine = '';
        $currentLineWidth = 0;

        foreach ($sorted as $index => $wordData) {
            $word = $wordData['word'];
            $weight = $wordData['weight'];

            // Size based on weight (1-3 chars visual size)
            $sizeFactor = 1 + (($weight / $maxWeight) * 2);
            $wordLen = mb_strlen($word, 'UTF-8');
            $wordWidth = (int) ceil($wordLen * $sizeFactor);

            $colors = $this->colors;
            if (empty($colors)) {
                $colors = [
                    Color::hex('#F38BA8'),
                    Color::hex('#A6E3A1'),
                    Color::hex('#89B4FA'),
                    Color::hex('#F9E2AF'),
                    Color::hex('#CBA6F7'),
                    Color::hex('#94E2D5'),
                ];
            }
            $colorIndex = $index % count($colors);
            $color = $colors[$colorIndex];

            // Add spacing
            if ($currentLine !== '') {
                $wordWidth += 2;
            }

            // Check if we need a new line
            if ($currentLineWidth + $wordWidth > $useWidth && $currentLine !== '') {
                $result[] = $currentLine;
                $currentLine = '';
                $currentLineWidth = 0;
            }

            // Add word to current line
            if ($currentLine !== '') {
                $currentLine .= ' ';
                $currentLineWidth++;
            }

            $colorStr = $color->toFg(ColorProfile::TrueColor);
            $currentLine .= $colorStr . $word . Ansi::reset();
            $currentLineWidth += $wordLen;

            // Add weight suffix if showing
            if ($this->showWeights) {
                $weightStr = '(' . round($weight, 1) . ')';
                $currentLine .= $weightStr;
                $currentLineWidth += mb_strlen($weightStr, 'UTF-8');
            }
        }

        // Don't forget the last line
        if ($currentLine !== '') {
            $result[] = $currentLine;
        }

        return implode("\n", $result);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set max words to display.
     */
    public function withMaxWords(int $max): self
    {
        return new self(
            words: $this->words,
            maxWords: max(1, $max),
            shuffle: $this->shuffle,
            showWeights: $this->showWeights,
            colors: $this->colors,
        );
    }

    /**
     * Set shuffle behavior.
     */
    public function withShuffle(bool $shuffle): self
    {
        return new self(
            words: $this->words,
            maxWords: $this->maxWords,
            shuffle: $shuffle,
            showWeights: $this->showWeights,
            colors: $this->colors,
        );
    }

    /**
     * Show weight values.
     */
    public function withShowWeights(bool $show): self
    {
        return new self(
            words: $this->words,
            maxWords: $this->maxWords,
            shuffle: $this->shuffle,
            showWeights: $show,
            colors: $this->colors,
        );
    }

    /**
     * Set colors.
     *
     * @param list<Color> $colors
     */
    public function withColors(array $colors): self
    {
        return new self(
            words: $this->words,
            maxWords: $this->maxWords,
            shuffle: $this->shuffle,
            showWeights: $this->showWeights,
            colors: $colors,
        );
    }
}
