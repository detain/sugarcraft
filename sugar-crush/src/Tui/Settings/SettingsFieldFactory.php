<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsProvider;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

/**
 * A {@see SettingDefinition} and its current value as the candy-forms field that
 * edits it (Appendix N §5.1), and that field's answer back as a settings value.
 *
 * Bool → {@see Confirm}; a key with choices ({@see OptionsProvider::for()}) →
 * {@see Select}; numbers, strings, paths and URLs → {@see Input}; a string list
 * → an {@see Input} of comma-separated entries; `models` → an {@see Input} for
 * the ACTIVE provider's entry, written back into the whole map (decision D9) —
 * and, since N-P5, `<provider>=<model id>` edits ANY provider's entry, so a
 * model can be chosen for a provider before switching to it. A complex MAP key
 * (`modelPrices`, `lintCommands`, `statusLine`, …; N-P5) → an {@see Input}
 * holding the whole map as one JSON object, parsed back and refused unless it
 * is one. Read-only and hidden keys, `permissionRules` (whose strict parser is
 * the launch's own) and the trust grants get no inline field ({@see field()}
 * answers null): those are edited by hand or through their own confirmed
 * action.
 *
 * Validation stays the writer's ({@see \SugarCraft\Crush\Config\Settings\SettingsWriter::refusal()})
 * so a value is judged by one set of rules wherever it comes from; this class
 * only parses text into the type the key holds and refuses text that is not
 * one ({@see value()}).
 */
final class SettingsFieldFactory
{
    /** Keys edited one entry at a time, keyed by the active provider. */
    private const PER_PROVIDER_KEYS = ['models'];

    /** `<provider>=<model id>`: an entry for a provider other than the active one. */
    private const PROVIDER_ENTRY = '/^([A-Za-z0-9][A-Za-z0-9_.-]*)\s*=\s*(.*)$/s';

    private function __construct()
    {
    }

    /**
     * The field that edits `$definition`, pre-filled with `$current`, or null
     * when the key is not edited inline.
     */
    public static function field(
        SettingDefinition $definition,
        mixed $current,
        OptionsProvider $options,
        ?string $provider = null,
    ): ?Field {
        if (!self::editable($definition)) {
            return null;
        }

        $title = $definition->label;
        $choices = $options->for($definition);

        if ($definition->type === SettingType::Bool) {
            return Confirm::new($definition->key, (bool) $current)
                ->withTitle($title)
                ->withDescription($definition->help);
        }

        if ($choices !== []) {
            $select = Select::new($definition->key)
                ->withOptions(...$choices)
                ->withTitle($title)
                ->withDescription($definition->help);

            return \is_string($current) && \in_array($current, $choices, true) ? $select->withSelected($current) : $select;
        }

        if (self::perProvider($definition)) {
            if ($provider === null || $provider === '') {
                return Input::new($definition->key)
                    ->withTitle($title)
                    ->withDescription('<provider>=<model id> sets that provider\'s model; <provider>= clears it.');
            }

            $entry = \is_array($current) ? ($current[$provider] ?? '') : '';

            return Input::new($definition->key)
                ->withTitle($title . ' for ' . $provider)
                ->withDescription('Model id; leave empty for the provider default. <provider>=<model id> sets another provider\'s.')
                ->withValue(\is_string($entry) ? $entry : '');
        }

        if (self::jsonMap($definition)) {
            return Input::new($definition->key)
                ->withTitle($title)
                ->withDescription('One JSON object. ' . $definition->help)
                ->withValue(\is_array($current) && $current !== [] ? self::text($definition, $current) : '{}');
        }

        $input = Input::new($definition->key)
            ->withTitle($title)
            ->withDescription($definition->type === SettingType::StringList ? 'Comma-separated.' : $definition->help)
            ->withValue(self::text($definition, $current));

        return $definition->type === SettingType::Secret ? $input->withPassword() : $input;
    }

    /** Whether the key gets an inline field at all (the provider is checked separately for `models`). */
    public static function editable(SettingDefinition $definition): bool
    {
        return (\in_array($definition->ui, [UiEditability::Easy, UiEditability::List], true) || self::jsonMap($definition))
            && $definition->applyMode !== ApplyMode::Frozen;
    }

    /**
     * Why the view offers no field for `$definition`, as the status line says
     * it — or null when it does. Names where the value IS changed instead.
     */
    public static function whyNotEditable(SettingDefinition $definition): ?string
    {
        if (self::editable($definition)) {
            return null;
        }

        return match (true) {
            $definition->applyMode === ApplyMode::Frozen
                => $definition->label . ' is set by hand in your config.json; it applies from the next launch',
            $definition->ui === UiEditability::ReadOnly => $definition->label . ' is written by the app itself, not edited here',
            $definition->key === 'permissionRules' => $definition->label . ' is edited by hand in your settings.json or config.json (/permissions lists them)',
            default => $definition->label . ' is edited by hand in your config.json',
        };
    }

    /** A nested map with no row editor of its own: edited as one JSON object. */
    private static function jsonMap(SettingDefinition $definition): bool
    {
        return $definition->ui === UiEditability::Complex && $definition->type === SettingType::Map;
    }

    /**
     * The settings value `$field`'s answer stands for.
     *
     * @throws \InvalidArgumentException when the text is not a value of the key's type
     */
    public static function value(SettingDefinition $definition, Field $field, mixed $current = null, ?string $provider = null): mixed
    {
        $raw = $field->value();
        if (\is_bool($raw)) {
            return $raw;
        }

        $text = trim((string) $raw);

        if (self::perProvider($definition)) {
            $target = $provider;
            if (preg_match(self::PROVIDER_ENTRY, $text, $m) === 1) {
                [, $target, $text] = $m;
                $text = trim($text);
            }

            if ($target === null || $target === '') {
                throw new \InvalidArgumentException('name the provider: <provider>=<model id>');
            }

            $map = \is_array($current) ? $current : [];
            if ($text === '') {
                unset($map[$target]);
            } else {
                $map[$target] = $text;
            }

            return $map;
        }

        if (self::jsonMap($definition)) {
            $decoded = json_decode($text === '' ? '{}' : $text, true);
            if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                throw new \InvalidArgumentException("{$definition->label} needs one JSON object, e.g. {\"name\": …}");
            }

            // `{}` would be written as a JSON list (`[]`) and states nothing a
            // reset does not state better: the reset removes the key.
            if ($decoded === []) {
                throw new \InvalidArgumentException("{$definition->label} is empty; press r to reset it instead");
            }

            return $decoded;
        }

        return match ($definition->type) {
            SettingType::Int => preg_match('/^-?\d+$/', $text) === 1
                ? (int) $text
                : throw new \InvalidArgumentException("{$definition->label} needs a whole number"),
            SettingType::Float => is_numeric($text)
                ? (float) $text
                : throw new \InvalidArgumentException("{$definition->label} needs a number"),
            SettingType::StringList => array_values(array_filter(
                array_map('trim', explode(',', $text)),
                static fn (string $entry): bool => $entry !== '',
            )),
            SettingType::Json => self::json($definition, $text),
            default => $text,
        };
    }

    private static function perProvider(SettingDefinition $definition): bool
    {
        return \in_array($definition->key, self::PER_PROVIDER_KEYS, true);
    }

    private static function text(SettingDefinition $definition, mixed $current): string
    {
        return match (true) {
            $current === null => '',
            \is_array($current) && array_is_list($current) && $definition->type === SettingType::StringList
                => implode(', ', array_map('strval', $current)),
            \is_array($current) => (string) json_encode($current, \JSON_UNESCAPED_SLASHES),
            \is_bool($current) => $current ? 'true' : 'false',
            default => (string) $current,
        };
    }

    private static function json(SettingDefinition $definition, string $text): mixed
    {
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }

        $decoded = json_decode($text, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException("{$definition->label} needs a number or a JSON object");
        }

        return $decoded;
    }
}
