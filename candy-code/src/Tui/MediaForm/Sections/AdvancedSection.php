<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm\Sections;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Code\Tui\MediaForm\OverrideRows;
use SugarCraft\Code\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

/**
 * The Advanced tab (crush-media W3.8, plan §4.2/§5.5): a typed-row editor
 * over `override_settings` — any core or extension option, applied for this
 * request only. The MODEL is the single source of truth: every commit
 * mutates the {@see OverrideRows} rows and every widget is then rebuilt
 * from them, so reorder/remove can never leave a widget describing a row it
 * no longer sits on.
 *
 * Key completion reads the lazily-fetched GET /sdapi/v1/options map when
 * the caller supplies one — as SUGGESTIONS ONLY. Free-typed keys are always
 * allowed: extension options are precisely the point (§5.5), and the server
 * is the only validator. When a typed key is a known option whose row is
 * still untouched (string type, no text), the row adopts the option's own
 * type and current value as a starting point.
 *
 * `override_settings_restore_afterwards` is the tab's one real wire row
 * (Confirm, default true — the A1111 safe direction of reverting the server
 * to its saved settings after the job). The empty editor emits NOTHING:
 * no override_settings key, and the companion flag rides only when
 * overrides actually ship (a lone restore flag would ask the server to
 * revert settings nobody changed).
 */
final class AdvancedSection implements Section, FieldValuedSection
{
    use RegistrySection {
        extraLabel as registryExtraLabel;
    }

    public const ROW_NOTE = 'adv_note';
    public const ROW_ADD = 'adv_add';
    public const ROW_RESTORE = 'adv_restore';

    private const KEY_PREFIX = 'adv_key:';
    private const TYPE_PREFIX = 'adv_type:';
    private const VALUE_PREFIX = 'adv_value:';
    private const UP_PREFIX = 'adv_up:';
    private const DOWN_PREFIX = 'adv_down:';
    private const REMOVE_PREFIX = 'adv_remove:';

    /**
     * @param array<string, mixed>|null $optionsMap the /sdapi/v1/options payload, null when undiscovered
     */
    protected readonly array $specs;

    private function __construct(
        protected readonly array $fields,
        protected readonly array $rowKeys,
        private readonly OverrideRows $model,
        private readonly ?array $optionsMap,
        private readonly bool $restore,
    ) {
        $this->specs = [];
    }

    /**
     * @param array<string, mixed>|null $optionsMap
     */
    public static function forDraft(MediaRequest $draft, ?array $optionsMap = null): self
    {
        $model = OverrideRows::fromAssoc($draft->overrideSettings() ?? []);
        $restore = $draft->overrideSettingsRestoreAfterwards() ?? true;

        return self::build($model, $optionsMap, $restore);
    }

    public function key(): string
    {
        return 'advanced';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_ADVANCED);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        $pairs = $this->model->toAssoc();
        if ($pairs === []) {
            // The empty editor emits no keys; stale clears only fire when
            // the draft actually carries the pair (fresh-draft key law).
            if ($draft->isSet('override_settings')) {
                $draft = $draft->withOverrideSettings(null);
            }

            return $draft->isSet('override_settings_restore_afterwards')
                ? $draft->withOverrideSettingsRestoreAfterwards(null)
                : $draft;
        }

        return $draft
            ->withOverrideSettings($pairs)
            ->withOverrideSettingsRestoreAfterwards($this->restore);
    }

    protected function accept(string $key, Field $field): static
    {
        $index = self::rowIndex($key, self::KEY_PREFIX);
        if ($index !== null) {
            $model = $this->model->withKey($index, (string) $field->value());
            $suggested = $this->optionsMap[$model->row($index)['key']] ?? null;
            $row = $model->row($index);
            if ($this->optionsMap !== null && $suggested !== null
                && $row['type'] === 'string' && $row['text'] === '') {
                $model = $model
                    ->withType($index, OverrideRows::inferType($suggested))
                    ->withText($index, OverrideRows::stringify($suggested));
            }

            return self::build($model, $this->optionsMap, $this->restore);
        }

        $index = self::rowIndex($key, self::TYPE_PREFIX);
        if ($index !== null) {
            return self::build(
                $this->model->withType($index, (string) $field->value()),
                $this->optionsMap,
                $this->restore,
            );
        }

        $index = self::rowIndex($key, self::VALUE_PREFIX);
        if ($index !== null) {
            return self::build(
                $this->model->withText($index, (string) $field->value()),
                $this->optionsMap,
                $this->restore,
            );
        }

        if ($key === self::ROW_ADD) {
            return self::build($this->model->appended(), $this->optionsMap, $this->restore);
        }

        $index = self::rowIndex($key, self::REMOVE_PREFIX);
        if ($index !== null) {
            return self::build($this->model->dropped($index), $this->optionsMap, $this->restore);
        }

        $index = self::rowIndex($key, self::UP_PREFIX);
        if ($index !== null) {
            return self::build($this->model->moved($index, -1), $this->optionsMap, $this->restore);
        }

        $index = self::rowIndex($key, self::DOWN_PREFIX);
        if ($index !== null) {
            return self::build($this->model->moved($index, 1), $this->optionsMap, $this->restore);
        }

        if ($key === self::ROW_RESTORE) {
            return self::build($this->model, $this->optionsMap, (bool) $field->value());
        }

        return $this;
    }

    protected function extraLabel(string $key): string
    {
        foreach ([
            self::KEY_PREFIX => GenFormKeys::ADVANCED_KEY,
            self::TYPE_PREFIX => GenFormKeys::ADVANCED_TYPE,
            self::VALUE_PREFIX => GenFormKeys::ADVANCED_VALUE,
            self::UP_PREFIX => GenFormKeys::ADVANCED_UP,
            self::DOWN_PREFIX => GenFormKeys::ADVANCED_DOWN,
            self::REMOVE_PREFIX => GenFormKeys::ADVANCED_REMOVE,
        ] as $prefix => $labelKey) {
            if (self::rowIndex($key, $prefix) !== null) {
                return Lang::t($labelKey);
            }
        }

        return match ($key) {
            self::ROW_NOTE => Lang::t(GenFormKeys::ADVANCED_NOTE),
            self::ROW_ADD => Lang::t(GenFormKeys::ADVANCED_ADD),
            self::ROW_RESTORE => Lang::t(GenFormKeys::ADVANCED_RESTORE_AFTERWARDS),
            default => $this->registryExtraLabel($key),
        };
    }

    protected function display(string $key): string
    {
        if ($key === self::ROW_NOTE) {
            return '';
        }

        $index = self::rowIndex($key, self::VALUE_PREFIX);
        if ($index !== null) {
            $row = $this->model->row($index);
            $error = OverrideRows::errorText($row['type'], $row['text']);

            return $error !== null
                ? 'error: ' . $error
                : ($row['text'] === '' ? Lang::t(GenFormKeys::NOT_SET) : $row['text']);
        }

        foreach ([self::UP_PREFIX, self::DOWN_PREFIX, self::REMOVE_PREFIX, self::ROW_ADD] as $momentary) {
            if ($key === $momentary || self::rowIndex($key, $momentary) !== null) {
                return '';
            }
        }

        $field = $this->fields[$key] ?? null;
        if ($field === null) {
            return '';
        }

        if ($field instanceof Confirm) {
            return self::boolCell((bool) $field->value());
        }

        $value = (string) $field->value();

        return $value === '' ? Lang::t(GenFormKeys::NOT_SET) : $value;
    }

    /**
     * Recreate every widget from the model — the row's widgets are pure
     * projections, so a reorder can never strand one on the wrong row.
     *
     * @param array<string, mixed>|null $optionsMap
     */
    private static function build(OverrideRows $model, ?array $optionsMap, bool $restore): self
    {
        $fields = [];
        $suggestions = $optionsMap === null ? null : array_keys($optionsMap);
        foreach ($model->rows() as $index => $row) {
            $key = self::KEY_PREFIX . $index;
            $widget = Input::new($key)->withValue($row['key']);
            $fields[$key] = $suggestions === null ? $widget : $widget->withSuggestions($suggestions);
            $fields[self::TYPE_PREFIX . $index] = Select::new(self::TYPE_PREFIX . $index)
                ->withOptions(...OverrideRows::TYPES)
                ->withSelected($row['type']);
            $fields[self::VALUE_PREFIX . $index] = Input::new(self::VALUE_PREFIX . $index)
                ->withValue($row['text']);
            $fields[self::REMOVE_PREFIX . $index] = Confirm::new(self::REMOVE_PREFIX . $index, false)
                ->withTitle(Lang::t(GenFormKeys::ADVANCED_REMOVE));
            if ($index > 0) {
                $fields[self::UP_PREFIX . $index] = Confirm::new(self::UP_PREFIX . $index, false)
                    ->withTitle(Lang::t(GenFormKeys::ADVANCED_UP));
            }

            if ($index < $model->count() - 1) {
                $fields[self::DOWN_PREFIX . $index] = Confirm::new(self::DOWN_PREFIX . $index, false)
                    ->withTitle(Lang::t(GenFormKeys::ADVANCED_DOWN));
            }
        }

        $fields[self::ROW_RESTORE] = Confirm::new(self::ROW_RESTORE, $restore)
            ->withTitle(Lang::t(GenFormKeys::ADVANCED_RESTORE_AFTERWARDS));

        return new self($fields, self::layout($model), $model, $optionsMap, $restore);
    }

    /**
     * @return list<string>
     */
    private static function layout(OverrideRows $model): array
    {
        $rowKeys = [self::ROW_NOTE];
        $last = $model->count() - 1;
        foreach ($model->rows() as $index => $row) {
            $rowKeys[] = self::KEY_PREFIX . $index;
            $rowKeys[] = self::TYPE_PREFIX . $index;
            $rowKeys[] = self::VALUE_PREFIX . $index;
            if ($index > 0) {
                $rowKeys[] = self::UP_PREFIX . $index;
            }

            if ($index < $last) {
                $rowKeys[] = self::DOWN_PREFIX . $index;
            }

            $rowKeys[] = self::REMOVE_PREFIX . $index;
        }

        $rowKeys[] = self::ROW_ADD;
        $rowKeys[] = self::ROW_RESTORE;

        return $rowKeys;
    }

    private static function rowIndex(string $key, string $prefix): ?int
    {
        if (!str_starts_with($key, $prefix)) {
            return null;
        }

        $tail = substr($key, strlen($prefix));

        return ctype_digit($tail) ? (int) $tail : null;
    }
}
