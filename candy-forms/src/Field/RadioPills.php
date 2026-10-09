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
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\HasReadonly;
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
 */
final class RadioPills implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;
    use HasReadonly;
    use CarriesNonCtorState;

    /** Cells of separator between pills. */
    private const GAP = 3;

    /**
     * @param list<string> $options
     */
    private function __construct(
        public readonly string $key,
        public readonly array $options,
        public readonly int $cursor,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
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

    /** Alias of {@see withOptions()}, family shape (Select::options()). */
    public function options(string ...$options): self { return $this->withOptions(...$options); }

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
        $offset = 0;
        foreach ($this->options as $index => $label) {
            $width = mb_strlen($label, 'UTF-8') + 2;
            if ($column <= $offset + $width) {
                // Inside this pill's padded cell — a click selects (the
                // right pad cell belongs to the pill, matching what the eye
                // sees around the label).
                return $index === $this->cursor ? $this : $this->mutate(cursor: $index);
            }
            if ($column <= $offset + $width + self::GAP) {
                return null; // three-cell dead zone between pills — inert
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
    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title . $this->readonlyTitleSuffix(); }
        if ($desc  !== '') { $lines[] = $desc; }

        $cells = [];
        foreach ($this->options as $index => $label) {
            $pill = ' ' . RenderSafe::clean($label) . ' ';
            $cells[] = $index === $this->cursor
                ? Ansi::sgr(Ansi::REVERSE) . $pill . Ansi::reset()
                : $pill;
        }
        $lines[] = implode(str_repeat(' ', self::GAP), $cells);
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return null; }
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
    ): self {
        return $this->carryNonCtorState(new self(
            key:         $this->key,
            options:     $options ?? $this->options,
            cursor:      $cursor   ?? $this->cursor,
            focused:     $focused  ?? $this->focused,
            title:       $title    ?? $this->title,
            description: $description ?? $this->description,
        ));
    }
}
