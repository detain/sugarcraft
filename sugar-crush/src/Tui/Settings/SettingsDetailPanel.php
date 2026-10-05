<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Lang;
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
        // Provenance first, the prose after it (N-P5): a short terminal shows
        // only the panel's top rows, and "what is it, where did it come from,
        // what locks it" is the answer the panel exists for.
        $out = [['', $definition->label], [Lang::t('tui.settings.detail.key'), $definition->key]];
        $out[] = [Lang::t('tui.settings.detail.value'), self::value($definition, $resolved->value)];
        $out[] = [Lang::t('tui.settings.detail.source'), self::sourceLabel($resolved->source)];
        if ($resolved->sourcePath !== null) {
            $out[] = [self::CONTINUED, PaneLabel::of($resolved->sourcePath)];
        }

        if ($resolved->shadowed !== []) {
            $out[] = [Lang::t('tui.settings.detail.shadows'), implode(', ', array_map(
                static fn (SettingSource $s): string => self::sourceLabel($s),
                $resolved->shadowed,
            ))];
        }

        if ($resolved->locked) {
            $out[] = [Lang::t('tui.settings.detail.locked'), Lang::t('tui.settings.detail.locked_reason', ['reason' => PaneLabel::of((string) $resolved->lockReason)])];
        }

        $out[] = ['', ''];
        $help = Width::wrap(PaneLabel::of($definition->help), max(8, $width));
        foreach (explode("\n", $help) as $line) {
            $out[] = ['', $line];
        }

        $out[] = ['', ''];
        $out[] = [Lang::t('tui.settings.detail.default'), $definition->defaultText !== null
            ? PaneLabel::of(str_replace('`', '', $definition->defaultText))
            : self::value($definition, $definition->default)];
        $out[] = [Lang::t('tui.settings.detail.applies'), $definition->applyMode->badge()];
        $out[] = [Lang::t('tui.settings.detail.set_in'), self::tiers($definition)];
        if ($definition->envVar !== null) {
            $out[] = [Lang::t('tui.settings.detail.env'), $definition->envVar];
        }

        if ($definition->cliFlag !== null) {
            $out[] = [Lang::t('tui.settings.detail.flag'), $definition->cliFlag];
        }

        $out[] = [Lang::t('tui.settings.detail.risk'), $definition->riskClass->value];
        $reader = $definition->readerShort() ?? $definition->readBy;
        if ($reader !== null) {
            $out[] = [Lang::t('tui.settings.detail.read_by'), PaneLabel::of($reader)];
        }

        return $out;
    }

    /** The lines for a row of the Files tab. @return list<array{0: string, 1: string}> */
    public static function fileLines(SettingsFile $file, int $width): array
    {
        $out = [
            ['', $file->role],
            [Lang::t('tui.settings.detail.path'), PaneLabel::of($file->path)],
            [Lang::t('tui.settings.detail.status'), self::fileStatus($file->status)],
            ['', ''],
        ];
        foreach (explode("\n", Width::wrap($file->note, max(8, $width))) as $line) {
            $out[] = ['', $line];
        }

        return $out;
    }

    /**
     * The on-screen word for a {@see SettingsFile::$status}. The status itself
     * stays the English word the protocol carries and the view keys its
     * colour on; only what the row says is translated.
     */
    public static function fileStatus(string $status): string
    {
        return match ($status) {
            'read' => Lang::t('tui.settings.file_status.read'),
            'absent' => Lang::t('tui.settings.file_status.absent'),
            'ignored' => Lang::t('tui.settings.file_status.ignored'),
            'not shown' => Lang::t('tui.settings.file_status.not_shown'),
            'not a layer' => Lang::t('tui.settings.file_status.not_a_layer'),
            default => $status,
        };
    }

    /**
     * A value as one cell of text: `on`/`off`, `(unset)`, a list joined, a map
     * as compact JSON — and never a secret.
     */
    public static function value(SettingDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return Lang::t('tui.settings.value.unset');
        }

        if ($definition->type === SettingType::Secret) {
            return Lang::t('tui.settings.value.secret');
        }

        if (\is_array($value) && self::holdsEnvironment($definition)) {
            return Lang::t(\count($value) === 1 ? 'tui.settings.value.env_one' : 'tui.settings.value.env', ['count' => \count($value)]);
        }

        return PaneLabel::of(match (true) {
            \is_bool($value) => Lang::t($value ? 'tui.settings.value.on' : 'tui.settings.value.off'),
            \is_array($value) && array_is_list($value) && self::allScalar($value) => $value === []
                ? Lang::t('tui.settings.value.empty')
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
            SettingSource::Default => Lang::t('tui.settings.source.default'),
            SettingSource::ProjectShared => Lang::t('tui.settings.source.project'),
            SettingSource::ProjectLocal => Lang::t('tui.settings.source.project_local'),
            SettingSource::UserSettings => Lang::t('tui.settings.source.user_settings'),
            SettingSource::UserConfig => Lang::t('tui.settings.source.user_config'),
            SettingSource::Session => Lang::t('tui.settings.source.session'),
            SettingSource::Env => Lang::t('tui.settings.source.env'),
            SettingSource::Flag => Lang::t('tui.settings.source.flag'),
        };
    }

    /** The source in the few cells the list's source column has. */
    public static function sourceShort(SettingSource $source): string
    {
        return match ($source) {
            SettingSource::UserSettings => Lang::t('tui.settings.source.user_settings_short'),
            SettingSource::UserConfig => Lang::t('tui.settings.source.user_config_short'),
            SettingSource::Env => Lang::t('tui.settings.source.env_short'),
            SettingSource::Flag => Lang::t('tui.settings.source.flag_short'),
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
                SettingSource::ProjectShared => Lang::t('tui.settings.tier.project_files'),
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
