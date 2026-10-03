<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Config\LayeredSettings;

/**
 * Answers "what am I running with, and why" for every {@see SettingsSchema} key:
 * the effective value, the source that won and the sources it shadowed.
 *
 * READ-ONLY AND ADVISORY. The launch does not resolve its settings through this
 * class — `Bootstrap::readUserConfig()` and the strict permission reader remain
 * the authorities, and this class is what a settings view or a diagnostic
 * consults to EXPLAIN them. It therefore reuses their rules instead of
 * restating them: the file reads and the project trust gate go through
 * {@see LayeredSettings}, and which sources may supply which key is
 * {@see SettingDefinition::sources()} — the same tier filter the merge applies.
 *
 * Precedence is {@see SettingSource}'s case order. A layer is handed in whole
 * (an unfiltered file) and filtered per key here, so a project file that names
 * `provider` is shown as ignored rather than winning.
 */
final class SettingsResolver
{
    /**
     * @param array<string, array{values: array<string, mixed>, path: ?string}> $layers by {@see SettingSource} value
     * @param array<string, string> $env
     * @param array<string, mixed> $flags by flag spelling, e.g. `--permission-mode`
     */
    private function __construct(
        private readonly array $layers,
        private readonly array $env,
        private readonly array $flags,
    ) {
    }

    public static function new(): self
    {
        return new self([], [], []);
    }

    /**
     * The four settings files as the launch would read them.
     *
     * `$projectTrusted` is the caller's answer to the trust question, exactly
     * as {@see LayeredSettings::projectLayer()} requires: an untrusted project
     * contributes nothing, and this class must not grow a second copy of that
     * check.
     */
    public static function fromFiles(
        ?string $projectRoot,
        bool $projectTrusted,
        ?string $userSettingsDir,
        ?string $userConfigPath,
    ): self {
        $resolver = self::new();
        if ($projectRoot !== null) {
            foreach (LayeredSettings::projectFiles($projectRoot, $projectTrusted) as $path => $values) {
                $source = str_ends_with($path, LayeredSettings::LOCAL_PATH)
                    ? SettingSource::ProjectLocal
                    : SettingSource::ProjectShared;
                $resolver = $resolver->withLayer($source, $values, $path);
            }
        }

        if ($userSettingsDir !== null) {
            $path = rtrim($userSettingsDir, '/') . '/' . LayeredSettings::USER_FILE;
            $resolver = $resolver->withLayer(SettingSource::UserSettings, LayeredSettings::decodedFile($path), $path);
        }

        if ($userConfigPath !== null) {
            $resolver = $resolver->withLayer(SettingSource::UserConfig, LayeredSettings::decodedFile($userConfigPath), $userConfigPath);
        }

        return $resolver;
    }

    /**
     * One source's values. Env and flags have their own setters because their
     * values are keyed by variable or flag, not by settings key.
     *
     * @param array<string, mixed> $values
     */
    public function withLayer(SettingSource $source, array $values, ?string $path = null): self
    {
        if ($source === SettingSource::Env || $source === SettingSource::Flag || $source === SettingSource::Default) {
            throw new \InvalidArgumentException("{$source->value} is not a file-shaped layer");
        }

        $layers = $this->layers;
        $layers[$source->value] = ['values' => $values, 'path' => $path];

        return new self($layers, $this->env, $this->flags);
    }

    /**
     * The process environment (or a fixture of it). An empty value is absence,
     * as every `SUGARCRUSH_*` reader treats it.
     *
     * @param array<string, string> $env
     */
    public function withEnvironment(array $env): self
    {
        return new self($this->layers, $env, $this->flags);
    }

    /** @param array<string, mixed> $flags parsed flag values, by spelling */
    public function withFlags(array $flags): self
    {
        return new self($this->layers, $this->env, $flags);
    }

    public function resolve(SettingDefinition $definition): ResolvedSetting
    {
        $allowed = $definition->sources();
        $candidates = [];
        foreach (array_reverse(SettingSource::cases()) as $source) {
            if (!\in_array($source, $allowed, true)) {
                continue;
            }

            $hit = $this->lookup($definition, $source);
            if ($hit !== null) {
                $candidates[] = [$source, ...$hit];
            }
        }

        if ($candidates === []) {
            return ResolvedSetting::new($definition->key, $definition->default, SettingSource::Default);
        }

        [$source, $value, $path] = array_shift($candidates);
        $lock = match ($source) {
            SettingSource::Env => 'set by ' . $definition->envVar,
            SettingSource::Flag => 'set by ' . $definition->cliFlag,
            default => null,
        };

        return ResolvedSetting::new(
            $definition->key,
            $value,
            $source,
            $path,
            array_map(static fn (array $c): SettingSource => $c[0], $candidates),
            $lock,
        );
    }

    /** @return array<string, ResolvedSetting> by key, in schema order */
    public function resolveAll(): array
    {
        $resolved = [];
        foreach (SettingsSchema::all() as $definition) {
            $resolved[$definition->key] = $this->resolve($definition);
        }

        return $resolved;
    }

    /** @return array{0: mixed, 1: ?string}|null value and source path, or null when the source is silent */
    private function lookup(SettingDefinition $definition, SettingSource $source): ?array
    {
        if ($source === SettingSource::Default) {
            return null;
        }

        if ($source === SettingSource::Env) {
            $raw = $this->env[(string) $definition->envVar] ?? '';
            if ($raw === '' || ($raw === '0' && self::isDisableFlag($definition))) {
                return null;
            }

            return [self::envValue($definition, $raw), null];
        }

        if ($source === SettingSource::Flag) {
            return \array_key_exists((string) $definition->cliFlag, $this->flags)
                ? [$this->flags[(string) $definition->cliFlag], null]
                : null;
        }

        $layer = $this->layers[$source->value] ?? null;
        // array_key_exists, not isset: an explicit null in a higher file is a
        // statement that outranks a lower value, as LayeredSettings::merge() has it.
        if ($layer === null || !\array_key_exists($definition->key, $layer['values'])) {
            return null;
        }

        return [$layer['values'][$definition->key], $layer['path']];
    }

    /**
     * The value an environment override means for the key it outranks.
     *
     * Every `SUGARCRUSH_DISABLE_*` flag reads "any value other than empty or
     * `0`" as set (`docs/ENVIRONMENT.md`), and setting it turns its boolean key
     * OFF; a numeric key takes the number; everything else is the string.
     */
    private static function envValue(SettingDefinition $definition, string $raw): mixed
    {
        return match ($definition->type) {
            SettingType::Bool => !self::isDisableFlag($definition),
            SettingType::Int => is_numeric($raw) ? (int) $raw : $raw,
            SettingType::Float => is_numeric($raw) ? (float) $raw : $raw,
            default => $raw,
        };
    }

    /** `SUGARCRUSH_DISABLE_*`: set means the boolean key is off; `0` means unset. */
    private static function isDisableFlag(SettingDefinition $definition): bool
    {
        return $definition->type === SettingType::Bool && str_contains((string) $definition->envVar, '_DISABLE_');
    }
}
