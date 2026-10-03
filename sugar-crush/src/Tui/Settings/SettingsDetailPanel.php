<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Tui\Components\PaneLabel;

/**
 * The settings view's detail column: everything the schema and the resolver
 * know about the highlighted key, as plain label/value lines.
 *
 * PROVENANCE IS THE POINT. A value alone does not answer "why am I running with
 * this"; the source that won, the file it came from, the sources it shadowed
 * and whether an env var or a flag locks it do. All of it is read off the
 * {@see ResolvedSetting} the resolver built — nothing is re-derived here.
 *
 * Values are made safe for a cell ({@see PaneLabel::of()}) and a secret-shaped
 * value is never printed: a {@see SettingType::Secret} key, and any map of
 * environment variables, show only how many entries they hold.
 */
final class SettingsDetailPanel
{
    /** Cells the label column takes; values start one cell after it. */
    public const LABEL_WIDTH = 8;

    /** The label of a line that continues the value above it (a path). */
    public const CONTINUED = '+';

    private function __construct()
    {
    }

    /**
     * The panel's lines for a key, each at most `$width` cells.
     *
     * @return list<array{0: string, 1: string}> [label, text]; an empty label is
     *         full-width prose, {@see CONTINUED} continues the value above
     */
    public static function lines(SettingDefinition $definition, ResolvedSetting $resolved, int $width): array
    {
        $out = [['', $definition->label], ['key', $definition->key]];

        $help = Width::wrap(PaneLabel::of($definition->help), max(8, $width));
        foreach (explode("\n", $help) as $line) {
            $out[] = ['', $line];
        }

        $out[] = ['', ''];
        $out[] = ['value', self::value($definition, $resolved->value)];
        $out[] = ['default', $definition->defaultText !== null
            ? PaneLabel::of(str_replace('`', '', $definition->defaultText))
            : self::value($definition, $definition->default)];
        $out[] = ['source', self::sourceLabel($resolved->source)];
        if ($resolved->sourcePath !== null) {
            $out[] = [self::CONTINUED, PaneLabel::of($resolved->sourcePath)];
        }

        if ($resolved->shadowed !== []) {
            $out[] = ['shadows', implode(', ', array_map(
                static fn (SettingSource $s): string => self::sourceLabel($s),
                $resolved->shadowed,
            ))];
        }

        if ($resolved->locked) {
            $out[] = ['locked', PaneLabel::of((string) $resolved->lockReason) . ' — unset it to change this here'];
        }

        $out[] = ['applies', $definition->applyMode->badge()];
        $out[] = ['set in', self::tiers($definition)];
        if ($definition->envVar !== null) {
            $out[] = ['env', $definition->envVar];
        }

        if ($definition->cliFlag !== null) {
            $out[] = ['flag', $definition->cliFlag];
        }

        $out[] = ['risk', $definition->riskClass->value];
        $reader = $definition->readerShort() ?? $definition->readBy;
        if ($reader !== null) {
            $out[] = ['read by', PaneLabel::of($reader)];
        }

        return $out;
    }

    /** The lines for a row of the Files tab. @return list<array{0: string, 1: string}> */
    public static function fileLines(SettingsFile $file, int $width): array
    {
        $out = [['', $file->role], ['path', PaneLabel::of($file->path)], ['status', $file->status], ['', '']];
        foreach (explode("\n", Width::wrap($file->note, max(8, $width))) as $line) {
            $out[] = ['', $line];
        }

        return $out;
    }

    /**
     * A value as one cell of text: `on`/`off`, `(unset)`, a list joined, a map
     * as compact JSON — and never a secret.
     */
    public static function value(SettingDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return '(unset)';
        }

        if ($definition->type === SettingType::Secret) {
            return '(set, hidden)';
        }

        if (\is_array($value) && self::holdsEnvironment($definition)) {
            return sprintf('(%d %s, hidden)', \count($value), \count($value) === 1 ? 'variable' : 'variables');
        }

        return PaneLabel::of(match (true) {
            \is_bool($value) => $value ? 'on' : 'off',
            \is_array($value) && array_is_list($value) && self::allScalar($value) => $value === []
                ? '(empty)'
                : implode(', ', array_map(static fn ($v): string => \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $value)),
            \is_array($value) => (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            \is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        });
    }

    /**
     * The source as the view names it: who set the value, not which class read
     * it. "you" is the person at the terminal — their own two files.
     */
    public static function sourceLabel(SettingSource $source): string
    {
        return match ($source) {
            SettingSource::Default => 'default',
            SettingSource::ProjectShared => 'project',
            SettingSource::ProjectLocal => 'project (local)',
            SettingSource::UserSettings => 'you (settings.json)',
            SettingSource::UserConfig => 'you (config.json)',
            SettingSource::Session => 'this session',
            SettingSource::Env => 'environment',
            SettingSource::Flag => 'command-line flag',
        };
    }

    /** The source in the few cells the list's source column has. */
    public static function sourceShort(SettingSource $source): string
    {
        return match ($source) {
            SettingSource::UserSettings => 'you (settings)',
            SettingSource::UserConfig => 'you (config)',
            SettingSource::Env => 'env',
            SettingSource::Flag => 'flag',
            default => self::sourceLabel($source),
        };
    }

    /** The one-glyph provenance mark the list column leads with. */
    public static function sourceGlyph(SettingSource $source): string
    {
        return match ($source) {
            SettingSource::Default => '○',
            SettingSource::ProjectShared, SettingSource::ProjectLocal => '◆',
            SettingSource::UserSettings, SettingSource::UserConfig => '●',
            SettingSource::Session => '◐',
            SettingSource::Env, SettingSource::Flag => '■',
        };
    }

    /** Which files may set the key, from {@see SettingDefinition::sources()}. */
    public static function tiers(SettingDefinition $definition): string
    {
        $names = [];
        foreach ($definition->sources() as $source) {
            $name = match ($source) {
                SettingSource::ProjectShared => 'project files',
                SettingSource::UserSettings => 'settings.json',
                SettingSource::UserConfig => 'config.json',
                default => null,
            };
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    private static function holdsEnvironment(SettingDefinition $definition): bool
    {
        return str_ends_with(strtolower($definition->key), 'env');
    }

    /** @param list<mixed> $values */
    private static function allScalar(array $values): bool
    {
        foreach ($values as $v) {
            if (!\is_scalar($v)) {
                return false;
            }
        }

        return true;
    }
}
