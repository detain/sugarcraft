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
        $visible = array_slice($this->cards, $this->scroll, $perRow);

        $blocks = [];
        foreach ($visible as $offset => $card) {
            $absolute = $this->scroll + $offset;
            $blocks[] = $card->render($focused && $absolute === $this->cursor, $cardWidth, $posterHeight);
        }

        return $head . "\n" . Layout::joinHorizontalWithSpacing(0.0, $spacing, ...$blocks);
    }
}
