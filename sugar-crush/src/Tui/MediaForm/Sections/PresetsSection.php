<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Crush\Tui\MediaForm\MediaPresets;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Text;

/**
 * The Presets tab (crush-media W3.9, plan §5.8 mirror-item 4): a name row,
 * the A1111-style `[x] name` multi-select of stored presets, and the three
 * press rows — Save folds the whole form's current values under the typed
 * name, Apply hydrates the first checked preset into every section, Delete
 * removes every checked name. The store itself is {@see MediaPresets}; this
 * section only ever *requests* those operations, reporting the chosen
 * command once through {@see pendingCommand()} so the form — which alone can
 * hydrate across sections and reach the persist door — runs it (W3.4's
 * press-row doctrine: the row resets itself, exactly like the seed ♻ pair).
 *
 * No registry specs: every row here is section-local, so the W3.9 display
 * overrides have nothing to move on this tab and the base early-return keeps
 * it that way.
 */
final class PresetsSection implements Section, FieldValuedSection
{
    use RegistrySection {
        collect as registryCollect;
        fieldAt as registryFieldAt;
        accept as registryAccept;
        display as registryDisplay;
        extraLabel as registryExtraLabel;
    }

    public const string ROW_NAME = 'preset_name';
    public const string ROW_NAMES = 'preset_names';
    public const string ROW_SAVE = 'preset_save';
    public const string ROW_APPLY = 'preset_apply';
    public const string ROW_DELETE = 'preset_delete';

    /**
     * @param array<string, Field> $fields
     * @param list<string> $rowKeys
     * @param array{0: string, 1: mixed}|null $pending
     */
    protected readonly array $specs;

    private function __construct(
        protected readonly array $fields,
        protected readonly array $rowKeys,
        private readonly ?MediaPresets $presets = null,
        private readonly ?array $pending = null,
    ) {
        $this->specs = [];
    }

    public static function forDraft(?MediaPresets $presets): self
    {
        $fields = [
            self::ROW_NAME => Text::new(self::ROW_NAME)
                ->withHeight(1)
                ->withTitle(Lang::t(GenFormKeys::PRESET_NAME)),
            self::ROW_NAMES => MultiSelect::new(self::ROW_NAMES)
                ->withOptions(...($presets?->list() ?? []))
                ->withTitle(Lang::t(GenFormKeys::PRESET_LOAD)),
            self::ROW_SAVE => self::pressRow(self::ROW_SAVE),
            self::ROW_APPLY => self::pressRow(self::ROW_APPLY),
            self::ROW_DELETE => self::pressRow(self::ROW_DELETE),
        ];

        return new self($fields, array_keys($fields), $presets);
    }

    public function key(): string
    {
        return 'presets';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_PRESETS);
    }

    /** The one-shot command this commit asked the form to run, if any. */
    public function pendingCommand(): ?array
    {
        return $this->pending;
    }

    /** The same section with its pending command consumed. */
    public function cleared(): self
    {
        return new self($this->fields, $this->rowKeys, $this->presets, null);
    }

    protected function extraLabel(string $key): string
    {
        return match ($key) {
            self::ROW_NAME => Lang::t(GenFormKeys::PRESET_NAME),
            self::ROW_NAMES => Lang::t(GenFormKeys::PRESET_LOAD),
            self::ROW_SAVE => Lang::t(GenFormKeys::PRESET_SAVE),
            self::ROW_APPLY => Lang::t(GenFormKeys::PRESET_APPLY),
            self::ROW_DELETE => Lang::t(GenFormKeys::PRESET_DELETE),
            default => throw new \InvalidArgumentException('presets has no row ' . $key),
        };
    }

    protected function accept(string $key, Field $field): static
    {
        return match ($key) {
            self::ROW_SAVE => $this->press($key, ['save', $this->nameValue()]),
            self::ROW_APPLY => $this->press($key, ['apply', $this->firstChecked()]),
            self::ROW_DELETE => $this->press($key, ['delete', $this->checked()]),
            default => $this->registryAccept($key, $field),
        };
    }

    protected function rebuild(array $changes): static
    {
        return new self([...$this->fields, ...$changes], $this->rowKeys, $this->presets, $this->pending);
    }

    protected function display(string $key): string
    {
        return match ($key) {
            self::ROW_NAME => $this->nameValue() === '' ? Lang::t(GenFormKeys::NOT_SET) : $this->nameValue(),
            self::ROW_NAMES => $this->namesCell(),
            // Press rows are actions, not states — no cell (the seed ♻ pair's law).
            self::ROW_SAVE, self::ROW_APPLY, self::ROW_DELETE => '',
            default => $this->registryDisplay($key),
        };
    }

    /** @param array{0: string, 1: mixed} $command */
    private function press(string $key, array $command): static
    {
        return new self(
            [...$this->fields, $key => self::pressRow($key)],
            $this->rowKeys,
            $this->presets,
            $command,
        );
    }

    private static function pressRow(string $key): Confirm
    {
        return Confirm::new($key)->withTitle(Lang::t(match ($key) {
            self::ROW_SAVE => GenFormKeys::PRESET_SAVE,
            self::ROW_APPLY => GenFormKeys::PRESET_APPLY,
            default => GenFormKeys::PRESET_DELETE,
        }));
    }

    private function nameValue(): string
    {
        $name = $this->fields[self::ROW_NAME] ?? null;

        return trim($name instanceof Text ? (string) $name->value() : '');
    }

    /** @return list<string> the checked preset names, option order */
    private function checked(): array
    {
        $picker = $this->fields[self::ROW_NAMES] ?? null;

        return $picker instanceof MultiSelect
            ? array_map(strval(...), (array) $picker->value())
            : [];
    }

    private function firstChecked(): string
    {
        return $this->checked()[0] ?? '';
    }

    /** The house-style `[x] name` list of what the store holds. */
    private function namesCell(): string
    {
        $names = $this->presets?->list() ?? [];
        if ($names === []) {
            return Lang::t(GenFormKeys::NOT_SET);
        }

        $checked = $this->checked();

        return implode('  ', array_map(
            static fn (string $name): string => (\in_array($name, $checked, true) ? '[x] ' : '[ ] ') . $name,
            $names,
        ));
    }
}
