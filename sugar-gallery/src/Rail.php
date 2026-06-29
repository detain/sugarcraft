<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use SugarCraft\Sprinkles\Layout;

/**
 * A horizontal carousel of {@see PosterCard}s with a title, a cursor, and a
 * scroll offset. Immutable; the owning model moves the cursor and swaps in
 * loaded posters. Use a {@see PosterGrid} instead when you need a 2-D,
 * virtualized view of a large collection.
 */
final readonly class Rail
{
    /**
     * @param list<PosterCard> $cards
     */
    public function __construct(
        public string $title,
        public array $cards = [],
        public int $cursor = 0,
        public int $scroll = 0,
    ) {
    }

    /** Canonical factory — mirrors the public constructor. */
    public static function new(string $title, array $cards = [], int $cursor = 0, int $scroll = 0): self
    {
        return new self($title, $cards, $cursor, $scroll);
    }

    /**
     * Replace the card list, clamping the cursor/scroll into range.
     *
     * @param list<PosterCard> $cards
     */
    public function withCards(array $cards): self
    {
        $cursor = $cards === [] ? 0 : min($this->cursor, count($cards) - 1);

        return new self($this->title, $cards, $cursor, min($this->scroll, $cursor));
    }

    /** Replace a single card by id (e.g. when its poster finishes loading). */
    public function withCard(PosterCard $card): self
    {
        $cards = $this->cards;
        foreach ($cards as $i => $existing) {
            if ($existing->id === $card->id) {
                $cards[$i] = $card;
                break;
            }
        }

        return new self($this->title, $cards, $this->cursor, $this->scroll);
    }

    /** Return a copy with a different title (immutable+fluent). */
    public function withTitle(string $title): self
    {
        return new self($title, $this->cards, $this->cursor, $this->scroll);
    }

    /**
     * Return a copy with a different cursor, clamped to [0, count-1].
     * Scroll is clamped to cursor to keep the card visible; if a specific
     * scroll/cursor relationship is needed use moveCursor() instead.
     */
    public function withCursor(int $cursor): self
    {
        if ($this->cards === []) {
            return new self($this->title, [], 0, 0);
        }

        $cursor = max(0, min(count($this->cards) - 1, $cursor));
        $scroll = max(0, min($this->scroll, $cursor));

        return new self($this->title, $this->cards, $cursor, $scroll);
    }

    public function focusedCard(): ?PosterCard
    {
        return $this->cards[$this->cursor] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->cards === [];
    }

    /** Move the cursor by $delta, scrolling so it stays visible. */
    public function moveCursor(int $delta, int $perRow): self
    {
        if ($this->cards === []) {
            return $this;
        }

        $cursor = max(0, min(count($this->cards) - 1, $this->cursor + $delta));
        $perRow = max(1, $perRow);

        $scroll = $this->scroll;
        if ($cursor < $scroll) {
            $scroll = $cursor;
        } elseif ($cursor >= $scroll + $perRow) {
            $scroll = $cursor - $perRow + 1;
        }

        return new self($this->title, $this->cards, $cursor, $scroll);
    }

    /** How many cards of $cardWidth fit in $railWidth at $spacing gap. */
    public static function perRow(int $railWidth, int $cardWidth, int $spacing = 2): int
    {
        return max(1, intdiv($railWidth + $spacing, max(1, $cardWidth) + $spacing));
    }

    public function render(int $railWidth, bool $focused, int $cardWidth, int $posterHeight, int $spacing = 2): string
    {
        $count = count($this->cards);
        $head = ($focused ? '●' : '○') . ' ' . $this->title;
        if ($count > 0) {
            $head .= '  (' . ($this->cursor + 1) . '/' . $count . ')';
        }

        if ($this->cards === []) {
            return $head . "\n  (no items)";
        }

        $perRow = self::perRow($railWidth, $cardWidth, $spacing);

        // Render-time scroll clamp: ensure the focused card is always inside
        // the visible window even after a stale scroll (e.g. after withCards()
        // or a railWidth/cardWidth change between moves). Mirrors moveCursor()
        // math without mutating state — Rail is readonly.
        $scroll = $this->scroll;
        if ($this->cursor < $scroll) {
            $scroll = $this->cursor;
        } elseif ($this->cursor >= $scroll + $perRow) {
            $scroll = $this->cursor - $perRow + 1;
        }
        $scroll = max(0, $scroll);

        $visible = array_slice($this->cards, $scroll, $perRow);

        $blocks = [];
        foreach ($visible as $offset => $card) {
            $absolute = $scroll + $offset;
            $blocks[] = $card->render($focused && $absolute === $this->cursor, $cardWidth, $posterHeight);
        }

        return $head . "\n" . Layout::joinHorizontalWithSpacing(0.0, $spacing, ...$blocks);
    }
}
