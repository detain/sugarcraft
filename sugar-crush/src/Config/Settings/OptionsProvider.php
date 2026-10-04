<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Theme;

/**
 * The live choices behind an {@see OptionsSource} — theme names, permission
 * modes, the providers this install can build — for the settings editor's
 * pick-one fields (Appendix N §5.1).
 *
 * The two lists the package itself defines are built in; the rest are what
 * THIS launch knows (the providers `config.dev.json` declares, say), so the
 * launch supplies them with {@see withList()}. A source nobody supplied answers
 * `[]`, and the field factory then falls back to free text rather than offering
 * a list it made up.
 */
final class OptionsProvider
{
    /** @param array<string, list<string>> $lists by {@see OptionsSource} value */
    private function __construct(private readonly array $lists)
    {
    }

    public static function new(): self
    {
        return new self([
            OptionsSource::Themes->value => Theme::names(),
            OptionsSource::PermissionModes->value => array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases()),
        ]);
    }

    /** @param list<string> $values */
    public function withList(OptionsSource $source, array $values): self
    {
        $lists = $this->lists;
        $lists[$source->value] = array_values(array_unique(array_map('strval', $values)));

        return new self($lists);
    }

    /** @return list<string> */
    public function options(OptionsSource $source): array
    {
        return $this->lists[$source->value] ?? [];
    }

    /**
     * The choices a definition offers: its own enum values when it declares
     * them, else its options source's list, else none (free text).
     *
     * @return list<string>
     */
    public function for(SettingDefinition $definition): array
    {
        if ($definition->enumValues !== []) {
            return array_values(array_map('strval', $definition->enumValues));
        }

        return $definition->optionsSource === null ? [] : $this->options($definition->optionsSource);
    }
}
