<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\CarriesNonCtorState;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\HasReadonly;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\Validator\Validator;

/**
 * Horizontal handle slider (E736 Phase-5 row 5.7, r88-x4). Mirrors
 * charmbracelet/bubbles' slider: a `[███◆░░░]` track over an inclusive
 * numeric range with a fixed step lattice.
 *
 * The value is parsed at the boundary: {@see normalise()} is the ONE
 * place a number is normalised — clamped into [min, max] and snapped onto
 * the step lattice — and every construction path (new / with* / key
 * presses) funnels through the private constructor, so no wither or render
 * path re-clamps (the r1-q16 Gauge/Meter single-site law; SliderTest
 * counts the lower-case clamp-call tokens structurally so a re-spread of the
 * clamp reddens the census even when the behavioural pins stay green).
 * Int-ness survives when min and step are both int; float results are
 * normalised to 10 decimals so lattice arithmetic never leaks
 * `0.30000000000000004` into a value or a rendered label.
 *
 * Nonsense geometry throws at the boundary (Fail Fast): min at-or-above
 * max, a zero/negative step and a sub-unit width are unusable
 * configurations, not values to coerce.
 *
 * Keys while focused:
 *   <- / h     one step down        Home   first step (min)
 *   -> / l     one step up          End    top step (last lattice point <= max)
 *
 * Up/Down/PageUp/PageDown/Enter/Tab stay with the Form — the slider moves
 * on its own axis only, so it consumes nothing.
 */
final class Slider implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;
    use HasReadonly;
    use CarriesNonCtorState;

    /** Normalised current value — written ONLY by the constructor. */
    public readonly int|float $value;

    /**
     * @param int|float $value raw seed, normalised in the body
     * @param ?\Closure(int|float):?string $validator
     */
    private function __construct(
        public readonly string $key,
        public readonly int|float $min,
        public readonly int|float $max,
        public readonly int|float $step,
        public readonly int $width,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        int|float $value = 0,
        public readonly ?string $error = null,
        public readonly ?\Closure $validator = null,
    ) {
        $this->value = self::normalise($min, $max, $step, $width, $value);
    }

    /**
     * Boundary: geometry invariants (throw on nonsense) then the single
     * normalisation of the seed value. Lives apart from the constructor so
     * the readonly assignment below stays the only write of `$value`.
     *
     * @return int|float the clamped, lattice-snapped value
     */
    private static function normalise(int|float $min, int|float $max, int|float $step, int $width, int|float $raw): int|float
    {
        if (!($min < $max)) {
            throw new \InvalidArgumentException(Lang::t('slider.min_below_max'));
        }
        if (!($step > 0)) {
            throw new \InvalidArgumentException(Lang::t('slider.step_positive'));
        }
        if ($width < 1) {
            throw new \InvalidArgumentException(Lang::t('slider.width_positive'));
        }
        $lattice = (int) round((self::clamp($min, $max, $raw) - $min) / $step);
        $top     = (int) floor(($max - $min) / $step + self::EPS);
        if ($lattice < 0) {
            $lattice = 0;
        }
        if ($lattice > $top) {
            $lattice = $top;
        }
        $aligned = $min + $lattice * $step;
        // Int knobs stay int; float knobs shed representation noise.
        return (is_int($min) && is_int($step)) ? $aligned : round($aligned, 10);
    }

    private const EPS = 1e-9;

    public static function new(string $key, int|float $min = 0, int|float $max = 100, int|float $step = 1, int|float|null $initial = null): self
    {
        return new self($key, $min, $max, $step, 20, false, '', '', $initial ?? $min);
    }

    // ------------------------------------------------------------------
    // Fluent knobs
    // ------------------------------------------------------------------

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }

    /**
     * Seed/clamp/re-align the value. Out-of-range numbers are pulled into
     * range and off-lattice numbers snapped to the nearest step — the
     * widget cannot hold an unrepresentable state, so the setter never
     * throws on a mere number.
     */
    public function withValue(int|float $v): self
    {
        return $this->mutate(value: $v, valueSet: true);
    }

    /** Move the lower bound; the value re-normalises against the new lattice. */
    public function withMin(int|float $m): self { return $this->mutate(min: $m); }

    /** Move the upper bound; the value re-normalises against the new lattice. */
    public function withMax(int|float $m): self { return $this->mutate(max: $m); }

    /** Change the step lattice; the value snaps to the nearest new step. */
    public function withStep(int|float $s): self { return $this->mutate(step: $s); }

    /** Track width in cells, handle included (minimum 1). */
    public function withWidth(int $w): self { return $this->mutate(width: $w); }

    /**
     * Attach a validator (null clears). A {@see Validator} instance is fed
     * the number as a display string (int stays `7`, float prints its
     * shortest form); a Closure receives the raw `int|float`. Attaching
     * re-judges the current value immediately (mirrors
     * {@see Confirm::withValidator()}).
     *
     * @param Validator|\Closure(int|float):?string|null $v
     */
    public function withValidator(Validator|\Closure|null $v): self
    {
        if ($v instanceof Validator) {
            $fn = static function (int|float $value) use ($v): ?string {
                $result = $v->validate((string) $value);
                return $result === true ? null : (string) $result;
            };
        } else {
            $fn = $v;
        }
        return $this->mutate(validator: $fn, validatorSet: true)->revalidate();
    }

    // Short-form aliases.
    public function title(string $t): self  { return $this->withTitle($t); }
    public function desc(string $d): self   { return $this->withDescription($d); }
    public function width(int $w): self     { return $this->withWidth($w); }
    public function default(int|float $v): self { return $this->withValue($v); }

    // ------------------------------------------------------------------
    // Field contract
    // ------------------------------------------------------------------

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->value; }

    /** Lattice index of the current value: 0 == min, `steps()` == top. */
    public function position(): int
    {
        return (int) round(($this->value - $this->min) / $this->step);
    }

    /** Number of step increments between min and max (lattice top index). */
    public function steps(): int
    {
        return (int) floor(($this->max - $this->min) / $this->step + self::EPS);
    }

    public function focus(): array { return [$this->mutate(focused: true), null]; }
    public function blur(): Field  { return $this->mutate(focused: false); }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused || $this->isReadonly()) {
            return [$this, null];
        }
        $char = static fn (string $r): bool => $msg->type === KeyType::Char
            && $msg->rune === $r && !$msg->ctrl;

        $next = match (true) {
            $msg->type === KeyType::Left  || $char('h') => $this->stepBy(-1),
            $msg->type === KeyType::Right || $char('l') => $this->stepBy(+1),
            $msg->type === KeyType::Home => $this->mutate(value: $this->min, valueSet: true),
            $msg->type === KeyType::End  => $this->mutate(value: $this->max, valueSet: true),
            default => null,
        };
        return [$next ?? $this, null];
    }

    /**
     * Title / description, then the track: '[' + filled blocks + handle +
     * empty blocks + ']' followed by the value label. The glyphs are the
     * bubbles look (U+2588 / U+2591) with a U+25C6 diamond handle, unpainted
     * like Confirm's raw-reverse pills — these widgets carry no themeable
     * style slots (E736 3.9 rationale).
     */
    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title . $this->readonlyTitleSuffix(); }
        if ($desc  !== '') { $lines[] = $desc; }

        $cells  = $this->width - 1;
        $ratio  = ($this->value - $this->min) / ($this->max - $this->min);
        $filled = (int) round($ratio * $cells);
        if ($filled < 0) {
            $filled = 0;
        }
        if ($filled > $cells) {
            $filled = $cells;
        }
        $lines[] = '[' . str_repeat('█', $filled) . '◆' . str_repeat('░', $cells - $filled) . ']'
            . ' ' . (string) $this->value;
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }
    public function consumes(Msg $msg): bool { return false; }

    /**
     * @internal
     */
    public function revalidate(): Field
    {
        $err = $this->validator !== null ? ($this->validator)($this->value) : null;
        if ($err === $this->error) {
            return $this;
        }
        // $error is readonly: only the ctor writes it; the validator rides
        // the ctor too, so the shared carrier covers exactly the remaining
        // non-ctor state (E741).
        return $this->carryNonCtorState(new self(
            key: $this->key, min: $this->min, max: $this->max, step: $this->step,
            width: $this->width, focused: $this->focused, title: $this->title,
            description: $this->description, value: $this->value, error: $err,
            validator: $this->validator,
        ));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** The one clamp expression in this file — census-pinned by SliderTest. */
    private static function clamp(int|float $min, int|float $max, int|float $v): int|float
    {
        return max($min, min($max, $v));
    }

    /** Walk the lattice by $delta steps, saturating at both ends. */
    private function stepBy(int $delta): self
    {
        $k = $this->position() + $delta;
        if ($k < 0) {
            $k = 0;
        }
        $top = $this->steps();
        if ($k > $top) {
            $k = $top;
        }
        return $this->mutate(value: $this->min + $k * $this->step, valueSet: true);
    }

    /**
     * Hand-written immutable copy; every normalisation rides the
     * constructor, never a local clamp (single-site law). The `valueSet` /
     * `validatorSet` sentinels distinguish null-intolerant knobs (int|float
     * unions default to null here) and validator clearing (house XSet law).
     */
    private function mutate(
        int|float|null $min = null,
        int|float|null $max = null,
        int|float|null $step = null,
        ?int $width = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        int|float|null $value = null,
        bool $valueSet = false,
        ?\Closure $validator = null,
        bool $validatorSet = false,
    ): self {
        $next = $this->carryNonCtorState(new self(
            key:         $this->key,
            min:         $min       ?? $this->min,
            max:         $max       ?? $this->max,
            step:        $step      ?? $this->step,
            width:       $width     ?? $this->width,
            focused:     $focused   ?? $this->focused,
            title:       $title     ?? $this->title,
            description: $description ?? $this->description,
            value:       $valueSet ? $value : $this->value,
            error:       $this->error,
            validator:   $validatorSet ? $validator : $this->validator,
        ));
        if ($next->value !== $this->value) {
            return $next->revalidate();
        }
        return $next;
    }
}
