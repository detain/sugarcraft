<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
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
 * `0.30000000000000004` into a value or a rendered label. The snap itself
 * is the explicit EPS-tolerant rule in {@see latticeIndex()} — never a bare
 * round() call, whose version-varying tie fuzz made float lattices drift
 * between PHP 8.3 and 8.4.
 *
 * Nonsense geometry throws at the boundary (Fail Fast): min at-or-above
 * max, a zero/negative step and a sub-unit width are unusable
 * configurations, not values to coerce.
 *
 * Keys while focused:
 *   <- / h     one step down        Home   first step (min)
 *   -> / l     one step up          End    top step (last lattice point <= max)
 *
 * Mouse while focused (crush_media W3.2): a left-button Press, Motion or
 * Release landing on the track line drags the handle to that column — the
 * TEA channel answers the widget-relative form in {@see update()}, and
 * hosts that own the hit-test geometry call the public zone form
 * {@see handleZoneMouse()} directly. Both funnel the number through
 * {@see withValue()}, so the single clamp/snap site stays untouched.
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
        $lattice = self::latticeIndex((self::clamp($min, $max, $raw) - $min) / $step);
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
        return self::latticeIndex(($this->value - $this->min) / $this->step);
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
        // crush_media W3.2: mouse arrives BEFORE the keyboard guard so the
        // KeyMsg arms below stay byte-identical (the ItemList E736 5.14
        // shape); unfocused stays deaf, and a non-track row is inert.
        if ($msg instanceof MouseMsg) {
            return $this->handleTrackMouse($msg);
        }
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
     * Host-callable zone drag (crush_media W3.2, plan :417-431). This is the
     * Shape-A-vs-B of widget mouse support and the ruling lands on B-plus-A:
     * Slider stays dependency-free — it types sugar-core's own `MouseMsg`
     * (already a required sibling, zero new composer requires), so a host on
     * the TEA channel just forwards messages to {@see update()}, while a
     * host that owns the layout geometry (candy-code runs sugar-mouse and
     * knows the track's absolute screen rect) hit-tests itself and calls
     * THIS method with the zone it computed.
     *
     * The mapping is endpoint-inclusive and monotonic: the quotient
     * `($msg->x - $zoneLeft) / span` over `span = $zoneWidth - 1` (floored at
     * one cell so a degenerate zone never divides by zero) scales
     * `min + ratio * (max - min)` — and the number ALWAYS routes through
     * {@see withValue()}, never a local clamp or snap: out-of-zone x
     * saturates into range via the single-site normalise(), and the lattice
     * snap reuses the documented EPS tie rule there (the r1-q16 single-site
     * law stays intact; SliderTest counts the clamp-call tokens
     * structurally). x exactly at `$zoneLeft` yields the min end; x at
     * `$zoneLeft + $zoneWidth - 1` yields the top lattice point.
     *
     * Press, Motion and Release are handled identically in v1 — the widget
     * keeps no drag state, so a host may fire on any action. Wheel messages
     * and non-left buttons answer null (not the widget's to take): noted
     * divergence — candy-code's Viewport routes the wheel as Up/Down
     * KeyMsgs today, so also answering WheelDown here would double-step.
     *
     * @param int $zoneLeft   1-based column of the zone's first draggable cell
     * @param int $zoneWidth  width of the zone in cells (handle band)
     *
     * @return ?self the dragged-to state, or null when this message is not
     *               the widget's to answer (readonly, wheel, non-left button)
     */
    public function handleZoneMouse(MouseMsg $msg, int $zoneLeft, int $zoneWidth): ?self
    {
        if ($this->isReadonly() || $msg instanceof MouseWheelMsg || $msg->button !== MouseButton::Left) {
            return null;
        }
        $span = $zoneWidth - 1;
        if ($span < 1) {
            $span = 1;
        }
        $ratio = ($msg->x - $zoneLeft) / $span;

        return $this->withValue($this->min + $ratio * ($this->max - $this->min));
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

    /**
     * The documented lattice snap: nearest step, exact ties away from zero,
     * and anything inside the class EPS of a tie treated as ON the tie — so
     * a seed whose double lands a few ulps below the halfway quotient
     * (0.35 / 0.1 == 3.4999999999999996) snaps up like the decimal value it
     * stands for. The quotient is always >= 0 here: normalise() clamps the
     * value into range before dividing, and every stored value is already a
     * lattice point, so floor(q + 0.5) is exactly half-up.
     *
     * Mirrors charmbracelet/bubbles' slider snapping, and deliberately does
     * NOT call round(): through PHP 8.3 round() "pre-rounded" — it nudged
     * values within ~1e-9 of a tie over it, silently implementing this rule
     * by accident. PHP 8.4 removed that pre-round as a bug fix (see
     * migration84.other-changes: 0.49999999999999994 now rounds to 0, not
     * 1), which made every round()-derived lattice drift between versions
     * (SliderTest::testFloatLatticeIsClean was green on 8.3, red on 8.4).
     * The tolerance now lives in this class's contract — pure IEEE
     * arithmetic, identical on every runtime — matching the same EPS the
     * `$top`/steps() floors already use.
     */
    private static function latticeIndex(int|float $quotient): int
    {
        return (int) floor($quotient + 0.5 + self::EPS);
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
     * Widget-relative mouse dispatch (crush_media W3.2): the track is always
     * the LAST line of {@see view()}, so a message answering through the TEA
     * channel must land on {@see trackLine()} to count as a grab; the handle
     * band starts at column 2 (the '[' bracket owns column 1) and spans the
     * track width in cells. Everything else — focus, readonly, button,
     * wheel — is judged by {@see handleZoneMouse()}.
     */
    private function handleTrackMouse(MouseMsg $msg): array
    {
        if (!$this->focused || $msg->y !== $this->trackLine()) {
            return [$this, null];
        }
        $next = $this->handleZoneMouse($msg, 2, $this->width);

        return [$next ?? $this, null];
    }

    /** 1-based line, within this widget's own view(), on which the track renders. */
    private function trackLine(): int
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
