<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Protocol\Methods\SettingsMethods;

/**
 * A settings PROFILE (roadmap N-P5, Appendix N §5.4 "import/export of a
 * settings profile"): the values you have configured, as one flat JSON object
 * — the same shape as a settings file, so a profile can also be dropped in as
 * one — written and read from the settings view (`x` exports, `p` imports).
 *
 * EXPORT ({@see values()}) takes what this launch resolved and keeps a key
 * only when a FILE or this session set it: a default says nothing about you,
 * and a value an environment variable or a flag supplied belongs to that
 * shell, not to the profile. It never carries a secret (a key the wire
 * protocol masks, {@see SettingsMethods::sensitive()}), a trust grant (those
 * are per-machine and change only through the confirmed action), or a key the
 * app writes itself.
 *
 * IMPORT ({@see staged()}) STAGES, it does not write: each key the chosen tier
 * may take is put in the view's edit set, so the save preview shows the diff
 * and the writer judges every value before anything lands — the same door, and
 * the same refusals, as a value typed by hand. Keys the tier never takes, keys
 * that are not settings and values already in force are reported, not staged.
 *
 * Reading and writing the file are the shell's Cmds
 * ({@see \SugarCraft\Crush\App\App}); {@see write()} and {@see read()} are the
 * I/O those Cmds run.
 */
final class SettingsProfile
{
    /** Where the prompt suggests a profile goes: beside your `config.json`. */
    public const DEFAULT_DIR = 'profiles';
    public const DEFAULT_FILE = 'settings-profile.json';

    /** File names a profile is never written as: the settings layers' own. */
    public const LAYER_FILE_NAMES = ['config.json', 'settings.json', 'settings.local.json'];

    /** The layers whose values a profile carries — your files, a trusted project's and this session. */
    private const EXPORTED_SOURCES = [
        SettingSource::ProjectShared,
        SettingSource::ProjectLocal,
        SettingSource::UserSettings,
        SettingSource::UserConfig,
        SettingSource::Session,
    ];

    private function __construct()
    {
    }

    /** The profile path the prompt opens on, next to the file `$userConfigPath` names. */
    public static function defaultPath(?string $userConfigPath): string
    {
        $dir = $userConfigPath === null || $userConfigPath === '' ? sys_get_temp_dir() : \dirname($userConfigPath);

        return rtrim($dir, '/') . '/' . self::DEFAULT_DIR . '/' . self::DEFAULT_FILE;
    }

    /**
     * What an export writes: every exportable key a file or this session set,
     * in schema order.
     *
     * @param array<string, ResolvedSetting> $resolved
     * @return array<string, mixed>
     */
    public static function values(array $resolved): array
    {
        $values = [];
        foreach (SettingsSchema::all() as $definition) {
            $setting = $resolved[$definition->key] ?? null;
            if ($setting === null || !self::exportable($definition)) {
                continue;
            }

            if (\in_array($setting->source, self::EXPORTED_SOURCES, true) && $setting->value !== null) {
                $values[$definition->key] = $setting->value;
            }
        }

        return $values;
    }

    /** Whether a profile may carry `$definition` at all. */
    public static function exportable(SettingDefinition $definition): bool
    {
        return $definition->ui !== UiEditability::Hidden
            && $definition->ui !== UiEditability::ReadOnly
            && $definition->applyMode !== ApplyMode::Frozen
            && !SettingsMethods::sensitive($definition);
    }

    /**
     * What an import stages on `$tier`, and why the rest is not staged.
     *
     * @param array<string, mixed> $profile the file's object
     * @param array<string, ResolvedSetting> $resolved what this launch runs with
     * @return array{set: array<string, mixed>, skipped: array<string, string>, same: list<string>}
     */
    public static function staged(array $profile, array $resolved, SettingsTier $tier): array
    {
        $set = [];
        $skipped = [];
        $same = [];
        foreach ($profile as $key => $value) {
            $key = (string) $key;
            $definition = SettingsSchema::byKey($key);
            $refusal = $definition === null ? Lang::t('tui.settings.profile.not_a_setting', ['key' => $key]) : SettingsWriter::keyRefusal($tier, $key);
            if ($refusal === null && $definition !== null && !self::exportable($definition)) {
                $refusal = Lang::t('tui.settings.profile.not_carried', ['key' => $key]);
            }

            if ($refusal !== null) {
                $skipped[$key] = $refusal;
                continue;
            }

            if (($resolved[$key] ?? null)?->value === $value) {
                $same[] = $key;
                continue;
            }

            $set[$key] = $value;
        }

        return ['set' => $set, 'skipped' => $skipped, 'same' => $same];
    }

    /**
     * Write `$values` to `$path` (owner-only), creating its directory. An
     * empty set is refused (it would be written as a JSON list, not a
     * profile), and so is a path named like a settings file
     * ({@see LAYER_FILE_NAMES}).
     *
     * @param array<string, mixed> $values
     *
     * @throws \RuntimeException when it cannot be written
     */
    public static function write(string $path, array $values): void
    {
        if ($values === []) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.nothing_to_export'));
        }

        // A profile is never a settings LAYER: those have their own writers
        // (config.json's lock and symlink rule, the writer's project gate),
        // so an export refuses any file named like one rather than replace it
        // behind their backs.
        if (\in_array(basename($path), self::LAYER_FILE_NAMES, true)) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.layer_name', ['name' => basename($path)]));
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.dir_not_created', ['dir' => $dir]));
        }

        if (is_link($path)) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.is_link', ['path' => $path]));
        }

        try {
            AtomicJsonFile::new($path)->withPermissions(0o600)->write($values);
        } catch (\Throwable $e) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.not_written', ['path' => $path, 'error' => $e->getMessage()]), 0, $e);
        }
    }

    /**
     * The profile at `$path`, read strictly: it must exist and be one JSON
     * object.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.missing', ['path' => $path]));
        }

        try {
            $data = AtomicJsonFile::new($path)->read();
        } catch (\Throwable $e) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.unreadable', ['path' => $path, 'error' => $e->getMessage()]), 0, $e);
        }

        if ($data !== [] && array_is_list($data)) {
            throw new \RuntimeException(Lang::t('tui.settings.profile.is_list', ['path' => $path]));
        }

        return $data;
    }

    /** `~/…` expanded against `$home`; anything else as typed, trimmed. */
    public static function expand(string $path, ?string $home): string
    {
        $path = trim($path);
        if ($home !== null && $home !== '' && ($path === '~' || str_starts_with($path, '~/'))) {
            return rtrim($home, '/') . substr($path, 1);
        }

        return $path;
    }
}
