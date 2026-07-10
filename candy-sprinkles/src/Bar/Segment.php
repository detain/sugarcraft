<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Bar;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * A single themed piece of text inside a {@see StatusBar}.
 *
 * Holds a raw text run plus an optional {@see Style}. Rendering applies
 * the style (if any) to the text; width is always measured against the
 * *visible* text so laying segments out ignores the SGR overhead.
 *
 * Immutable — every setter returns a new instance via {@see mutate()}.
 */
final class Segment
{
    use Mutable;

    public function __construct(
        public readonly string $text = '',
        public readonly ?Style $style = null,
    ) {}

    /** Empty segment (no text, no style). */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Build a segment from a text run and an optional {@see Style}.
     */
    public static function of(string $text, ?Style $style = null): self
    {
        return new self($text, $style);
    }

    /** Replace the text run. */
    public function withText(string $text): self
    {
        return $this->mutate(['text' => $text]);
    }

    /** Replace (or clear, with null) the segment style. */
    public function withStyle(?Style $style): self
    {
        return $this->mutate(['style' => $style]);
    }

    /** Bound text run. */
    public function text(): string
    {
        return $this->text;
    }

    /** Bound style, or null when the text renders raw. */
    public function style(): ?Style
    {
        return $this->style;
    }

    /** Visible cell width of the text (the raw text carries no SGR). */
    public function width(): int
    {
        return Width::string($this->text);
    }

    /** True when the text run is empty (renders to nothing). */
    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /**
     * Render the text through its style. Returns the raw text unchanged
     * when no style is bound.
     */
    public function render(): string
    {
        return $this->style !== null ? $this->style->render($this->text) : $this->text;
    }
}
