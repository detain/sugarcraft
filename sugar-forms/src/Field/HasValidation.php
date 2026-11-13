<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Forms\Validator\Validator;

/**
 * Single-slot validation machinery for the selectable fields that never
 * carried a validator surface (sugar-forms-026).
 *
 * The upstream family attaches a `Validate` func to every selectable widget
 * (huh's textinput/select models); this library documented the same promise
 * in the README but shipped it only on Input/Text/Confirm/Date/Slider, so
 * Select, Color, RadioPills, FilePicker and NumberField painted no error and
 * `Form::validateAll()` silently passed them. This trait closes the matrix:
 * the same replace-semantics single slot as Confirm/Date/Slider, normalized
 * attach (a {@see Validator} instance is wrapped exactly once, per the
 * SF-19 single-invoke law), and a verdict that rides every rebuild.
 *
 * Immutability: the slots live OUTSIDE each host's private constructor and
 * are carried by `CarriesNonCtorState` (the E741 family funnel); a host's
 * `mutate()` wraps its rebuild in {@see validationRide()} so a value flip
 * re-runs the rule the same keystroke that caused it — the Confirm mutate
 * precedent, generalized. Hosts whose error slot is a constructor parameter
 * (NumberField's committed-value lane, MultiSelect's constraint lane) keep
 * the trait for rule storage but route the verdict through their own
 * pipeline by declaring their methods (class bodies win over trait bodies).
 *
 * Timing: the rule runs on attach, on every value change, and on
 * {@see revalidate()} (the submit gate). Input's deferred `withValidateOn`
 * lane stays Input-only — these widgets have no per-keystroke validator
 * cost because their values only move on deliberate edits.
 */
trait HasValidation
{
    /**
     * Normalized rule: a {@see Validator} instance arrives wrapped (single
     * invoke; string-cast subject — null reads '', a BackedEnum reads its
     * value, true reads '1' — the Confirm precedent); a closure is stored
     * verbatim and receives the raw {@see validationValue()}. Null = no
     * validator attached.
     *
     * @var (\Closure(mixed):?string)|null
     */
    private ?\Closure $fieldValidator = null;

    /** Latest verdict; the slot the default {@see error()} reads. */
    private ?string $fieldError = null;

    /**
     * Attach a validator (replaces the previous one; null clears). The new
     * rule judges the current value immediately — the Confirm attach
     * precedent — so a form restored over bad data shows its error on the
     * first frame, not after the first edit.
     *
     * @param Validator|\Closure(mixed):?string|null $validator
     */
    public function withValidator(Validator|\Closure|null $validator): self
    {
        $next           = $this->mutate();
        $next->fieldValidator = self::normalizeValidator($validator);
        return $next->revalidate();
    }

    /** Short-form alias for withValidator (README :124 alias contract). */
    public function validator(Validator|\Closure|null $validator): self
    {
        return $this->withValidator($validator);
    }

    /** Latest validator verdict, or null when the value passes validation. */
    public function error(): ?string
    {
        return $this->fieldError;
    }

    /**
     * The submit gate's re-judgement: run the rule against the current
     * value. Idempotent — a verdict identical to the stored one returns
     * `$this` (the Field contract's identity law, E736 1.3).
     */
    public function revalidate(): \SugarCraft\Forms\Field
    {
        $verdict = $this->fieldValidator === null ? null : ($this->fieldValidator)($this->validationValue());
        if ($verdict === $this->fieldError) {
            return $this;
        }
        $next           = $this->mutate();
        $next->fieldError = $verdict;
        return $next;
    }

    /**
     * The subject the rule runs against. Defaults to the field's value;
     * hosts with a draft/committed split override it to judge exactly what
     * `values()` reports.
     */
    protected function validationValue(): mixed
    {
        return $this->value();
    }

    /**
     * Rebuild funnel: when a `mutate()` moved the value, the verdict
     * recomputes in the same step (no stale error outlives the value that
     * earned it, and a fresh valid value clears the old row immediately).
     * Host mutate() bodies wrap their carryNonCtorState result in this.
     *
     * @param static $rebuilt
     */
    private function validationRide(self $rebuilt): self
    {
        if ($this->fieldValidator !== null && $rebuilt->value() !== $this->value()) {
            return $rebuilt->revalidate();
        }
        return $rebuilt;
    }

    /**
     * @param Validator|\Closure(mixed):?string|null $validator
     */
    private static function normalizeValidator(Validator|\Closure|null $validator): ?\Closure
    {
        if ($validator === null) {
            return null;
        }
        if (!$validator instanceof Validator) {
            return $validator;
        }
        return static function (mixed $subject) use ($validator): ?string {
            $text = match (true) {
                $subject === null  => '',
                $subject instanceof \BackedEnum => (string) $subject->value,
                $subject instanceof \UnitEnum   => $subject->name,
                default            => (string) $subject,
            };
            $result = $validator->validate($text);
            return $result === true ? null : (string) $result;
        };
    }

    /**
     * Shared error-row shape for hosts whose view() renders the verdict
     * (echo-safe: validator messages may quote user input — clean at the
     * display site, the stored slot stays raw).
     */
    private function validationErrorLines(bool $showErrors, ?\SugarCraft\Forms\Theme $theme): array
    {
        if (!$showErrors || $this->fieldError === null) {
            return [];
        }
        return [$this->themedErrorRow(\SugarCraft\Forms\Util\RenderSafe::clean($this->fieldError), $theme)];
    }
}
