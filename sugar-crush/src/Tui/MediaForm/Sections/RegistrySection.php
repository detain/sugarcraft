<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaFormState;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;

/**
 * Shared plumbing for the FieldSpec-driven sections (crush-media W3.4): the
 * registry rows are the ONLY source of what exists on screen and what folds
 * into the draft, so lanes W3.5-W3.9 add rows to {@see FieldSpec} and extend
 * this class without touching {@see \SugarCraft\Crush\Tui\MediaForm\MediaForm}.
 *
 * A section owns `key => Field` widgets keyed by the wire name, plus an
 * ordered row-key list (spec rows first, then section-local rows such as the
 * seed action pair). Rows whose key has no field — or which a subclass
 * removes from {@see editableSpecs()} — are read-only or hidden, and hidden
 * rows contribute to neither collect() nor the hydration map (W3.5 law).
 *
 * A TRAIT, not an abstract base, because `src/` ships no abstract class as a
 * file's own PSR-4 symbol (the BuiltInToolCorpus policy the whole tree
 * obeys): a using section declares this trait's three lattice properties
 * itself — `protected readonly array $specs` (registry rows keyed by wire
 * name, display order), `$fields` (current widgets keyed the same way) and
 * `$rowKeys` (every row, spec keys plus section-local keys, display order) —
 * by constructor promotion or constructor-body assignment, and implements the
 * {@see Section} and {@see FieldValuedSection} contracts. Where a section
 * shadows one of the plumbing methods and still needs the shared body, it
 * calls the aliased trait copy ($this->registryCollect() and friends).
 */
trait RegistrySection
{
    public function visible(MediaFormState $state): bool
    {
        return true;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return array_map($this->rowLabel(...), $this->visibleRowKeys());
    }

    public function fieldAt(int $row): ?Field
    {
        $key = $this->visibleRowKeys()[$row] ?? null;

        return $key === null ? null : ($this->fields[$key] ?? null);
    }

    /** Hand a committed field back to the row that owns its key. */
    public function withField(Field $field): Section
    {
        return \in_array($field->key(), $this->rowKeys, true)
            ? $this->accept($field->key(), $field)
            : $this;
    }

    public function valueAt(int $row): string
    {
        return $this->display($this->visibleRowKeys()[$row] ?? '');
    }

    /** Fold every editable registry row into the draft, registry order. */
    public function collect(MediaRequest $draft): MediaRequest
    {
        foreach ($this->editableSpecs() as $key => $spec) {
            $field = $this->fields[$key] ?? null;
            if ($field !== null) {
                $draft = $spec->applyTo($draft, $field->value());
            }
        }

        return $draft;
    }

    /** @return array<string, mixed> */
    public function fieldValues(): array
    {
        $values = [];
        foreach ($this->editableSpecs() as $key => $spec) {
            $field = $this->fields[$key] ?? null;
            if ($field !== null) {
                $values[$key] = $field->value();
            }
        }

        return $values;
    }

    /**
     * Rebuild the widgets from a flat wire-keyed map (infotext/preset
     * import). Junk for a row is skipped, never coerced into the wire — the
     * widgets still clamp everything that does land.
     *
     * @param array<string, mixed> $values
     */
    public function withFieldValues(array $values): static
    {
        $changes = [];
        foreach ($this->editableSpecs() as $key => $spec) {
            if (!\array_key_exists($key, $values)) {
                continue;
            }

            $field = $this->fields[$key] ?? null;
            $next = $field === null ? null : $this->inject($spec, $field, $values[$key]);
            if ($next !== null) {
                $changes[$key] = $next;
            }
        }

        return $changes === [] ? $this : $this->rebuild($changes);
    }

    /** Rows that render AND collect; hidden groups drop out here (W3.5). */
    protected function editableSpecs(): array
    {
        return $this->specs;
    }

    /**
     * Fold the `ui.mediaDisplayOverrides` map into this section's lattice
     * (crush-media W3.9): accepted range/value props rebuild the row's spec
     * and reshape its widget (carrying an already-touched value through the
     * new range, clamping via the widget's own coercion), and a
     * `visible: false` row is cut from specs, fields, AND rowKeys — so it
     * renders nowhere and collects nothing, the W3.5 hidden-row law applied
     * at build time. Unresolvable ids are silently ignored (§5.5 tolerance);
     * a section with no registry specs (Scripts, Advanced, Presets) has
     * nothing to override and returns itself.
     *
     * One disclosed shape: a reshaped option-row loses a runtime choice feed
     * it received through its constructor (the rebuilt widget falls back to
     * the registry's static option list) — overrides moving a Select row's
     * default while a live /samplers feed is up are rare, and a wrong-feed
     * drop clamps through withSelected's own refusal rather than forging one.
     *
     * @param array<string, mixed> $overrides
     */
    public function withDisplayOverrides(array $overrides): static
    {
        if ($overrides === [] || $this->specs === []) {
            return $this;
        }

        [$specs, $changed] = \SugarCraft\Crush\Tui\MediaForm\DisplayOverrides::apply($this->specs, $overrides);
        $hidden = array_diff_key($this->specs, $specs);
        if ($changed === [] && $hidden === []) {
            return $this;
        }

        $fields = $this->fields;
        $rowKeys = [];
        foreach ($this->rowKeys as $key) {
            if (isset($hidden[$key])) {
                unset($fields[$key]);
                continue;
            }

            $rowKeys[] = $key;
            if (isset($changed[$key])) {
                $fields[$key] = $this->reshapeField($this->specs[$key], $specs[$key]);
            }
        }

        return $this->rebuildLattice($specs, $fields, $rowKeys);
    }

    /**
     * Rebuild over a transformed lattice; subclasses thread their extra
     * constructor state through this door exactly as {@see rebuild()} does.
     *
     * @param array<string, FieldSpec> $specs
     * @param array<string, Field> $fields
     * @param list<string> $rowKeys
     */
    protected function rebuildLattice(array $specs, array $fields, array $rowKeys): static
    {
        return new static($specs, $fields, $rowKeys);
    }

    /**
     * The reshaped widget for a row whose props moved: fresh bounds from the
     * new spec, the row's label, and — the part that matters — the operator's
     * value survives whenever it differed from the OLD default (untouched
     * rows adopt the NEW default, which is precisely what "pre-fill" means).
     */
    private function reshapeField(FieldSpec $old, FieldSpec $new): Field
    {
        $fresh = $new->createField($new->key)->withTitle(Lang::t($new->labelKey));
        $existing = $this->fields[$old->key] ?? null;
        $current = $existing?->value();
        $baseline = $old->widget === FieldSpec::WIDGET_MULTI ? $old->defaultList : $old->default;
        $effective = $current !== null && $current != $baseline
            ? $current
            : ($new->widget === FieldSpec::WIDGET_MULTI ? ($new->defaultList ?? []) : $new->default);

        return $effective === null ? $fresh : ($this->inject($new, $fresh, $effective) ?? $fresh);
    }

    /**
     * The row keys currently on screen — every row by default; a disclosure
     * subclass filters this to its gate state so a collapsed group is not
     * even a row (W3.5: the index math MediaForm consumes is positional over
     * exactly what renders).
     *
     * @return list<string>
     */
    protected function visibleRowKeys(): array
    {
        return array_values($this->rowKeys);
    }

    /**
     * The stale-clearance fold (W3.5 disclosure law): a registered row that
     * {@see editableSpecs()} currently drops never rides the wire on a
     * previous value — if the draft already carries the key it folds to
     * null (the DTO's set-to-default, guarded server-side per field), and a
     * group never touched stays absent.
     */
    protected function clearGatedOff(MediaRequest $draft): MediaRequest
    {
        $editable = $this->editableSpecs();
        foreach ($this->specs as $key => $spec) {
            if (!isset($editable[$key]) && $draft->isSet($key)) {
                $draft = $spec->applyTo($draft, null);
            }
        }

        return $draft;
    }

    /** The list cell for a boolean row — Confirm widgets have no cast text. */
    protected static function boolCell(bool $on): string
    {
        return Lang::t($on ? GenFormKeys::STATE_ON : GenFormKeys::STATE_OFF);
    }

    /** Where a committed field lands; subclasses intercept action rows here. */
    protected function accept(string $key, Field $field): static
    {
        return $this->rebuild([$key => $field]);
    }

    /**
     * @param array<string, Field> $changes
     */
    protected function rebuild(array $changes): static
    {
        return new static($this->specs, [...$this->fields, ...$changes], $this->rowKeys);
    }

    protected function rowLabel(string $key): string
    {
        $spec = $this->specs[$key] ?? null;

        return $spec === null ? $this->extraLabel($key) : Lang::t($spec->labelKey);
    }

    /** Label for a section-local (non-registry) row; unknown keys fail loud. */
    protected function extraLabel(string $key): string
    {
        throw new \InvalidArgumentException('section ' . $this->key() . ' has no row ' . $key);
    }

    protected function display(string $key): string
    {
        $spec = $this->specs[$key] ?? null;
        $field = $this->fields[$key] ?? null;
        if ($spec === null) {
            // Section-local rows carry their own widgets; a Confirm toggle
            // (the W3.5 disclosure gates) reads as its on/off cell.
            return $field instanceof Confirm
                ? self::boolCell((bool) $field->value())
                : Lang::t(GenFormKeys::NOT_SET);
        }
        if ($field === null) {
            return Lang::t(GenFormKeys::NOT_SET);
        }

        $value = $field->value();

        return match ($spec->widget) {
            FieldSpec::WIDGET_TEXT => ($line = str_replace(["\r\n", "\n", "\r"], ' ', (string) $value)) === ''
                ? Lang::t(GenFormKeys::NOT_SET)
                : $line,
            FieldSpec::WIDGET_MULTI => !is_array($value) || $value === []
                ? Lang::t(GenFormKeys::NOT_SET)
                : implode(', ', array_map(strval(...), $value)),
            FieldSpec::WIDGET_CONFIRM => self::boolCell((bool) $value),
            default => (string) $value,
        };
    }

    /** Push one hydrated value into the widget of its row. */
    protected function inject(FieldSpec $spec, Field $field, mixed $value): ?Field
    {
        return match ($spec->widget) {
            FieldSpec::WIDGET_TEXT => $field instanceof Text && is_string($value)
                ? $field->withValue($value)
                : null,
            FieldSpec::WIDGET_SLIDER, FieldSpec::WIDGET_NUMBER => ($number = self::numeric($value)) !== null
                && ($field instanceof Slider || $field instanceof NumberField)
                ? $field->withValue($number)
                : null,
            FieldSpec::WIDGET_SELECT => $field instanceof Select && is_string($value)
                ? $field->withSelected($value)
                : null,
            FieldSpec::WIDGET_MULTI => $field instanceof MultiSelect && is_array($value)
                ? $field
                    ->withOptions(...array_values(array_unique([...($spec->options ?? []), ...array_map(strval(...), $value)])))
                    ->withValue(array_map(strval(...), $value))
                : null,
            // Confirm has no value setter — hydration rebuilds the widget at
            // the imported bool, keeping its title (the row's own label).
            FieldSpec::WIDGET_CONFIRM => $field instanceof Confirm && is_bool($value)
                ? Confirm::new($field->key(), $value)->withTitle($field->getTitle())
                : null,
            // Radios hold their option LABEL; a value outside the row's
            // option list is junk and refuses (null), never a silent guess.
            FieldSpec::WIDGET_RADIO => $field instanceof RadioPills && is_string($value)
                && \in_array($value, $spec->optionLabels(), true)
                ? $field->withValue($value)
                : null,
            default => null,
        };
    }

    /** Numeric scalar or numeric string, else null (junk never reaches a widget). */
    protected static function numeric(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? $value + 0 : null;
    }
}
