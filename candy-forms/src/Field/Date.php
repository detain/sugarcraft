<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\CarriesNonCtorState;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\Validator\Validator;

/**
 * Calendar-grid date picker (E736 Phase-5 row 5.6, r88-x4). Mirrors the
 * keyboard model of charmbracelet/bubbles' datepicker reduced to the date
 * half of HTML's `date` input: the value is a `YYYY-MM-DD` string, the grid
 * walks it with arrows, and every movement commits.
 *
 * Hand-rolled on {@see \DateTimeImmutable} — no calendar library, no
 * candy-vt, ext-agnostic. All state lives in the constructor, so every
 * rebuild path funnels through the single strict parse boundary
 * (parse-don't-validate); only the label-trait closures ride outside and
 * are carried by {@see CarriesNonCtorState}.
 *
 * Keys while focused (Tab always leaves the field — the field never
 * consumes it):
 *   <- / ->          previous / next day          h / l
 *   ^ / v            previous / next week         k / j   (the field
 *                                                         consumes these:
 *                                                         the default
 *                                                         KeyMap binds
 *                                                         them to form
 *                                                         navigation —
 *                                                         same claim
 *                                                         {@see Select}
 *                                                         makes in list
 *                                                         mode)
 *   SHIFT+<- / SHIFT+->, PageUp / PageDown        previous / next month
 *                                                  (day clamped to the
 *                                                  target month's length:
 *                                                  2026-03-31 -1mo lands
 *                                                  2026-02-28, not Mar 3)
 *   Home / End       first / last day of the shown month
 *   Space            commit the cursor without moving it
 *
 * Enter is deliberately NOT consumed: it stays the Form's submit /
 * advance key, matching {@see Select}'s non-filter-mode behaviour.
 */
final class Date implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;
    use CarriesNonCtorState;

    /**
     * @param ?string $value committed selection (`Y-m-d`); null until the
     *                       user first moves or presses Space
     * @param string  $cursor the highlighted day (`Y-m-d`); the grid always
     *                        renders the cursor's month, so the cursor is
     *                        always on the displayed page
     * @param ?\Closure(?string):?string $validator
     */
    private function __construct(
        public readonly string $key,
        public readonly ?string $value,
        public readonly string $cursor,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $error = null,
        public readonly ?\Closure $validator = null,
    ) {}

    /**
     * @param string|null $initial pre-fills value and cursor; must be a real
     *                             calendar date in zero-padded `Y-m-d` form
     *
     * @throws \InvalidArgumentException on a malformed / impossible $initial
     */
    public static function new(string $key, ?string $initial = null): self
    {
        $cursor = $initial !== null ? self::parse($initial) : self::today();
        return new self($key, $initial, $cursor, false, '', '');
    }

    // ------------------------------------------------------------------
    // Fluent knobs
    // ------------------------------------------------------------------

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }

    /**
     * Set (or with null, clear) the committed date. Setting also jumps the
     * cursor to the new value so the grid always shows it.
     *
     * @throws \InvalidArgumentException when $date is malformed
     */
    public function withValue(?string $date): self
    {
        if ($date === null) {
            return $this->mutate(value: null, valueSet: true);
        }
        $canonical = self::parse($date);
        return $this->mutate(value: $canonical, valueSet: true, cursor: $canonical);
    }

    /**
     * Attach a validator (null clears). A {@see Validator} instance is fed
     * the committed value as a string — empty string while nothing is
     * committed — so {@see \SugarCraft\Forms\Validator\Required} means
     * "a date must be chosen". A Closure receives the `?string` directly.
     * Attaching re-judges the current value immediately (mirrors
     * {@see Confirm::withValidator()}).
     *
     * @param Validator|\Closure(?string):?string|null $v
     */
    public function withValidator(Validator|\Closure|null $v): self
    {
        if ($v instanceof Validator) {
            $fn = static function (?string $value) use ($v): ?string {
                $result = $v->validate($value ?? '');
                return $result === true ? null : (string) $result;
            };
        } else {
            $fn = $v;
        }
        return $this->mutate(validator: $fn, validatorSet: true)->revalidate();
    }

    // Short-form aliases. (`withValue` has none: `value()` is the interface
    // accessor and PHP forbids overloading it — the Slider/Color precedent.)
    public function title(string $t): self                      { return $this->withTitle($t); }
    public function desc(string $d): self                       { return $this->withDescription($d); }
    public function validator(Validator|\Closure|null $v): self { return $this->withValidator($v); }

    /**
     * @internal
     */
    public function revalidate(): Field
    {
        $err = $this->validator !== null ? ($this->validator)($this->value) : null;
        if ($err === $this->error) {
            return $this;
        }
        // Only the constructor writes $error (readonly); every other slot —
        // validator included — is a ctor param, so the shared trait carrier
        // covers exactly the remaining non-ctor state (E741).
        return $this->carryNonCtorState(new self(
            key: $this->key, value: $this->value, cursor: $this->cursor,
            focused: $this->focused, title: $this->title,
            description: $this->description, error: $err,
            validator: $this->validator,
        ));
    }

    // ------------------------------------------------------------------
    // Field contract
    // ------------------------------------------------------------------

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->value; }

    /** The highlighted day (`Y-m-d`) — the grid's anchor. */
    public function cursorDate(): string { return $this->cursor; }

    public function focus(): array { return [$this->mutate(focused: true), null]; }
    public function blur(): Field  { return $this->mutate(focused: false); }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }

        $char = static fn (string $r): bool => $msg->type === KeyType::Char
            && $msg->rune === $r && !$msg->ctrl;

        $moved = match (true) {
            // Shifted / paged month steps BEFORE the plain arrows so
            // Shift+Left never double-hits the day branch.
            $msg->type === KeyType::Left  && $msg->shift => $this->shiftMonth(-1),
            $msg->type === KeyType::Right && $msg->shift => $this->shiftMonth(+1),
            $msg->type === KeyType::PageUp               => $this->shiftMonth(-1),
            $msg->type === KeyType::PageDown             => $this->shiftMonth(+1),
            $msg->type === KeyType::Left  || $char('h')  => $this->shiftDays(-1),
            $msg->type === KeyType::Right || $char('l')  => $this->shiftDays(+1),
            $msg->type === KeyType::Up    || $char('k')  => $this->shiftDays(-7),
            $msg->type === KeyType::Down  || $char('j')  => $this->shiftDays(+7),
            $msg->type === KeyType::Home => $this->edgeOfMonth(first: true),
            $msg->type === KeyType::End  => $this->edgeOfMonth(first: false),
            default => null,
        };
        if ($moved !== null) {
            // Movement commits: value and cursor always agree afterwards.
            return [$this->mutate(value: $moved, valueSet: true, cursor: $moved), null];
        }

        if ($msg->type === KeyType::Space) {
            return [$this->mutate(value: $this->cursor, valueSet: true), null];
        }

        return [$this, null];
    }

    /**
     * Render title / description, the month header, the weekday row and the
     * calendar grid (Sunday-first; seven right-aligned two-character day
     * cells joined by single-space gutters), then the committed-value line.
     * The cursor cell carries the same reverse-video highlight
     * {@see Confirm} uses for its pill — these widgets have no themeable
     * style slots (the E736 3.9 rationale applies verbatim).
     */
    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }

        $day   = new \DateTimeImmutable($this->cursor);
        $year  = (int) $day->format('Y');
        $month = (int) $day->format('n');
        $lines[] = Lang::t('date.month_' . $month) . ' ' . $year;
        $lines[] = implode(' ', array_map(
            static fn (int $w): string => Lang::t('date.day_' . $w),
            range(0, 6),
        ));

        $offset = (int) $day->setDate($year, $month, 1)->format('w');
        $days   = (int) $day->format('t');
        $cells  = array_fill(0, $offset, '');
        for ($d = 1; $d <= $days; $d++) {
            $cells[] = (string) $d;
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = '';
        }
        $cursorDay = (int) $day->format('j');
        foreach (array_chunk($cells, 7) as $week) {
            $rendered = [];
            foreach ($week as $cell) {
                $text  = sprintf('%2s', $cell);
                if ($cell !== '' && (int) $cell === $cursorDay) {
                    $text = Ansi::sgr(Ansi::REVERSE) . $text . Ansi::reset();
                }
                $rendered[] = $text;
            }
            $lines[] = implode(' ', $rendered);
        }

        $lines[] = Lang::t('date.selected') . ': '
            . ($this->value !== null ? $this->value : Lang::t('date.none'));
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }

    /**
     * The default KeyMap binds Up/Down to form navigation; like
     * {@see Select} in list mode the picker claims exactly those while
     * focused (every other binding — arrows are its only collision) and
     * Tab keeps leaving the field.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->focused || !$msg instanceof KeyMsg) {
            return false;
        }
        return match ($msg->type) {
            KeyType::Up, KeyType::Down => true,
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // Internals — all date math funnels through the boundary parser
    // ------------------------------------------------------------------

    /**
     * Strict `Y-m-d` boundary parse: rejects junk (`not-a-date`),
     * non-zero-padded forms (`2026-3-5`) and impossible days
     * (`2026-02-30`, which PHP would otherwise roll into March) by
     * comparing the round-trip. The ONLY place external date text enters.
     */
    private static function parse(string $raw): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException(Lang::t('date.invalid_value'));
        }
        return $parsed->format('Y-m-d');
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** +/- days with natural month / year rollover. */
    private function shiftDays(int $delta): string
    {
        return (new \DateTimeImmutable($this->cursor))
            ->modify(sprintf('%+d day', $delta))
            ->format('Y-m-d');
    }

    /**
     * +/- month with the day clamped into the target month — walking off
     * 2026-03-31 lands on 2026-02-28, never March 3 or a year-skewed page.
     */
    private function shiftMonth(int $delta): string
    {
        $day    = new \DateTimeImmutable($this->cursor);
        $year   = (int) $day->format('Y');
        $month  = (int) $day->format('n');
        $target  = $day->setDate($year, $month, 1)->modify(sprintf('%+d month', $delta));
        $clamped = min((int) $day->format('j'), (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $clamped)
            ->format('Y-m-d');
    }

    private function edgeOfMonth(bool $first): string
    {
        $day   = new \DateTimeImmutable($this->cursor);
        $year  = (int) $day->format('Y');
        $month = (int) $day->format('n');
        return $day->setDate($year, $month, $first ? 1 : (int) $day->format('t'))
            ->format('Y-m-d');
    }

    /**
     * Hand-written immutable copy. The sentinels exist because `value` is
     * nullable — a bare `?? $this->value` could not tell "clear" from
     * "leave alone" (house XSet law).
     */
    private function mutate(
        ?string $value = null,
        bool $valueSet = false,
        ?string $cursor = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        ?\Closure $validator = null,
        bool $validatorSet = false,
    ): self {
        $next = new self(
            key:         $this->key,
            value:       $valueSet ? $value : $this->value,
            cursor:      $cursor      ?? $this->cursor,
            focused:     $focused     ?? $this->focused,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
            error:       $this->error,
            validator:   $validatorSet ? $validator : $this->validator,
        );
        $next = $this->carryNonCtorState($next);
        // Re-run the validator when the committed value changed (Confirm
        // keeps the same on-change discipline; the blur/submit gates call
        // revalidate() for the untouched cases).
        if ($next->value !== $this->value) {
            return $next->revalidate();
        }
        return $next;
    }
}
