<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

use SugarCraft\Sprinkles\Border\BorderTitle;
use SugarCraft\Sprinkles\Border\TitleAnchor;

/**
 * The 13 corner / edge / interior runes that make up a rectangular box
 * border. Outer runes drive Style boxes; the five middle-* runes drive
 * Table separators (column splits, row separators, cross intersections).
 *
 * Mirrors lipgloss `Border`. All runes must occupy a single terminal cell.
 *
 * Titles may be attached to any of six anchor positions via
 * {@see withTitle()}.  Rendered by {@see Style} when a border is applied.
 */
final class Border
{
    /**
     * @param array<string, list<BorderTitle>> $titles Keys are TitleAnchor case names
     */
    public function __construct(
        public readonly string $top,
        public readonly string $bottom,
        public readonly string $left,
        public readonly string $right,
        public readonly string $topLeft,
        public readonly string $topRight,
        public readonly string $bottomLeft,
        public readonly string $bottomRight,
        public readonly string $middleLeft = ' ',
        public readonly string $middleRight = ' ',
        public readonly string $middle = ' ',
        public readonly string $middleTop = ' ',
        public readonly string $middleBottom = ' ',
        private readonly array $titles = [],
    ) {}

    public static function normal(): self
    {
        return new self(
            '─', '─', '│', '│', '┌', '┐', '└', '┘',
            middleLeft: '├', middleRight: '┤', middle: '┼',
            middleTop: '┬', middleBottom: '┴',
        );
    }

    public static function rounded(): self
    {
        return new self(
            '─', '─', '│', '│', '╭', '╮', '╰', '╯',
            middleLeft: '├', middleRight: '┤', middle: '┼',
            middleTop: '┬', middleBottom: '┴',
        );
    }

    public static function thick(): self
    {
        return new self(
            '━', '━', '┃', '┃', '┏', '┓', '┗', '┛',
            middleLeft: '┣', middleRight: '┫', middle: '╋',
            middleTop: '┳', middleBottom: '┻',
        );
    }

    public static function double(): self
    {
        return new self(
            '═', '═', '║', '║', '╔', '╗', '╚', '╝',
            middleLeft: '╠', middleRight: '╣', middle: '╬',
            middleTop: '╦', middleBottom: '╩',
        );
    }

    public static function block(): self
    {
        return new self('█', '█', '█', '█', '█', '█', '█', '█');
    }

    public static function ascii(): self
    {
        return new self(
            '-', '-', '|', '|', '+', '+', '+', '+',
            middleLeft: '+', middleRight: '+', middle: '+',
            middleTop: '+', middleBottom: '+',
        );
    }

    public static function hidden(): self
    {
        return new self(' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ');
    }

    /**
     * GitHub-flavored Markdown table border. Mirrors lipgloss's
     * `Border::markdownBorder()` — pipes for the verticals, dashes
     * for the horizontals, plain `|` corners. Useful when an
     * already-rendered Sprinkles\Table needs to round-trip through a
     * Markdown reader without losing its grid.
     */
    public static function markdownBorder(): self
    {
        return new self(
            '-', '-', '|', '|', '|', '|', '|', '|',
            middleLeft: '|', middleRight: '|', middle: '|',
            middleTop: '|', middleBottom: '|',
        );
    }

    /**
     * Enumerate the names of all built-in border factories.
     *
     * Each entry maps to a same-named zero-arg static factory on this class
     * (e.g. `'rounded'` → {@see Border::rounded()}). Listed in declaration
     * order. Enables programmatic discovery (e.g. a `--list-borders` command).
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return ['normal', 'rounded', 'thick', 'double', 'block', 'ascii', 'hidden', 'markdownBorder'];
    }

    /**
     * Attach a title to the border.
     *
     * Multiple titles may be attached to the same anchor; they are
     * concatenated in insertion order with a space separator.
     * Mirrors ratatui `Block::title()`.
     *
     * @param string $text       The title text (may contain ANSI sequences)
     * @param TitleAnchor|null $anchor Defaults to TopLeft for backward
     *                                 compatibility with a single positional arg.
     */
    public function withTitle(string $text, ?TitleAnchor $anchor = null): self
    {
        $anchor ??= TitleAnchor::TopLeft;
        $titles = $this->titles;
        $titles[$anchor->name][] = new BorderTitle($text, $anchor);
        return new self(
            $this->top,
            $this->bottom,
            $this->left,
            $this->right,
            $this->topLeft,
            $this->topRight,
            $this->bottomLeft,
            $this->bottomRight,
            $this->middleLeft,
            $this->middleRight,
            $this->middle,
            $this->middleTop,
            $this->middleBottom,
            $titles,
        );
    }

    /**
     * Attach multiple titles in bulk, replacing any previously set.
     *
     * @param array<TitleAnchor|string, list<string>|string> $map Map of anchor → title text(s)
     */
    public function withTitles(array $map): self
    {
        $titles = [];
        foreach ($map as $anchorRaw => $texts) {
            // Normalize key to string name (handles TitleAnchor enum, string, or int keys)
            if ($anchorRaw instanceof TitleAnchor) {
                $anchorName = $anchorRaw->name;
                $anchorEnum = $anchorRaw;
            } else {
                $anchorName = (string) $anchorRaw;
                $anchorEnum = match ($anchorName) {
                    'TopLeft'      => TitleAnchor::TopLeft,
                    'TopCenter'    => TitleAnchor::TopCenter,
                    'TopRight'     => TitleAnchor::TopRight,
                    'BottomLeft'   => TitleAnchor::BottomLeft,
                    'BottomCenter' => TitleAnchor::BottomCenter,
                    'BottomRight'  => TitleAnchor::BottomRight,
                    default => throw new \InvalidArgumentException("Unknown title anchor: $anchorName"),
                };
            }
            foreach ((array) $texts as $text) {
                $titles[$anchorName][] = new BorderTitle((string) $text, $anchorEnum);
            }
        }
        return new self(
            $this->top,
            $this->bottom,
            $this->left,
            $this->right,
            $this->topLeft,
            $this->topRight,
            $this->bottomLeft,
            $this->bottomRight,
            $this->middleLeft,
            $this->middleRight,
            $this->middle,
            $this->middleTop,
            $this->middleBottom,
            $titles,
        );
    }

    /**
     * @return array<string, list<BorderTitle>>
     */
    public function getTitles(): array
    {
        return $this->titles;
    }
}
