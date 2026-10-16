<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Forms\CarriesNonCtorState;
use SugarCraft\Forms\Cursor\BlinkMsg;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\HasReadonly;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Forms\Util\RenderSafe;

/**
 * Bounded numeric field (crush_media W3.3, plan :433-445). Where a
 * {@see Slider} is structurally wrong — an A1111-style seed is an int64, not
 * a draggable ratio — this is the truthful widget: a text draft composed over
 * {@see TextInput} plus Slider's normalisation surface for everything the
 * draft is not.
 *
 * The model is draft + committed value: keystrokes edit the draft, and a
 * commit (Enter, blur, {@see revalidate()}) parses it. A failed parse shows
 * an error and leaves the committed value untouched — the widget can hold a
 * draft it has no value for, but never a value it cannot represent. The
 * never-set state is its own sentinel ({@see hasValue()}, like the house
 * XSet "never set" law): EMPTY IS NOT ZERO — clearing the box and pressing
 * Enter re-draws the display (or stays unset), it never commits 0.
 *
 * The int64 law: seed values NEVER route through float. A canonical integer
 * draft commits through an exact integer lattice walk (clamp + half-up snap
 * via intdiv — ties up, matching Slider's documented EPS rule); only when
 * the geometry or the draft genuinely needs floats does the number delegate
 * to Slider::withValue() — the single clamp/snap site for floats stays
 * Slider's normalise(), re-used, never re-implemented here. `99999999999999999`
 * round-trips digit-exact (NumberFieldTest pins it), which a float would
 * corrupt; a draft beyond int64 is REFUSED, not silently widened. The
 * geometry itself answers to the same law: an all-int lattice whose span
 * exceeds int64 is rejected by the constructor (R6-M1) rather than accepted
 * with an int path that could never run.
 *
 * Locale-safe parse: canonical `-?[0-9]+` and plain-decimal/exponent float
 * forms only. Thousands separators are refused with a message, never guessed
 * — "1,000" is ambiguous across locales and this widget does not guess.
 *
 * Keys while focused:
 *   Up / Down              one step (from the committed value, or from min
 *                          while never-set — the step ladder starts at min)
 *   PageUp / PageDown      ten steps
 *   Home / End             commit min / commit max (the plan's binding; the
 *                          inner text cursor does NOT get the row jump —
 *                          numeric drafts are short and lattice jumps win)
 *   Enter                  commit the draft
 *   anything else          edits the draft through TextInput
 *
 * consumes(): Up/Down only, while focused and mutable (the Date/Color
 * precedent — the Form otherwise navigates fields on its Down/Up bindings;
 * Enter stays unclaimed so it remains Form submit/advance, and advancing
 * blurs, and blur commits). Read-only releases the claim so navigation
 * walks over the frozen widget, and its whole mutating message set —
 * arrows included — is refused at the {@see update()} door (E736 5.10
 * value-editor shape). Mouse has no v1 path here (draft caret placement is
 * TextInput's domain and un-routed); crush lane a owns zone plumbing.
 */
final class NumberField implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;
    use HasReadonly;
    use CarriesNonCtorState;

    /** Committed value — written ONLY by the constructor; null = never set. */
    public readonly int|float|null $value;

    /**
     * @param int|float|null $value raw seed, coerced in the body; null keeps
     *                              the widget in the never-set state
     */
    private function __construct(
        public readonly string $key,
        public readonly int|float $min,
        public readonly int|float $max,
        public readonly int|float $step,
        public readonly string $title,
        public readonly string $description,
        public readonly TextInput $text,
        int|float|null $value = null,
        public readonly ?string $error = null,
    ) {
        if (!($min < $max)) {
            throw new \InvalidArgumentException(Lang::t('number.min_below_max'));
        }
        if (!($step > 0)) {
            throw new \InvalidArgumentException(Lang::t('number.step_positive'));
        }
        // R6-M1 fail-fast: an all-int geometry whose span overflows int64
        // (min=-1, max=PHP_INT_MAX) cannot honour the int64 law — the exact
        // walk below would fall to the corrupting float delegate — so the
        // shape that makes the promise unhonorable is refused here, at the
        // same boundary as the other nonsense-geometry throws.
        if (is_int($min) && is_int($max) && is_int($step) && !is_int($max - $min)) {
            throw new \InvalidArgumentException(Lang::t('number.span_overflow'));
        }
        $this->value = $value === null ? null : self::coerce($key, $min, $max, $step, $value);
    }

    public static function new(string $key, int|float $min = 0, int|float $max = 100, int|float $step = 1, int|float|null $initial = null): self
    {
        $field = new self($key, $min, $max, $step, '', '', TextInput::new(), $initial);
        // The draft mirrors the committed display at construction: a seeded
        // box shows its number (an unset one shows the placeholder).
        $display = $field->value === null ? '' : self::display($field->value);
        return $display === '' ? $field : $field->withDraft($display);
    }

    // ------------------------------------------------------------------
    // Fluent knobs
    // ------------------------------------------------------------------

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }
    public function withPlaceholder(string $p): self { return $this->mutate(text: $this->text->withPlaceholder($p)); }
    public function withPrompt(string $p): self      { return $this->mutate(text: $this->text->withPrompt($p)); }
    public function withWidth(int $w): self          { return $this->mutate(text: $this->text->withWidth($w)); }

    /** Commit a number: coerce onto the lattice, re-draw the draft, clear any error. */
    public function withValue(int|float $v): self { return $this->commitValue($v); }

    /**
     * Return to the never-set state: value null, draft empty, error cleared.
     * The distinct absence of a value — {@see hasValue()} — is the point
     * (empty box ≠ 0, both in the model and on screen).
     */
    public function withoutValue(): self
    {
        return $this->mutate(text: $this->text->setValue(''), value: null, valueSet: true, error: null, errorSet: true);
    }

    /** Move a bound; the committed value re-coerces against the new lattice. */
    public function withMin(int|float $m): self   { return $this->mutate(min: $m); }
    public function withMax(int|float $m): self   { return $this->mutate(max: $m); }
    public function withStep(int|float $s): self  { return $this->mutate(step: $s); }

    // Short-form aliases (Slider/Input family shape).
    public function title(string $t): self        { return $this->withTitle($t); }
    public function desc(string $d): self         { return $this->withDescription($d); }
    public function placeholder(string $p): self  { return $this->withPlaceholder($p); }
    public function prompt(string $p): self       { return $this->withPrompt($p); }
    public function width(int $w): self           { return $this->withWidth($w); }

    // ------------------------------------------------------------------
    // Field contract
    // ------------------------------------------------------------------

    public function key(): string      { return $this->key; }
    public function value(): mixed     { return $this->value; }
    public function hasValue(): bool   { return $this->value !== null; }

    /** The uncommitted draft text exactly as displayed. */
    public function draft(): string    { return $this->text->value; }

    public function focus(): array
    {
        [$text, $cmd] = $this->text->focus();
        return [$this->mutate(text: $text instanceof TextInput ? $text : $this->text), $cmd];
    }

    /** Release focus — a commit point: the draft is judged on the way out. */
    public function blur(): Field
    {
        $committed = $this->commit();
        return $committed->mutate(text: $committed->text->blur());
    }

    public function update(Msg $msg): array
    {
        // Read-only door (E736 5.10 value-editor shape): the whole mutating
        // set — stepping arrows included — is refused before anything else.
        if ($this->isReadonly() && self::isReadonlyMutatingMsg($msg)) {
            return [$this, null];
        }

        if ($this->isFocused() && $msg instanceof KeyMsg) {
            $stepped = match (true) {
                $msg->type === KeyType::Up       => $this->stepBy(+1),
                $msg->type === KeyType::Down     => $this->stepBy(-1),
                $msg->type === KeyType::PageUp   => $this->stepBy(+10),
                $msg->type === KeyType::PageDown => $this->stepBy(-10),
                $msg->type === KeyType::Home     => $this->commitValue($this->min),
                $msg->type === KeyType::End      => $this->commitValue($this->max),
                $msg->type === KeyType::Enter    => $this->commit(),
                default                          => null,
            };
            if ($stepped !== null) {
                return [$stepped, null];
            }
        }

        // Blink (cursor animation), typed chars, editing keys and paste ride
        // the inner TextInput; everything else (mouse, resize plumbing) is
        // not this widget's to answer in v1.
        if (!$msg instanceof BlinkMsg && !$msg instanceof KeyMsg && !$msg instanceof PasteMsg) {
            return [$this, null];
        }
        if (!$this->isFocused()) {
            return [$this, null];
        }

        [$text, $cmd] = $this->text->update($msg);
        $text = $text instanceof TextInput ? $text : $this->text;
        if ($text === $this->text) {
            return [$this, $cmd];
        }
        $next = $this->mutate(text: $text);
        // A stale parse error must not outlive the draft that caused it:
        // any real edit clears the error (the value itself stays put until
        // the next commit).
        if ($next->error !== null) {
            $next = $next->mutate(error: null, errorSet: true);
        }
        return [$next, $cmd];
    }

    /** Render title/description, the draft line, then any parse error. */
    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') {
            $lines[] = $title . $this->readonlyTitleSuffix();
        }
        if ($desc !== '') {
            $lines[] = $desc;
        }
        $lines[] = $this->text->view();
        if ($this->error !== null) {
            // Parse messages quote the offending draft — clean at the
            // display site (getError() stays raw, Input::view() precedent).
            $lines[] = '! ' . RenderSafe::clean($this->error);
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->text->isFocused(); }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }

    public function consumes(Msg $msg): bool
    {
        // Date/Color precedent: claim only the stepping arrows, and only
        // while focused and mutable (read-only releases the claim so the
        // Form navigates over the frozen widget).
        if (!$this->isFocused() || $this->isReadonly()) {
            return false;
        }
        return $msg instanceof KeyMsg
            && ($msg->type === KeyType::Up || $msg->type === KeyType::Down);
    }

    /**
     * The Form's submit gate calls this: an uncommitted draft is judged, so
     * an invalid box surfaces its error at submit instead of silently
     * shipping its last good value. Idempotent once the draft matches the
     * committed display ($this survives — the contract's identity law).
     */
    public function revalidate(): Field
    {
        return $this->commit();
    }

    // ------------------------------------------------------------------
    // Commit machinery
    // ------------------------------------------------------------------

    /**
     * Judge the draft. Empty is NOT a commit (never-set survives, display
     * re-draws); canonical int / float forms commit through coerce(); every
     * other shape refuses with a message while the committed value stays put.
     */
    private function commit(): self
    {
        $draft = trim($this->text->value);
        if ($draft === '') {
            return $this->redrawDisplay();
        }
        if (str_contains($draft, ',')) {
            return $this->refuse(Lang::t('number.separators_refused'));
        }
        if (preg_match('/^-?[0-9]+$/', $draft) === 1) {
            $overflow = self::int64Overflow($draft);
            if ($overflow !== null) {
                return $this->refuse($overflow);
            }
            return $this->commitValue((int) $draft);
        }
        if (preg_match('/^-?(?:[0-9]+\.[0-9]*|\.[0-9]+|[0-9]+)(?:[eE][+-]?[0-9]+)?$/', $draft) === 1) {
            $float = (float) $draft;
            if (!is_finite($float)) {
                return $this->refuse(Lang::t('number.out_of_range'));
            }
            return $this->commitValue($float);
        }
        return $this->refuse(Lang::t('number.invalid'));
    }

    /** Commit an already-judged number onto the lattice and re-draw the draft. */
    private function commitValue(int|float $raw): self
    {
        $coerced = self::coerce($this->key, $this->min, $this->max, $this->step, $raw);
        $display = self::display($coerced);
        if ($coerced === $this->value && $this->error === null && $this->text->value === $display) {
            return $this;
        }
        return $this->mutate(text: $this->text->setValue($display), value: $coerced, valueSet: true, error: null, errorSet: true);
    }

    /** Walk the ladder by $delta steps from the committed value (or min while never-set). */
    private function stepBy(int $delta): self
    {
        $base = $this->value ?? $this->min;

        return $this->commitValue($base + $delta * $this->step);
    }

    /** Re-draw the draft from the committed display (or clear it when never-set). */
    private function redrawDisplay(): self
    {
        $display = $this->value === null ? '' : self::display($this->value);
        if ($this->text->value === $display && $this->error === null) {
            return $this;
        }
        $next = $this->mutate(text: $this->text->setValue($display));
        return $next->error === null ? $next : $next->mutate(error: null, errorSet: true);
    }

    /** Record a parse error; the committed value and draft stay untouched. */
    private function refuse(string $error): self
    {
        return $this->error === $error ? $this : $this->mutate(error: $error, errorSet: true);
    }

    /**
     * The single coercion onto [min, max]'s step lattice. INT PATH (the
     * int64 law): when min/max/step and the raw number are all int and the
     * span itself fits int, clamp + half-up snap runs on pure integer
     * arithmetic (intdiv — overflow-free below the guard; the constructor
     * refuses all-int geometries whose span overflows int, so the in-walk
     * span guard is defense-in-depth) so an int64 seed round-trips
     * digit-exact. FLOAT PATH: everything else delegates to
     * Slider::withValue() — Slider's normalise() stays the ONE float
     * clamp/snap site in the library, EPS tie rule included. The two paths
     * are pinned equal on the overlap by NumberFieldTest's cross-engine walk.
     */
    private static function coerce(string $key, int|float $min, int|float $max, int|float $step, int|float $raw): int|float
    {
        if (is_int($min) && is_int($max) && is_int($step) && is_int($raw) && is_int($max - $min)) {
            $v = $raw;
            if ($v < $min) {
                $v = $min;
            }
            if ($v > $max) {
                $v = $max;
            }
            $offset = $v - $min;
            $index  = intdiv($offset, $step);
            $rem    = $offset % $step;
            if ($rem >= $step - $rem) { // half-up, ties away from min
                ++$index;
            }
            $top = intdiv($max - $min, $step);
            if ($index > $top) {
                $index = $top;
            }
            return $min + $index * $step;
        }

        return Slider::new($key, $min, $max, $step)->withValue($raw)->value;
    }

    /** Display form of a committed number: int stays digit-exact; float uses PHP 8's shortest repr. */
    private static function display(int|float $v): string
    {
        return (string) $v;
    }

    /**
     * Does a canonical-integer draft exceed int64? Compared on the zero-
     * stripped digit string against the decimal bound constants, so the
     * answer never depends on a float cast. Null = fits; string = the Lang
     * refusal message.
     */
    private static function int64Overflow(string $draft): ?string
    {
        $negative = str_starts_with($draft, '-');
        $digits   = ltrim($negative ? substr($draft, 1) : $draft, '0');
        if ($digits === '') {
            return null;
        }
        $bound = $negative ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > strlen($bound)
            || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0)
        ) {
            return Lang::t('number.out_of_range');
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Immutable rebuild
    // ------------------------------------------------------------------

    /**
     * Hand-written copy; the value is always re-fed through the constructor
     * so a moved bound re-coerces it (the Slider single-site shape). The
     * `valueSet` / `errorSet` sentinels distinguish "carry" from an explicit
     * null write (withoutValue, cleared errors — house XSet law).
     */
    private function mutate(
        ?TextInput $text = null,
        int|float|null $min = null,
        int|float|null $max = null,
        int|float|null $step = null,
        ?string $title = null,
        ?string $description = null,
        int|float|null $value = null,
        bool $valueSet = false,
        ?string $error = null,
        bool $errorSet = false,
    ): self {
        return $this->carryNonCtorState(new self(
            key:         $this->key,
            min:         $min    ?? $this->min,
            max:         $max    ?? $this->max,
            step:        $step   ?? $this->step,
            title:       $title  ?? $this->title,
            description: $description ?? $this->description,
            text:        $text   ?? $this->text,
            value:       $valueSet ? $value : $this->value,
            error:       $errorSet ? $error : $this->error,
        ));
    }

    /** Seed the draft buffer without touching the committed value. @internal */
    private function withDraft(string $draft): self
    {
        return $this->mutate(text: $this->text->setValue($draft));
    }
}
