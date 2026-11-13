<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use InvalidArgumentException;
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
     * printing e.g. "(1000/2)" with a phantom empty row. Every with*-carrier
     * rides the same clamp via {@see carrying()};
     * the render-time window re-clamp in {@see windowedScroll()} still handles
     * perRow changes between moves.
     *
     * @param list<PosterCard> $cards
     * @param array{railWidth:int, cardWidth:int, spacing:int}|null $geometry
     *                          Optional stored layout (see {@see withGeometry()});
     *                          null until a host stores one.
     */
    public function __construct(
        public string $title,
        public array $cards = [],
        int $cursor = 0,
        int $scroll = 0,
        public ?array $geometry = null,
    ) {
        $last = $this->cards === [] ? 0 : count($this->cards) - 1;
        $cursor = max(0, min($last, $cursor));

        $this->cursor = $cursor;
        $this->scroll = max(0, min($cursor, $scroll));
    }

    /** Canonical factory — mirrors the public constructor. */
    public static function new(string $title, array $cards = [], int $cursor = 0, int $scroll = 0, ?array $geometry = null): self
    {
        return new self($title, $cards, $cursor, $scroll, $geometry);
    }

    /**
     * Store the layout tuple (`railWidth`, `cardWidth`, `spacing`) on the rail
     * so `moveCursor()` can derive its window without the host re-threading
     * the numbers (sugar-gallery-001). Additive: explicit call-site values
     * always win, and `render()` keeps taking its own geometry — this stores
     * the same three numbers in ONE place for the zero-arg move path.
     */
    public function withGeometry(int $railWidth, int $cardWidth, int $spacing = 2): self
    {
        return $this->carrying([
            'geometry' => ['railWidth' => $railWidth, 'cardWidth' => $cardWidth, 'spacing' => $spacing],
        ]);
    }

    /**
     * The one carrier law (mirrors PosterCard::carrying()): every with-family
     * and move-family mutator rebuilds through a full
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
            'geometry' => $this->geometry,
        ];

        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $fields)) {
                throw new InvalidArgumentException("{$name} is not a constructor field of " . self::class);
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
     * (this was pinned by tests but undocumented).
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

    /**
     * Move the cursor by $delta, scrolling so it stays visible. Omit $perRow
     * to use the stored geometry set by {@see withGeometry()}; an explicit
     * $perRow always wins (override). With no stored geometry and no
     * argument, the call fails loud naming both remedies.
     */
    public function moveCursor(int $delta, ?int $perRow = null): self
    {
        if ($this->cards === []) {
            return $this;
        }

        if ($perRow === null) {
            if ($this->geometry === null) {
                throw new InvalidArgumentException(
                    'Rail::moveCursor() needs a $perRow argument or stored geometry — pass $perRow or build the rail via withGeometry() first',
                );
            }
            $perRow = self::perRow($this->geometry['railWidth'], $this->geometry['cardWidth'], $this->geometry['spacing']);
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
     * render-time re-clamp. The result is always >= 0, so a rail constructed
     * with a
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

    /**
     * How many cards of $cardWidth fit in $railWidth at $spacing gap. The
     * clamps here are the ONE row-fit law: they mirror the grid's constructor
     * floors (`cardWidth ≥ 4`) and the rail's render-head clamps, so direct
     * callers and `render()` can never disagree about the window (each card
     * paints at 4 cells minimum no matter what it is asked for — a perRow
     * computed from an unfloored cardWidth promises more slots than fit).
     */
    public static function perRow(int $railWidth, int $cardWidth, int $spacing = 2): int
    {
        return max(1, intdiv(max(1, $railWidth) + max(0, $spacing), max(4, $cardWidth) + max(0, $spacing)));
    }

    public function render(int $railWidth, bool $focused, int $cardWidth, int $posterHeight, int $spacing = 2): string
    {
        // Clamp the degenerate edges ONCE, at the method head, and let every
        // use below ride the clamped values (sugar-gallery-005/-021: the
        // header floored the rail while perRow() ran raw, so railWidth=60 at
        // cardWidth=2 painted 88-cell rows — fifteen 4-wide cards the
        // unfloored division happily promised). These are the same floors
        // PosterGrid::new() enforces at construction; perRow() re-applies
        // them for direct callers.
        $railWidth = max(1, $railWidth);
        $cardWidth = max(4, $cardWidth);
        $spacing = max(0, $spacing);

        $count = count($this->cards);
        // The rail title rides the SAME trust boundary the plain card title
        // documents: titles are DB/server-sourced, so the exact scanner
        // PosterCard::render() runs ({@see AnsiGuard::stripControls()}) gates
        // the heading too — a server-returned title can move no cursor, clear
        // no screen, and smuggle no OSC-8 hyperlink into the frame.
        // Both header lines are then clamped to $railWidth so an over-long
        // title cannot overflow the rail (the width twin of the README's
        // exact-width invariant).
        $prefix = ($focused ? '●' : '○') . ' ';
        $counter = $count > 0 ? '  (' . ($this->cursor + 1) . '/' . $count . ')' : '';
        $title = AnsiGuard::stripControls($this->title);

        // The position/total counter is DATA, not decoration: it is reserved
        // BEFORE the title is trimmed, so a narrow rail truncates the title
        // and the counter survives whole. Before this the whole line went
        // through one clamp and the counter died first.
        if (Width::string($prefix . $title . $counter) > $railWidth) {
            $room = $railWidth - Width::string($prefix . $counter) - 1;
            $title = $room > 0 ? Width::truncate($title, $room) . '…' : '';
        }

        $head = self::fitHead($prefix . $title . $counter, $railWidth);

        if ($this->cards === []) {
            // The one prose string in this widget is a translation lookup,
            // not a literal (lang/ is wired into sugar-core's T registry by
            // src/Lang.php). The two-space indent
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
