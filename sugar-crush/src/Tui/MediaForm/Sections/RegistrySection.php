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
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\NumberField;
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
 */
abstract class RegistrySection implements Section, FieldValuedSection
{
    /**
     * @param array<string, FieldSpec> $specs registry rows keyed by wire key, display order
     * @param array<string, Field> $fields current widgets keyed the same way
     * @param list<string> $rowKeys every row (spec keys plus section-local keys) in display order
     */
    protected function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
    ) {
    }

    abstract public function key(): string;

    abstract public function label(): string;

    public function visible(MediaFormState $state): bool
    {
        return true;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return array_map($this->rowLabel(...), $this->rowKeys);
    }

    public function fieldAt(int $row): ?Field
    {
        $key = $this->rowKeys[$row] ?? null;

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
        return $this->display($this->rowKeys[$row] ?? '');
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
        if ($spec === null || $field === null) {
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
