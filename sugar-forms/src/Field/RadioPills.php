<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Forms\CarriesNonCtorState;
use SugarCraft\Forms\Field;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\HasReadonly;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\Util\RenderSafe;

/**
 * Horizontal radio-pill row (crush_media W3.3, droppable half of plan
 * :433-445): a single-choice widget in the A1111 mask-mode-radio shape —
 * Confirm's raw-reverse pill idiom stretched over an option list.
 *
 * The value IS the highlighted pill (radio semantics, not picker browse
 * semantics): every cursor move commits. That places this widget on the
 * VALUE-EDITOR side of the E736 5.10 readonly split — the whole mutating
 * message set (arrows included) is refused while read-only, unlike Select,
 * whose browse cursor is sanctioned navigation.
 *
 * Keys while focused:
 *   <- / h     previous option (saturating)     Home  first option
 *   -> / l     next option (saturating)         End   last option
 *
 * Up/Down/Enter/Tab stay with the Form — the row moves on its own axis
 * only, so it consumes nothing. Mouse (crush_media W3.2 dispatch shape): a
 * left-button Press/Motion/Release landing on the pill row selects the pill
 * under the cursor; the three-cell gaps between pills are deliberate dead
 * zones and wheel is refused (crush's Viewport maps it to Up/Down today).
 *
 * Cell widths are codepoint counts of the clean labels plus one pad cell on
 * each side — single-width labels assumed, exactly like Confirm's pills.
 *
 * Horizontal window (opt-in via {@see withWidth()}): when the roster is
 * wider than the budget the row becomes a tab strip — a window of pills
 * that always contains the selection, carrying a plain ellipsis marker on
 * each side where pills were left behind (no marker on a side with nothing
 * clipped). Without a width the row stays the full unwindowed line, byte
 * for byte as it always was. Mouse clicks hit-test the windowed layout the
 * row actually renders, markers and gaps alike.
 */
final class RadioPills implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;
    use HasReadonly;
    use CarriesNonCtorState;

    /** Cells of separator between pills. */
    private const GAP = 3;

    /** Continuation marker on the side where pills were windowed out. */
    private const ELLIPSIS = '…';

    /**
     * @param list<string> $options
     * @param ?int         $width available cells for the pill row, null = unbounded
     */
    private function __construct(
        public readonly string $key,
        public readonly array $options,
        public readonly int $cursor,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        public readonly ?int $width = null,
    ) {
    }

    /**
     * @param list<string>|string ...$options one list or bare strings
     */
    public static function new(string $key, array|string ...$options): self
    {
        $flat = count($options) === 1 && is_array($options[0]) ? array_values($options[0]) : array_map(strval(...), $options);

        return new self($key, $flat, 0, false, '', '');
    }

    // ------------------------------------------------------------------
    // Fluent knobs
    // ------------------------------------------------------------------

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }

    /**
     * Budget in cells available to the pill row (minimum 1). A roster that
     * fits renders whole; one that does not is windowed around the
     * selection with ellipsis markers on the clipped sides. Null (the
     * default) keeps the full unwindowed line.
     */
    public function withWidth(int $w): self
    {
        if ($w < 1) {
            throw new \InvalidArgumentException(Lang::t('radio_pills.width_positive'));
        }

        return $this->mutate(width: $w);
    }

    /**
     * Replace the option list. The current selection survives by LABEL when
     * the new list still carries it — pill identity is the label, so a
     * re-order re-points the cursor and a removal falls back to option 0.
     */
    public function withOptions(string ...$options): self
    {
        $selected = $this->value();
        $index    = $selected === null ? 0 : array_search($selected, $options, true);

        return $this->mutate(options: array_values($options), cursor: $index === false ? 0 : $index);
    }

    // ------------------------------------------------------------------
    // Short-form aliases (Input family shape; README :99 contract).
    // ------------------------------------------------------------------

    public function title(string $t): self { return $this->withTitle($t); }
    public function desc(string $d): self  { return $this->withDescription($d); }

    /** Alias of {@see withOptions()}, family shape (Select::options()). */
    public function options(string ...$options): self { return $this->withOptions(...$options); }

    /** Alias of {@see withWidth()}, family shape (Slider::width()). */
    public function width(int $w): self { return $this->withWidth($w); }

    /** Jump the selection to a label; an unknown label is a refusal (same instance). */
    public function withValue(mixed $label): self
    {
        if (!is_string($label)) {
            return $this;
        }
        $index = array_search($label, $this->options, true);
        return $index === false ? $this : $this->mutate(cursor: $index);
    }

    // ------------------------------------------------------------------
    // Field contract
    // ------------------------------------------------------------------

    public function key(): string { return $this->key; }

    /** The selected label — the widget's whole value; null while the row is empty. */
    public function value(): mixed
    {
        return $this->options[$this->cursor] ?? null;
    }

    /** Index of the selected pill (0 on an empty row — only {@see value()} distinguishes). */
    public function cursor(): int { return $this->cursor; }

    public function focus(): array { return [$this->mutate(focused: true), null]; }
    public function blur(): Field  { return $this->mutate(focused: false); }

    public function update(Msg $msg): array
    {
        // crush_media W3.2 dispatch shape: mouse answers BEFORE the keyboard
        // door so the KeyMsg arms below stay byte-identical; unfocused stays
        // deaf and read-only refuses the value-writing click.
        if ($msg instanceof MouseMsg) {
            return $this->handlePillsMouse($msg);
        }
        if (!$msg instanceof KeyMsg || !$this->focused || $this->isReadonly()) {
            return [$this, null];
        }
        $char = static fn (string $r): bool => $msg->type === KeyType::Char
            && $msg->rune === $r && !$msg->ctrl;

        $count = count($this->options);
        $next  = match (true) {
            $count === 0               => null,
            $msg->type === KeyType::Left  || $char('h') => $this->stepBy(-1),
            $msg->type === KeyType::Right || $char('l') => $this->stepBy(+1),
            $msg->type === KeyType::Home => $this->mutate(cursor: 0),
            $msg->type === KeyType::End  => $this->mutate(cursor: $count - 1),
            default                    => null,
        };
        return [$next ?? $this, null];
    }

    /**
     * Host-callable zone click, the Slider W3.2 ruling mirrored: the host
     * owns the hit-test geometry and passes the row's left column; press,
     * motion and release behave identically, wheel and non-left refuse, and
     * read-only refuses the whole message (moving the cursor IS committing).
     *
     * @return ?self the clicked-into state, or null when this message is not
     *               the widget's to answer
     */
    public function handleZoneMouse(MouseMsg $msg, int $zoneLeft): ?self
    {
        if ($this->isReadonly() || $msg instanceof MouseWheelMsg || $msg->button !== MouseButton::Left) {
            return null;
        }
        $column = $msg->x - $zoneLeft + 1;
        if ($column < 1) {
            return null;
        }
        [$first, $last, $head, $tail] = $this->pillWindow();
        $cells = [];
        if ($head) {
            $cells[] = [1, null];
        }
        for ($index = $first; $index <= $last; ++$index) {
            $cells[] = [mb_strlen($this->options[$index], 'UTF-8') + 2, $index];
        }
        if ($tail) {
            $cells[] = [1, null];
        }
        $offset = 0;
        foreach ($cells as [$width, $index]) {
            if ($column <= $offset + $width) {
                // Inside this cell — a pill selects (its right pad cell
                // belongs to the pill, matching what the eye sees around the
                // label); an ellipsis marker is decorative, inert.
                if ($index === null) {
                    return null;
                }
                return $index === $this->cursor ? $this : $this->mutate(cursor: $index);
            }
            if ($column <= $offset + $width + self::GAP) {
                return null; // three-cell dead zone between cells — inert
            }
            $offset += $width + self::GAP;
        }
        return null; // past the row (or on an empty one) — inert
    }

    /** Pills row only: the line after optional title/description lines. */
    private function handlePillsMouse(MouseMsg $msg): array
    {
        if (!$this->focused || $msg->y !== $this->pillsRow()) {
            return [$this, null];
        }
        $next = $this->handleZoneMouse($msg, 1);
        return [$next ?? $this, null];
    }

    /** 1-based line, within this widget's own view(), on which the pills render. */
    private function pillsRow(): int
    {
        $line = 1;
        if ($this->resolveTitle($this->title) !== '') {
            ++$line;
        }
        if ($this->resolveDescription($this->description) !== '') {
            ++$line;
        }
        return $line;
    }

    /**
     * Title / description, then the pill row — the selected pill in raw
     * reverse video, the rest plain, separated by three dead cells (the
     * Confirm idiom, deliberately unpainted: E736 3.9 — these widgets carry
     * no themeable style slots).
     */
    public function view(bool $showErrors = true): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title . $this->readonlyTitleSuffix(); }
        if ($desc  !== '') { $lines[] = $desc; }

        $lines[] = $this->buildPillRow();
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->focused; }
    /**
     * NOTE (accessor era, 2026-10-24): this getTitle() read cannot lose its
     * get* prefix — the Field interface declares getTitle() as the contract
     * read, and eleven sibling fields have the bare title() slot taken by a
     * live fluent setter, so the family cannot go bare class-by-class.
     * Treated as the documented legal exception (sugar-style precedent);
     * owner-pending and rostered in tests/AccessorDeprecationTest.php until
     * a vocabulary ruling lands.
     */
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    /** Static description, same shape as {@see getTitle()}. */
    public function description(): string { return $this->resolveDescription($this->description); }
    /**
     * @deprecated Use the bare {@see self::description()} read (accessor era,
     *             2026-10-24). REMOVED-IN: 2.0.0
     */
    public function getDescription(): string { return $this->description(); }
    /** Latest validator error, or null when the value passes validation. */
    public function error(): ?string { return null; }
    /**
     * @deprecated Use the bare {@see self::error()} read (accessor era,
     *             2026-10-24). REMOVED-IN: 2.0.0
     */
    public function getError(): ?string { return $this->error(); }
    public function skippable(): bool        { return false; }
    public function consumes(Msg $msg): bool { return false; }

    /**
     * @internal Radio state has nothing to re-judge: selection is commit,
     *           so the contract is the identity rebuild.
     */
    public function revalidate(): Field { return $this; }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The pill row as rendered: the selected pill in raw reverse video, the
     * rest plain, cells joined by three dead cells. With no width set (or a
     * roster that fits it) this is the whole roster; otherwise the window
     * from {@see pillWindow()} plus an ellipsis on each clipped side, cut to
     * the budget as a final guarantee.
     */
    private function buildPillRow(): string
    {
        $gap = str_repeat(' ', self::GAP);
        [$first, $last, $head, $tail] = $this->pillWindow();
        if ($first === 0 && $last === count($this->options) - 1 && !$head && !$tail) {
            $cells = [];
            foreach ($this->options as $index => $label) {
                $pill = ' ' . RenderSafe::clean($label) . ' ';
                $cells[] = $index === $this->cursor
                    ? Ansi::sgr(Ansi::REVERSE) . $pill . Ansi::reset()
                    : $pill;
            }
            return implode($gap, $cells);
        }

        $parts = [];
        if ($head) {
            $parts[] = self::ELLIPSIS;
        }
        for ($index = $first; $index <= $last; ++$index) {
            $pill = ' ' . RenderSafe::clean($this->options[$index]) . ' ';
            $parts[] = $index === $this->cursor
                ? Ansi::sgr(Ansi::REVERSE) . $pill . Ansi::reset()
                : $pill;
        }
        if ($tail) {
            $parts[] = self::ELLIPSIS;
        }
        return Width::truncateAnsi(implode($gap, $parts), (int) $this->width);
    }

    /**
     * The horizontal window: [first, last, head marker, tail marker] pill
     * indices into {@see $options}. Deterministic tab-strip rule — when the
     * row exceeds the budget, the widest window of pills that still fits
     * TOGETHER WITH an ellipsis on every side it clips wins; equal sizes
     * prefer the leftmost window. The selection is always inside the window.
     * Degenerate case: if the selected pill cannot fit even without
     * markers, the window collapses to the selection alone with the markers
     * dropped (buildPillRow then truncates the pill to the budget, so the
     * selection stays the one thing on screen). An empty row or a roster
     * that fits reports the whole roster with no markers; a null width
     * always reports the whole roster.
     *
     * @return array{0:int,1:int,2:bool,3:bool}
     */
    private function pillWindow(): array
    {
        $n = count($this->options);
        if ($this->width === null || $n === 0) {
            return [0, $n - 1, false, false];
        }
        $cost = [];
        foreach ($this->options as $label) {
            $cost[] = mb_strlen($label, 'UTF-8') + 2;
        }
        $marker = self::GAP + 1; // the ellipsis cell plus its joining gap
        $budget = $this->width;

        // Whole roster with no markers first (the unwindowed shape).
        $total = array_sum($cost) + self::GAP * ($n - 1);
        if ($total <= $budget) {
            return [0, $n - 1, false, false];
        }

        $bestSpan = 0;
        $best = null;
        for ($s = 0; $s <= $this->cursor && $s < $n; ++$s) {
            $run = $cost[$s] + ($s > 0 ? $marker : 0);
            for ($e = $s; $e < $n; ++$e) {
                if ($e > $s) {
                    $run += self::GAP + $cost[$e];
                }
                $candidate = $run + ($e < $n - 1 ? $marker : 0);
                if ($candidate > $budget) {
                    break; // run + tail cost is strictly increasing in e
                }
                $span = $e - $s + 1;
                if ($e >= $this->cursor && $span > $bestSpan) {
                    $bestSpan = $span;
                    $best = [$s, $e];
                }
            }
        }
        if ($best !== null) {
            [$s, $e] = $best;
            return [$s, $e, $s > 0, $e < $n - 1];
        }
        return [$this->cursor, $this->cursor, false, false];
    }

    /** Move the selection, saturating at both ends of the row. */
    private function stepBy(int $delta): self
    {
        $k = $this->cursor + $delta;
        if ($k < 0) {
            $k = 0;
        }
        $last = count($this->options) - 1;
        if ($k > $last) {
            $k = $last;
        }
        return $this->mutate(cursor: $k);
    }

    /** Hand-written immutable copy; every knob funnels the private constructor. */
    private function mutate(
        ?array $options = null,
        ?int $cursor = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        ?int $width = null,
    ): self {
        return $this->carryNonCtorState(new self(
            key:         $this->key,
            options:     $options ?? $this->options,
            cursor:      $cursor   ?? $this->cursor,
            focused:     $focused  ?? $this->focused,
            title:       $title    ?? $this->title,
            description: $description ?? $this->description,
            width:       $width    ?? $this->width,
        ));
    }
}
