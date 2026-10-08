<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

use SugarCraft\Sprinkles\Border\BorderTitle;
use SugarCraft\Sprinkles\Border\TitleAnchor;
use SugarCraft\Core\Util\Width;

/**
 * The 13 corner / edge / interior runes that make up a rectangular box
 * border. Outer runes drive Style boxes; the five middle-* runes drive
 * Table separators (column splits, row separators, cross intersections).
 *
 * Mirrors lipgloss `Border`. All runes must occupy a single terminal cell.
 *
 * Titles may be attached to any of six anchor positions via
 * {@see withTitle()} (laid onto the edge) or {@see withEmbeddedTitle()}
 * (bracketed by junctions, btop-style `─┐cpu┌─`).  Rendered by
 * {@see Style} when a border is applied. {@see seam()} answers the
 * junction rune where an internal divider meets the frame.
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
        return $this->withTitleList($titles);
    }

    /**
     * Attach a title EMBEDDED in the edge: bracketed by junction runes so
     * it reads as cut into the line — `╭─┐cpu┌───╮` on the top edge,
     * `╰─┘mem└───╯` on the bottom. Mirrors btop `Draw::createBox` (title /
     * title2 args) and `Draw::update_clock` (btop_draw.cpp, Symbols
     * `title_left ┐ / title_right ┌`, `title_left_down ┘ / title_right_down └`).
     *
     * Placement law (btop):
     *  - Left anchors sit one edge rune in from the corner (`╭─┐`).
     *  - Right anchors mirror that (`┌─╮`).
     *  - Center anchors place the opening junction at
     *    `floor(run/2) - floor(len/2)` edge runes into the run — btop's
     *    clock formula, which leans one cell right of true centre on
     *    even/even sizes; kept byte-faithful on purpose.
     *  - Adjacent embeds on one anchor are glued by one edge rune
     *    (`┐a┌─┐b┌`), so a left title + right clock is two anchors.
     *  - Overflow shrinks the text to fit with a trailing `…`
     *    (btop `uresize`); an embed with no room for one text cell plus
     *    both junctions is dropped and the edge stays a plain run.
     *
     * The text is painted verbatim inside the edge's title colour — pass
     * pre-styled text (e.g. a bold Style render) for btop's bold titles.
     *
     * Erasing a stale, wider embed when the text shrinks (btop's
     * `update_clock` overwrites the old span with h_lines) is deliberately
     * NOT provided: a re-render rebuilds the whole edge, and the cell-diff
     * renderer only repaints the cells that changed.
     *
     * @param string|null $open  Junction before the text; null derives it
     *                           from the border's corner on that side
     *                           (`┐` top / `┘` bottom; rounded corners map
     *                           to their square form as btop does).
     * @param string|null $close Junction after the text; null derives
     *                           `┌` top / `└` bottom likewise.
     * @throws \InvalidArgumentException When `$open`/`$close` is not exactly
     *                                   one terminal cell wide.
     */
    public function withEmbeddedTitle(
        string $text,
        ?TitleAnchor $anchor = null,
        ?string $open = null,
        ?string $close = null,
    ): self {
        // An empty embed would still paint `┐┌` — treat it as no title so
        // the edge stays byte-identical to an untitled border.
        if ($text === '') {
            return $this;
        }
        // The layout budgets exactly one cell per junction; anything else
        // would push the closing corner off the edge.
        foreach (['open' => $open, 'close' => $close] as $name => $rune) {
            if ($rune !== null && Width::string($rune) !== 1) {
                throw new \InvalidArgumentException(
                    "Embedded title \${$name} junction must be exactly one cell wide, got " . var_export($rune, true)
                );
            }
        }
        $anchor ??= TitleAnchor::TopLeft;
        $titles = $this->titles;
        $titles[$anchor->name][] = new BorderTitle($text, $anchor, embedded: true, open: $open, close: $close);
        return $this->withTitleList($titles);
    }

    /**
     * The default `[open, close]` junction pair for an embedded title on
     * the top (`$bottom = false`) or bottom edge. Taken from the border's
     * own opposite corners so every family stays consistent — `┐┌`/`┘└`
     * for normal and rounded, `┓┏`/`┛┗` thick, `╗╔`/`╝╚` double, `++`
     * ascii — with the rounded arcs squared off, matching btop which
     * always embeds with square junctions even on rounded boxes.
     *
     * @return array{0:string,1:string}
     */
    public function embedJunctions(bool $bottom = false): array
    {
        $square = ['╭' => '┌', '╮' => '┐', '╰' => '└', '╯' => '┘'];
        return $bottom
            ? [$square[$this->bottomRight] ?? $this->bottomRight, $square[$this->bottomLeft] ?? $this->bottomLeft]
            : [$square[$this->topRight] ?? $this->topRight, $square[$this->topLeft] ?? $this->topLeft];
    }

    /**
     * The rune for a point where lines leave in the given directions —
     * the junction a seam (an internal divider) makes where it meets a
     * frame or another seam. btop's divider law (Symbols `div_left ├`,
     * `div_right ┤`, `div_up ┬`, `div_down ┴`): a vertical seam landing on
     * the top edge is `seam(left: true, right: true, down: true)` → `┬`.
     *
     * Every answer comes from this border's own runes, so the family is
     * preserved (thick → `┳`, double → `╦`). Two-arm turns use the
     * corners as-is (rounded borders keep their arcs). A single arm has no
     * half-line rune in the Border model, so it degrades to the straight
     * edge rune on that axis; no arms is a blank cell.
     */
    public function seam(bool $left, bool $right, bool $up, bool $down): string
    {
        return match ([$left, $right, $up, $down]) {
            [false, false, false, false] => ' ',
            [true,  true,  true,  true]  => $this->middle,
            [true,  true,  false, true]  => $this->middleTop,
            [true,  true,  true,  false] => $this->middleBottom,
            [false, true,  true,  true]  => $this->middleLeft,
            [true,  false, true,  true]  => $this->middleRight,
            [false, true,  false, true]  => $this->topLeft,
            [true,  false, false, true]  => $this->topRight,
            [false, true,  true,  false] => $this->bottomLeft,
            [true,  false, true,  false] => $this->bottomRight,
            [true,  true,  false, false],
            [true,  false, false, false],
            [false, true,  false, false] => $this->top,
            default                      => $this->left, // up+down, up-only, down-only
        };
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
        return $this->withTitleList($titles);
    }

    /** @param array<string, list<BorderTitle>> $titles */
    private function withTitleList(array $titles): self
    {
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
     * Attached titles, keyed by {@see TitleAnchor} case name, each anchor's
     * list in insertion order.
     *
     * @return array<string, list<BorderTitle>>
     */
    public function titles(): array
    {
        return $this->titles;
    }

    /**
     * Alias of {@see titles()}, kept so existing callers keep compiling.
     *
     * @deprecated Use the bare accessor {@see titles()}.
     * @return array<string, list<BorderTitle>>
     */
    public function getTitles(): array
    {
        return $this->titles();
    }
}
