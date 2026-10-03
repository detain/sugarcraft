<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Config\Settings\Validator\AbsolutePathValidator;
use SugarCraft\Crush\Config\Settings\Validator\EnumValidator;
use SugarCraft\Crush\Config\Settings\Validator\GlobListValidator;
use SugarCraft\Crush\Config\Settings\Validator\RangeValidator;
use SugarCraft\Crush\Config\Settings\Validator\UrlValidator;

/**
 * One settings key, described once: its shape, default, tier, risk, when it
 * applies, which environment variable or flag outranks it, and which method
 * actually reads it.
 *
 * THE READERS STAY WHERE THEY ARE. A definition does not replace
 * `Bootstrap::selectedProviderName()` or any other reader — it NAMES it
 * ({@see $readerSymbol}, resolved by reflection in the schema test), so the
 * doc table's "Read by" column is checked rather than proof-read, and a key
 * whose reader is deleted reds instead of lingering as surface that looks
 * configurable.
 *
 * SAFE DEFAULTS FOR A FORGOTTEN FIELD. A definition built with only
 * {@see new()} is user-config-only, not layered, not project-settable,
 * {@see RiskClass::Security}, applies at {@see ApplyMode::Restart} — so a
 * definition that forgot to classify itself can never widen what a
 * repository may set.
 *
 * Labels and help are literal English (decision D7); {@see $labelKey} and
 * {@see $helpKey} are kept so the deferred i18n step can resolve them instead.
 */
final class SettingDefinition
{
    /**
     * @param list<string> $enumValues
     * @param list<SettingValidator> $validators
     */
    private function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public readonly mixed $default,
        public readonly SettingCategory $category,
        public readonly RiskClass $riskClass,
        public readonly bool $projectSettable,
        public readonly bool $layered,
        public readonly bool $strict,
        public readonly ApplyMode $applyMode,
        public readonly ?string $envVar,
        public readonly ?string $cliFlag,
        public readonly UiEditability $ui,
        public readonly array $enumValues,
        public readonly ?OptionsSource $optionsSource,
        public readonly int|float|null $min,
        public readonly int|float|null $max,
        public readonly string $label,
        public readonly string $labelKey,
        public readonly string $help,
        public readonly string $helpKey,
        public readonly ?string $docAnchor,
        public readonly ?string $readerSymbol,
        public readonly ?string $readBy,
        public readonly array $validators,
    ) {
    }

    /**
     * `$default` is the value a reader falls back to when no tier sets the key;
     * `null` means "unset", which is distinct from `0`, `""` or `[]` — an unset
     * `maxOutputTokens` sends no ceiling at all.
     */
    public static function new(string $key, SettingType $type, mixed $default = null): self
    {
        if (preg_match('/^[a-z][A-Za-z0-9]*(\.[a-z][A-Za-z0-9]*)*$/', $key) !== 1) {
            throw new \InvalidArgumentException("setting key '{$key}' is not a camelCase (optionally dotted) name");
        }

        return new self(
            key: $key,
            type: $type,
            default: $default,
            category: SettingCategory::Advanced,
            riskClass: RiskClass::Security,
            projectSettable: false,
            layered: false,
            strict: false,
            applyMode: ApplyMode::Restart,
            envVar: null,
            cliFlag: null,
            ui: UiEditability::Easy,
            enumValues: [],
            optionsSource: null,
            min: null,
            max: null,
            label: $key,
            labelKey: 'settings.' . $key . '.label',
            help: '',
            helpKey: 'settings.' . $key . '.help',
            docAnchor: null,
            readerSymbol: null,
            readBy: null,
            validators: [],
        );
    }

    public function withCategory(SettingCategory $category): self
    {
        return $this->mutate(category: $category);
    }

    public function withRiskClass(RiskClass $riskClass): self
    {
        return $this->mutate(riskClass: $riskClass);
    }

    /** Whether a trusted project's settings files may contribute this key. */
    public function withProjectSettable(bool $projectSettable = true): self
    {
        return $this->mutate(projectSettable: $projectSettable);
    }

    /**
     * Whether `LayeredSettings` merges this key from `~/.sugar-crush/settings.json`
     * (and, if project-settable, the project's files); a non-layered key is
     * answered by `config.json` alone.
     */
    public function withLayered(bool $layered = true): self
    {
        return $this->mutate(layered: $layered);
    }

    /**
     * Whether the key travels the STRICT permission reader
     * (`Bootstrap::permissionConfigLayers()`), which also reads
     * `~/.sugar-crush/settings.json` but refuses the launch on a malformed file.
     */
    public function withStrict(bool $strict = true): self
    {
        return $this->mutate(strict: $strict);
    }

    public function withApplyMode(ApplyMode $applyMode): self
    {
        return $this->mutate(applyMode: $applyMode);
    }

    public function withEnvVar(?string $envVar): self
    {
        return $this->mutate(envVar: $envVar);
    }

    public function withCliFlag(?string $cliFlag): self
    {
        return $this->mutate(cliFlag: $cliFlag);
    }

    public function withUi(UiEditability $ui): self
    {
        return $this->mutate(ui: $ui);
    }

    /** @param list<string> $enumValues */
    public function withEnumValues(array $enumValues): self
    {
        return $this->mutate(enumValues: array_values($enumValues));
    }

    public function withOptionsSource(?OptionsSource $optionsSource): self
    {
        return $this->mutate(optionsSource: $optionsSource);
    }

    public function withRange(int|float|null $min, int|float|null $max = null): self
    {
        return $this->mutate(min: $min, max: $max);
    }

    public function withLabel(string $label): self
    {
        return $this->mutate(label: $label);
    }

    public function withLabelKey(string $labelKey): self
    {
        return $this->mutate(labelKey: $labelKey);
    }

    public function withHelp(string $help): self
    {
        return $this->mutate(help: $help);
    }

    public function withHelpKey(string $helpKey): self
    {
        return $this->mutate(helpKey: $helpKey);
    }

    public function withDocAnchor(?string $docAnchor): self
    {
        return $this->mutate(docAnchor: $docAnchor);
    }

    /**
     * The method that really reads the key, as `Fully\Qualified\Class::method`
     * — resolved by reflection in {@see \SugarCraft\Crush\Tests\Config\Settings\SettingsSchemaTest}.
     */
    public function withReaderSymbol(string $readerSymbol): self
    {
        if (!str_contains($readerSymbol, '::')) {
            throw new \InvalidArgumentException("reader symbol '{$readerSymbol}' is not Class::method");
        }

        return $this->mutate(readerSymbol: $readerSymbol);
    }

    /**
     * The "Read by" cell of the generated key table, when the read path is
     * worth more than the one method {@see $readerSymbol} names (e.g.
     * "`Bootstrap::tools()` → `filterToolSet()`"). Defaults to that method.
     */
    public function withReadBy(?string $readBy): self
    {
        return $this->mutate(readBy: $readBy);
    }

    public function withValidators(SettingValidator ...$validators): self
    {
        return $this->mutate(validators: array_values($validators));
    }

    /** `Class::method()` with the namespace dropped, for prose and tables. */
    public function readerShort(): ?string
    {
        if ($this->readerSymbol === null) {
            return null;
        }

        [$class, $method] = explode('::', $this->readerSymbol, 2);
        $parts = explode('\\', $class);

        return end($parts) . '::' . $method . '()';
    }

    /** The "Read by" text: {@see $readBy}, else `` `Class::method()` ``. */
    public function readByText(): string
    {
        if ($this->readBy !== null) {
            return $this->readBy;
        }

        $short = $this->readerShort();

        return $short === null ? '—' : '`' . $short . '`';
    }

    /**
     * Every source that can supply this key, lowest precedence first.
     *
     * Mirrors what the readers actually consult today rather than what would be
     * tidy: a layered key comes from the user's `settings.json` and, only if
     * project-settable, from the two project files; a strict key comes from
     * `settings.json` through the permission reader instead; anything else is
     * `config.json` alone. The session overlay is offered to every key that is
     * not frozen for the process.
     *
     * @return list<SettingSource>
     */
    public function sources(): array
    {
        $sources = [SettingSource::Default];
        if ($this->layered && $this->projectSettable) {
            $sources[] = SettingSource::ProjectShared;
            $sources[] = SettingSource::ProjectLocal;
        }

        if ($this->layered || $this->strict) {
            $sources[] = SettingSource::UserSettings;
        }

        $sources[] = SettingSource::UserConfig;
        if ($this->applyMode !== ApplyMode::Frozen) {
            $sources[] = SettingSource::Session;
        }

        if ($this->envVar !== null) {
            $sources[] = SettingSource::Env;
        }

        if ($this->cliFlag !== null) {
            $sources[] = SettingSource::Flag;
        }

        return $sources;
    }

    /**
     * The validators this key is held to: the explicit ones, plus what its
     * type, range and vocabulary already imply.
     *
     * @return list<SettingValidator>
     */
    public function effectiveValidators(): array
    {
        $implied = match ($this->type) {
            SettingType::Int => [RangeValidator::new($this->min, $this->max)],
            SettingType::Float => [RangeValidator::new($this->min, $this->max, integer: false)],
            SettingType::Enum => $this->enumValues === [] ? [] : [EnumValidator::new($this->enumValues)],
            SettingType::StringList => [GlobListValidator::new()],
            SettingType::Url => [UrlValidator::new()],
            SettingType::Path => [AbsolutePathValidator::new()],
            default => [],
        };

        return [...$implied, ...$this->validators];
    }

    /**
     * The first reason `$value` is refused, or null.
     *
     * @param array<string, mixed> $all every candidate value by key, for cross-field checks
     */
    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value !== null) {
            $shape = match ($this->type) {
                SettingType::Bool => \is_bool($value),
                SettingType::String, SettingType::Secret => \is_string($value),
                SettingType::Map => \is_array($value) && ($value === [] || !array_is_list($value)),
                default => true,
            };
            if (!$shape) {
                return 'must be a ' . $this->type->label();
            }
        }

        foreach ($this->effectiveValidators() as $validator) {
            $reason = $validator->validate($value, $all);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function mutate(mixed ...$changes): self
    {
        $args = get_object_vars($this);
        foreach ($changes as $name => $value) {
            $args[$name] = $value;
        }

        return new self(...$args);
    }
}
