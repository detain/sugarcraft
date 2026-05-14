<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Width;

/**
 * Text case transformation component.
 *
 * Applies case transformations to text content:
 * - Upper case
 * - Lower case
 * - Title case
 * - Upper first (first letter uppercase, rest lower)
 * - Upper words (first letter of each word uppercase)
 *
 * Mirrors lipgloss transformer functionality but adapted to PHP
 * with wither-style immutable setters.
 */
final class Transformer implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const Upper = 'upper';
    public const Lower = 'lower';
    public const Title = 'title';
    public const UpperFirst = 'upper_first';
    public const UpperWords = 'upper_words';

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly string $transform = self::Upper,
    ) {}

    /**
     * Create a new transformer with uppercase transformation.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            transform: self::Upper,
        );
    }

    /**
     * Set the allocated dimensions for this transformer.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the transformed text.
     */
    public function render(): string
    {
        $content = $this->content->render();

        // Apply the transformation
        return $this->applyTransform($content);
    }

    /**
     * Apply the case transformation to text.
     */
    private function applyTransform(string $text): string
    {
        return match ($this->transform) {
            self::Upper => mb_strtoupper($text, 'UTF-8'),
            self::Lower => mb_strtolower($text, 'UTF-8'),
            self::Title => $this->titleCase($text),
            self::UpperFirst => $this->upperFirst($text),
            self::UpperWords => $this->upperWords($text),
            default => $text,
        };
    }

    /**
     * Title case: first letter of each sentence uppercase, rest lowercase.
     *
     * Treats each line as a separate sentence.
     */
    private function titleCase(string $text): string
    {
        $lines = explode("\n", $text);
        $result = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $result[] = '';
                continue;
            }
            // Uppercase first letter of the line, lowercase the rest
            $result[] = mb_strtoupper(mb_substr($line, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_strtolower(mb_substr($line, 1), 'UTF-8');
        }

        return implode("\n", $result);
    }

    /**
     * Upper first: uppercase first letter of entire text, lowercase the rest.
     */
    private function upperFirst(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $firstChar = mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8');
        $rest = mb_strtolower(mb_substr($text, 1), 'UTF-8');

        return $firstChar . $rest;
    }

    /**
     * Upper words: uppercase first letter of each word, lowercase the rest.
     */
    private function upperWords(string $text): string
    {
        $lines = explode("\n", $text);
        $result = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $result[] = '';
                continue;
            }

            $words = preg_split('/(\s+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($words === false) {
                $result[] = $line;
                continue;
            }

            $transformed = '';
            foreach ($words as $word) {
                // Preserve whitespace as-is
                if (preg_match('/^\s+$/u', $word)) {
                    $transformed .= $word;
                } elseif ($word !== '') {
                    // Transform the word: uppercase first, lowercase rest
                    $transformed .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                        . mb_strtolower(mb_substr($word, 1), 'UTF-8');
                }
            }
            $result[] = $transformed;
        }

        return implode("\n", $result);
    }

    /**
     * Calculate the natural dimensions of this transformer when rendered.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width;
        $h = $this->height;

        if ($w !== null && $h !== null) {
            return [$w, $h];
        }

        $content = $this->content->render();
        $lines = explode("\n", $content);

        $maxWidth = 0;
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth > $maxWidth) {
                $maxWidth = $lineWidth;
            }
        }

        return [$maxWidth, count($lines)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set uppercase transformation (all characters uppercase).
     */
    public function withUpper(): self
    {
        return new self(
            content: $this->content,
            transform: self::Upper,
        );
    }

    /**
     * Set lowercase transformation (all characters lowercase).
     */
    public function withLower(): self
    {
        return new self(
            content: $this->content,
            transform: self::Lower,
        );
    }

    /**
     * Set title case transformation (first letter uppercase, rest lowercase).
     */
    public function withTitle(): self
    {
        return new self(
            content: $this->content,
            transform: self::Title,
        );
    }

    /**
     * Set upper-first transformation (first char of text uppercase, rest lowercase).
     */
    public function withUpperFirst(): self
    {
        return new self(
            content: $this->content,
            transform: self::UpperFirst,
        );
    }

    /**
     * Set upper-words transformation (first char of each word uppercase, rest lowercase).
     */
    public function withUpperWords(): self
    {
        return new self(
            content: $this->content,
            transform: self::UpperWords,
        );
    }
}
