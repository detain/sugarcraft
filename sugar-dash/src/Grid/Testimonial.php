<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A testimonial quote display component.
 *
 * Displays a customer testimonial with quote text, author name,
 * and optional role/company information. Supports decorative styling.
 *
 * Mirrors testimonial/quote-card concepts adapted to PHP with wither-style immutable setters.
 */
final class Testimonial implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{text: string, author: string, role?: string, company?: string, avatar?: string}> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly ?Color $quoteColor = null,
        private readonly ?Color $authorColor = null,
        private readonly ?Color $roleColor = null,
        private readonly ?Color $accentColor = null,
        private readonly string $openQuote = '「',
        private readonly string $closeQuote = '」',
    ) {}

    /**
     * Create a new testimonial with default styling.
     *
     * @param array<int, array{text: string, author: string, role?: string, company?: string, avatar?: string}> $testimonials
     */
    public static function new(array $testimonials): self
    {
        return new self(
            items: array_map(fn($t) => [
                'text' => $t['text'] ?? '',
                'author' => $t['author'] ?? '',
                'role' => $t['role'] ?? '',
                'company' => $t['company'] ?? '',
                'avatar' => $t['avatar'] ?? null,
            ], $testimonials),
            quoteColor: Color::hex('#FAFAFA'),
            authorColor: Color::hex('#FAFAFA'),
            roleColor: Color::hex('#A1A1AA'),
            accentColor: Color::hex('#A78BFA'),
            openQuote: '「',
            closeQuote: '」',
        );
    }

    /**
     * Create a single testimonial with centered styling.
     */
    public static function single(array $testimonial): self
    {
        return self::new([$testimonial]);
    }

    /**
     * Set the allocated dimensions for this testimonial.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the testimonial as a string.
     */
    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $useWidth = $this->getWidth();
        $lines = [];

        foreach ($this->items as $index => $item) {
            if ($index > 0) {
                // Add separator between multiple testimonials
                $lines[] = '';
            }

            $lines = array_merge($lines, $this->renderSingleTestimonial($item, $useWidth));
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single testimonial item.
     *
     * @param array{text: string, author: string, role?: string, company?: string, avatar?: string} $item
     * @return array<int, string>
     */
    private function renderSingleTestimonial(array $item, int $width): array
    {
        $lines = [];
        $text = $item['text'] ?? '';
        $author = $item['author'] ?? '';
        $role = $item['role'] ?? '';
        $company = $item['company'] ?? '';
        $avatar = $item['avatar'] ?? null;

        // Calculate content width (leave room for quotes)
        $contentWidth = $width - 4;

        // Top border with accent color
        if ($this->accentColor !== null) {
            $lines[] = $this->accentColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
        } else {
            $lines[] = str_repeat('─', $width);
        }

        // Opening quote
        if ($this->quoteColor !== null) {
            $lines[] = $this->quoteColor->toFg(ColorProfile::TrueColor);
        }
        $lines[] = '  ' . $this->openQuote . ' ';
        $lines[] = Ansi::reset();

        // Word-wrap the text
        $textLines = $this->wordWrap($text, $contentWidth);
        foreach ($textLines as $line) {
            if ($this->quoteColor !== null) {
                $lines[] = $this->quoteColor->toFg(ColorProfile::TrueColor);
            }
            $lines[] = '  ' . str_pad($line, $contentWidth);
            $lines[] = Ansi::reset();
        }

        // Closing quote
        if ($this->quoteColor !== null) {
            $lines[] = $this->quoteColor->toFg(ColorProfile::TrueColor);
        }
        $lines[] = '  ' . $this->closeQuote . ' ';
        $lines[] = Ansi::reset();

        // Author info
        $authorLine = '';
        if ($avatar !== null) {
            $authorLine .= '[' . $avatar . '] ';
        }
        $authorLine .= '— ' . $author;

        if ($role !== '') {
            $authorLine .= ', ';
        }

        if ($this->authorColor !== null) {
            $lines[] = $this->authorColor->toFg(ColorProfile::TrueColor);
        }
        $lines[] = str_pad($authorLine, $width);
        $lines[] = Ansi::reset();

        // Role and company
        if ($role !== '' || $company !== '') {
            $roleLine = $role;
            if ($role !== '' && $company !== '') {
                $roleLine .= ' at ';
            }
            $roleLine .= $company;

            if ($this->roleColor !== null) {
                $lines[] = $this->roleColor->toFg(ColorProfile::TrueColor);
            }
            $lines[] = str_pad($roleLine, $width);
            $lines[] = Ansi::reset();
        }

        // Bottom border
        if ($this->accentColor !== null) {
            $lines[] = $this->accentColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
        } else {
            $lines[] = str_repeat('─', $width);
        }

        return $lines;
    }

    /**
     * Word wrap text to fit within a given width.
     *
     * @return array<int, string>
     */
    private function wordWrap(string $text, int $width): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $wordLen = Width::string($word);

            if ($currentLine === '') {
                $currentLine = $word;
            } elseif (Width::string($currentLine) + 1 + $wordLen <= $width) {
                $currentLine .= ' ' . $word;
            } else {
                $lines[] = $currentLine;
                $currentLine = $word;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    /**
     * Calculate the natural dimensions of this testimonial.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        // Calculate height based on content
        $totalHeight = 0;
        foreach ($this->items as $item) {
            $text = $item['text'] ?? '';
            $textLines = count($this->wordWrap($text, $width - 4));
            $totalHeight += 2 + $textLines + 3; // top border + text + quote + author info + bottom border
            $totalHeight += 2; // separator between testimonials
        }

        return [$width, max(0, $totalHeight - 2)]; // subtract last separator
    }

    /**
     * Get the width to use for this testimonial.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Default width of 60 characters
        return 60;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the testimonial items.
     *
     * @param array<int, array{text: string, author: string, role?: string, company?: string, avatar?: string}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: array_map(fn($t) => [
                'text' => $t['text'] ?? '',
                'author' => $t['author'] ?? '',
                'role' => $t['role'] ?? '',
                'company' => $t['company'] ?? '',
                'avatar' => $t['avatar'] ?? null,
            ], $items),
            quoteColor: $this->quoteColor,
            authorColor: $this->authorColor,
            roleColor: $this->roleColor,
            accentColor: $this->accentColor,
            openQuote: $this->openQuote,
            closeQuote: $this->closeQuote,
        );
    }

    /**
     * Set the quote text color.
     */
    public function withQuoteColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            quoteColor: $color,
            authorColor: $this->authorColor,
            roleColor: $this->roleColor,
            accentColor: $this->accentColor,
            openQuote: $this->openQuote,
            closeQuote: $this->closeQuote,
        );
    }

    /**
     * Set the author name color.
     */
    public function withAuthorColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            quoteColor: $this->quoteColor,
            authorColor: $color,
            roleColor: $this->roleColor,
            accentColor: $this->accentColor,
            openQuote: $this->openQuote,
            closeQuote: $this->closeQuote,
        );
    }

    /**
     * Set the role/company color.
     */
    public function withRoleColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            quoteColor: $this->quoteColor,
            authorColor: $this->authorColor,
            roleColor: $color,
            accentColor: $this->accentColor,
            openQuote: $this->openQuote,
            closeQuote: $this->closeQuote,
        );
    }

    /**
     * Set the accent/border color.
     */
    public function withAccentColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            quoteColor: $this->quoteColor,
            authorColor: $this->authorColor,
            roleColor: $this->roleColor,
            accentColor: $color,
            openQuote: $this->openQuote,
            closeQuote: $this->closeQuote,
        );
    }

    /**
     * Set the quote characters.
     */
    public function withQuoteChars(string $open, string $close): self
    {
        return new self(
            items: $this->items,
            quoteColor: $this->quoteColor,
            authorColor: $this->authorColor,
            roleColor: $this->roleColor,
            accentColor: $this->accentColor,
            openQuote: $open,
            closeQuote: $close,
        );
    }
}
