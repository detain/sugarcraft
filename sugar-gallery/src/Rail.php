<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Layout;

/**
 * A horizontal carousel of {@see PosterCard}s with a title, a cursor, and a
 * scroll offset. Immutable; the owning model moves the cursor and swaps in
 * loaded posters. Use a {@see PosterGrid} instead when you need a 2-D,
 * virtualized view of a large collection.
 */
final readonly class Rail
{
    public readonly int $cursor;
    public readonly int $scroll;

    /**
     * The taught construction path is "restore a saved (cursor, scroll) pair",
     * so the constructor CLAMPS rather than validates: the cursor lands inside
     * [0, count-1] and the scroll at or below it, instead of a stale restore
     * printing e.g. "(1000/2)" with a phantom empty row (2026-11-03 review
     * m10). Every with*-carrier rides the same clamp via {@see carrying()};
     * the render-time window re-clamp in {@see windowedScroll()} still handles
     * perRow changes between moves.
     *
     * @param list<PosterCard> $cards
     */
    public function __construct(
        public string $title,
        public array $cards = [],
        int $cursor = 0,
        int $scroll = 0,
    ) {
        $last = $this->cards === [] ? 0 : count($this->cards) - 1;
        $cursor = max(0, min($last, $cursor));

        $this->cursor = $cursor;
        $this->scroll = max(0, min($cursor, $scroll));
    }

    /** Canonical factory — mirrors the public constructor. */
    public static function new(string $title, array $cards = [], int $cursor = 0, int $scroll = 0): self
    {
        return new self($title, $cards, $cursor, $scroll);
    }

    /**
     * The one carrier law (mirrors PosterCard::carrying() — 2026-11-03 review
     * M9): every with-family and move-family mutator rebuilds through a full
     * state snapshot plus named overrides, so a field added to the
     * constructor can never be silently dropped by a hand-rolled positional
     * reconstruction again.
     *
     * @param array<string, mixed> $overrides
     */
    private function carrying(array $overrides): self
    {
        $fields = [
            'title' => $this->title,
            'cards' => $this->cards,
            'cursor' => $this->cursor,
            'scroll' => $this->scroll,
        ];

        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $fields)) {
                throw new \InvalidArgumentException("{$name} is not a constructor field of " . self::class);
            }
            $fields[$name] = $value;
        }

        return new self(...$fields);
    }

    /**
     * Replace the card list; the constructor clamp pulls cursor/scroll back
     * into range for the new list.
     *
     * @param list<PosterCard> $cards
     */
    public function withCards(array $cards): self
    {
        return $this->carrying(['cards' => $cards]);
    }

    /**
     * Replace a single card by id (e.g. when its poster finishes loading).
     * A card whose id is not in the list is a SILENT no-op: the copy is
     * returned with the original cards, cursor, and scroll untouched
     * (2026-11-03 review m1 — this was pinned by tests but undocumented).
     */
    public function withCard(PosterCard $card): self
    {
        $cards = $this->cards;
        foreach ($cards as $i => $existing) {
            if ($existing->id === $card->id) {
                $cards[$i] = $card;
                break;
            }
        }

        return $this->carrying(['cards' => $cards]);
    }

    /** Return a copy with a different title (immutable+fluent). */
    public function withTitle(string $title): self
    {
        return $this->carrying(['title' => $title]);
    }

    /**
     * Return a copy with a different cursor, clamped to [0, count-1].
     * Scroll is clamped to at most the cursor so the card stays visible.
     * To restore an arbitrary (cursor, scroll) pair, construct the rail
     * directly: `new Rail($title, $cards, $cursor, $scroll)` (or {@see new()},
     * same signature) — the constructor applies the SAME clamp, so a stale
     * pair degrades to the nearest legal window rather than rendering lies.
     */
    public function withCursor(int $cursor): self
    {
        return $this->carrying(['cursor' => $cursor]);
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

        return $this->carrying([
            'cursor' => $cursor,
            'scroll' => self::windowedScroll($cursor, $this->scroll, max(1, $perRow)),
        ]);
    }

    /**
     * The minimum scroll that keeps $cursor inside a $perRow-wide window —
     * the ONE copy of this math, shared by {@see moveCursor()} and the
     * render-time re-clamp (it was twin text in both before docs-review-1023
     * NIT-dup). The result is always >= 0, so a rail constructed with a
     * negative scroll normalises on the first move as well as on render.
     */
    private static function windowedScroll(int $cursor, int $scroll, int $perRow): int
    {
        if ($cursor < $scroll) {
            return max(0, $cursor);
        }

        if ($cursor >= $scroll + $perRow) {
            return max(0, $cursor - $perRow + 1);
        }

        return max(0, $scroll);
    }

    /** How many cards of $cardWidth fit in $railWidth at $spacing gap. */
    public static function perRow(int $railWidth, int $cardWidth, int $spacing = 2): int
    {
        return max(1, intdiv($railWidth + $spacing, max(1, $cardWidth) + $spacing));
    }

    public function render(int $railWidth, bool $focused, int $cardWidth, int $posterHeight, int $spacing = 2): string
    {
        $count = count($this->cards);
        // The rail title rides the SAME trust boundary the plain card title
        // documents: titles are DB/server-sourced, so the exact scanner
        // PosterCard::render() runs ({@see AnsiGuard::stripControls()}) gates
        // the heading too — a server-returned title can move no cursor, clear
        // no screen, and smuggle no OSC-8 hyperlink into the frame
        // (docs-review-1023 M4). Both header lines are then clamped to
        // $railWidth so an over-long title cannot overflow the rail (M4's
        // width twin of the README's exact-width invariant).
        $prefix = ($focused ? '●' : '○') . ' ';
        $counter = $count > 0 ? '  (' . ($this->cursor + 1) . '/' . $count . ')' : '';
        $title = AnsiGuard::stripControls($this->title);

        // The position/total counter is DATA, not decoration: it is reserved
        // BEFORE the title is trimmed, so a narrow rail truncates the title
        // and the counter survives whole. Before this the whole line went
        // through one clamp and the counter died first (2026-11-03 review M3).
        if (Width::string($prefix . $title . $counter) > max(1, $railWidth)) {
            $room = max(1, $railWidth) - Width::string($prefix . $counter) - 1;
            $title = $room > 0 ? Width::truncate($title, $room) . '…' : '';
        }

        $head = self::fitHead($prefix . $title . $counter, $railWidth);

        if ($this->cards === []) {
            // The one prose string in this widget is a translation lookup,
            // not a literal (docs-review-1023 M2/M3 — lang/ was wired into
            // sugar-core's T registry by src/Lang.php). The two-space indent
            // stays structural, outside the translated value.
            return $head . "\n" . self::fitHead('  ' . Lang::t('rail.empty'), $railWidth);
        }

        $perRow = self::perRow($railWidth, $cardWidth, $spacing);

        // Render-time scroll clamp: ensure the focused card is always inside
        // the visible window even after a stale scroll (e.g. after withCards()
        // or a railWidth/cardWidth change between moves). Same math as
        // moveCursor() via windowedScroll() — Rail is readonly, nothing mutates.
        $scroll = self::windowedScroll($this->cursor, $this->scroll, $perRow);

        $visible = array_slice($this->cards, $scroll, $perRow);

        $blocks = [];
        foreach ($visible as $offset => $card) {
            $absolute = $scroll + $offset;
            $blocks[] = $card->render($focused && $absolute === $this->cursor, $cardWidth, $posterHeight);
        }

        return $head . "\n" . Layout::joinHorizontalWithSpacing(0.0, $spacing, ...$blocks);
    }

    /**
     * Cap a plain (already control-stripped) header line at $max visible
     * cells, with an ellipsis on overflow — the same shape
     * PosterCard::render() uses for plain titles, so both header rows keep
     * the "every row fits the rail" invariant regardless of locale. After
     * the counter-reservation pass this is the safety net for the extreme
     * widths where chrome alone cannot fit.
     */
    private static function fitHead(string $line, int $max): string
    {
        $max = max(1, $max);

        return Width::string($line) <= $max
            ? $line
            : Width::truncate($line, max(0, $max - 1)) . '…';
    }
}
