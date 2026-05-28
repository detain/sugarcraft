<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * A single terminal cell — the atom of buffer rendering.
 *
 * A cell holds: a displayed rune (or grapheme cluster), an optional
 * inline {@see Style}, an optional OSC 8 {@see Hyperlink}, and a
 * display-cell width (1 for normal, 2 for wide chars like CJK/emoji).
 *
 * When width is 2 the next adjacent cell in the buffer MUST be an
 * empty "continuation" cell (rune '', width 0) — callers are
 * responsible for creating and placing it.
 *
 * Cell is a readonly value object: rebuild via new rather than with*().
 *
 * Mirrors charmbracelet/lipgloss's Cell and the charmbracelet/vte
 * terminal cell representation.
 *
 * @readonly
 */
final class Cell
{
    /**
     * @param string      $rune   Displayed grapheme(s) — empty string for continuation cells
     * @param Style|null $style  Per-cell style or null for defaults
     * @param Hyperlink|null $link OSC 8 hyperlink or null
     * @param int         $width  Display width in cells (1 or 2; 0 for continuation)
     */
    public function __construct(
        public readonly string $rune,
        public readonly ?Style $style,
        public readonly ?Hyperlink $link,
        public readonly int $width,
    ) {}

    /**
     * Default factory — blank cell.
     */
    public static function new(
        string $rune = ' ',
        ?Style $style = null,
        ?Hyperlink $link = null,
        int $width = 1,
    ): self {
        return new self($rune, $style, $link, $width);
    }

    /**
     * Factory: a blank continuation cell for wide-char neighbours.
     * Continuation cells have empty rune and zero width.
     */
    public static function continuation(): self
    {
        return new self('', null, null, 0);
    }

    /** Displayed grapheme(s). */
    public function rune(): string { return $this->rune; }

    /** Per-cell style (or null for default rendering). */
    public function style(): ?Style { return $this->style; }

    /** OSC 8 hyperlink anchor, or null. */
    public function link(): ?Hyperlink { return $this->link; }

    /**
     * Display width in terminal cells.
     * @return int 0 (continuation), 1 (normal), or 2 (wide/CJK/emoji)
     */
    public function width(): int { return $this->width; }
}
