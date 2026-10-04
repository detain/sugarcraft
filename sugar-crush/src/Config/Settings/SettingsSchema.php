<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Every settings key sugar-crush reads from a settings file, described once.
 *
 * ONE SCHEMA, MANY CONSUMERS: the settings editor's form, the generated key
 * table in `docs/SETTINGS.md`, the "Settings key" column of
 * `docs/ENVIRONMENT.md`, and the tier rules. The tier constants in
 * {@see \SugarCraft\Crush\Config\LayeredSettings} are DERIVED from here
 * (DH-KEYS): `tools/gen-settings-doc.php --write` writes
 * {@see layeredKeys()} and {@see projectTierKeys()} into them, and
 * {@see \SugarCraft\Crush\Tests\Config\Settings\SettingsSchemaDocDriftTest}
 * reds until it has been re-run — so a key is added in exactly one place, its
 * category's file under `Definitions/`.
 *
 * WHAT IS IN IT: every key that some reader in `src/` actually consults — the
 * layered keys, the two strict permission keys, the four trust lists, the
 * three `claudeMcp*` grant keys and `enabledSkills`. Nothing speculative: a key
 * joins when its reader does, for the reason `LayeredSettings::LAYERED_KEYS`
 * gives — a key nothing reads is worse than a missing one, because it looks
 * configurable. Package provider definitions (`config.dev.json`) are not
 * settings keys and are not here.
 */
final class SettingsSchema
{
    /**
     * One definitions file per category, under `Definitions/`. Listed rather
     * than discovered by a directory scan: a `glob()` here would be a new
     * read sink in `src/` for no benefit, and `SettingsSchemaTest` already
     * reds when a file in that directory is missing from this list.
     *
     * @var list<class-string<SettingDefinitionSet>>
     */
    public const DEFINITION_SETS = [
        Definitions\ModelProviderSettings::class,
        Definitions\AgentLoopSettings::class,
        Definitions\ContextSettings::class,
        Definitions\PermissionSettings::class,
        Definitions\ToolSettings::class,
        Definitions\MemoryRuleSettings::class,
        Definitions\SkillSettings::class,
        Definitions\GitSettings::class,
        Definitions\InterfaceSettings::class,
        Definitions\HooksMcpSettings::class,
        Definitions\ServerSettings::class,
    ];

    /** @var list<SettingDefinition>|null */
    private static ?array $all = null;

    private function __construct()
    {
    }

    /**
     * Every definition, in category order and then declaration order.
     *
     * @return list<SettingDefinition>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $definitions = self::definitions();
        $seen = [];
        foreach ($definitions as $definition) {
            if (isset($seen[$definition->key])) {
                throw new \LogicException("settings key '{$definition->key}' is defined twice");
            }

            $seen[$definition->key] = true;
        }

        usort(
            $definitions,
            static fn (SettingDefinition $a, SettingDefinition $b): int => $a->category->order() <=> $b->category->order(),
        );

        return self::$all = $definitions;
    }

    public static function byKey(string $key): ?SettingDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (SettingDefinition $d): string => $d->key, self::all());
    }

    /** @return list<SettingDefinition> */
    public static function inCategory(SettingCategory $category): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (SettingDefinition $d): bool => $d->category === $category,
        ));
    }

    /**
     * The keys `LayeredSettings` merges from the lower layers.
     *
     * @return list<string>
     */
    public static function layeredKeys(): array
    {
        return self::keysWhere(static fn (SettingDefinition $d): bool => $d->layered);
    }

    /**
     * The layered keys a trusted project may contribute.
     *
     * @return list<string>
     */
    public static function projectTierKeys(): array
    {
        return self::keysWhere(static fn (SettingDefinition $d): bool => $d->layered && $d->projectSettable);
    }

    /**
     * Environment variable → the settings key it outranks.
     *
     * @return array<string, string>
     */
    public static function envMap(): array
    {
        $map = [];
        foreach (self::all() as $definition) {
            if ($definition->envVar !== null) {
                $map[$definition->envVar] = $definition->key;
            }
        }

        ksort($map);

        return $map;
    }

    /** @return list<string> */
    private static function keysWhere(\Closure $predicate): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d): string => $d->key,
            array_filter(self::all(), $predicate),
        ));
    }

    /** @return list<SettingDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (self::DEFINITION_SETS as $set) {
            foreach ($set::definitions() as $definition) {
                if ($definition->category !== $set::category()) {
                    throw new \LogicException("{$definition->key} is defined in {$set} but categorised {$definition->category->value}");
                }

                $definitions[] = $definition;
            }
        }

        return $definitions;
    }
}
