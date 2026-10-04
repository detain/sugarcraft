<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\OptionsProvider;
use SugarCraft\Crush\Config\Settings\SettingsResolver;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * What the settings view reads: a {@see SettingsResolver} over the launch's
 * layers plus the file list it explains them with.
 *
 * THE TRUST ANSWER IS THE CALLER'S, never re-derived here. Whether a project's
 * settings files may contribute is decided by the launch
 * (`Cli\Bootstrap::projectSettingsTrusted()`), and a second copy of that check
 * in a display class would be one more place for the two to disagree — so
 * `$projectTrusted` is passed in, and `null` means "this view was not told".
 * Then the project files are NOT read and the file list says so, rather than
 * guessing either way: showing an untrusted project's values as if they applied
 * would be as wrong as hiding a trusted project's.
 */
final class SettingsSources
{
    /**
     * The defaults THIS launch runs with where they differ from the schema's:
     * the settings view only ever explains the TUI, and the TUI's permission
     * gate starts in `default` (decision D5; `Bootstrap`'s
     * `INTERACTIVE_DEFAULT_PERMISSION_MODE`) while the schema default is the
     * `-p` / daemon one. Without this the view said `bypass-permissions` for a
     * session that was asking before every write.
     * `SettingsSourcesLaunchDefaultsTest` pins it to the Bootstrap constant.
     */
    public const TUI_DEFAULTS = ['permissionMode' => PermissionMode::Default->value];

    /** @param list<SettingsFile> $files */
    private function __construct(
        public readonly SettingsResolver $resolver,
        public readonly array $files,
        public readonly OptionsProvider $options,
    ) {
    }

    /**
     * The launch's layers.
     *
     * @param ?bool $projectTrusted the launch's answer for `$root`; null when unknown
     * @param array<string, string> $env the process environment
     * @param array<string, mixed> $flags parsed flag values, by spelling
     * @param OptionsProvider|null $options the launch's pick-one lists (providers…); null = the built-in ones
     */
    public static function fromLaunch(
        ?string $root,
        ?bool $projectTrusted,
        ?string $userSettingsDir,
        ?string $userConfigPath,
        array $env,
        array $flags = [],
        ?OptionsProvider $options = null,
    ): self {
        $resolver = SettingsResolver::fromFiles(
            $projectTrusted === null ? null : $root,
            $projectTrusted ?? false,
            $userSettingsDir,
            $userConfigPath,
        )->withEnvironment($env)->withFlags($flags)->withDefaults(self::TUI_DEFAULTS);

        $files = [];
        if ($userConfigPath !== null) {
            $files[] = new SettingsFile(
                'your config',
                $userConfigPath,
                is_file($userConfigPath) ? 'read' : 'absent',
                'Layer 4: outranks every settings file. /theme, /model and the pane layout write here.',
            );
        }

        if ($userSettingsDir !== null) {
            $path = rtrim($userSettingsDir, '/') . '/' . LayeredSettings::USER_FILE;
            $files[] = new SettingsFile(
                'your settings',
                $path,
                is_file($path) ? 'read' : 'absent',
                'Layer 5: the layered keys and the two permission keys. Never written by the app.',
            );
        }

        if ($root !== null && $root !== '') {
            foreach ([
                ['project local', LayeredSettings::LOCAL_PATH, 'Layer 6'],
                ['project shared', LayeredSettings::SHARED_PATH, 'Layer 7'],
            ] as [$role, $relative, $layer]) {
                $path = rtrim($root, '/') . '/' . $relative;
                $present = is_file($path);
                [$status, $note] = match (true) {
                    !$present => ['absent', "{$layer}: only the project-settable keys are read from it."],
                    $projectTrusted === null => [
                        'not shown',
                        "{$layer}: this view was not told whether the project is trusted, so values from it are not shown.",
                    ],
                    $projectTrusted => ['read', "{$layer}: trusted project; only the project-settable keys apply."],
                    default => [
                        'ignored',
                        "{$layer}: ignored until the project root is listed under "
                            . LayeredSettings::PROJECT_SETTINGS_TRUST_KEY . ' in your config.',
                    ],
                };
                $files[] = new SettingsFile($role, $path, $status, $note);
            }

            // N-DOC-3: the repo's own `config.json` beside those files is read
            // only by the dormant Agents\WorktreeConfig — nothing in the merge
            // consults it — so it is listed as what it is, not as a layer.
            $worktree = rtrim($root, '/') . '/' . LayeredSettings::dir() . '/config.json';
            if (is_file($worktree)) {
                $files[] = new SettingsFile(
                    'project config',
                    $worktree,
                    'not a layer',
                    'Not a settings layer: only the worktree configuration reads it, and nothing applies '
                        . 'its keys (including trustedProjectMcp) to this session.',
                );
            }
        }

        return new self($resolver, $files, $options ?? OptionsProvider::new());
    }

    /**
     * The best this process can say without the launch's own answers: the
     * environment, the user's two files, and the project files listed but not
     * read (trust unknown). `Cli\Bootstrap::app()` is the place that knows more
     * and should hand {@see \SugarCraft\Crush\App\App::withSettingsSources()} a
     * {@see fromLaunch()} built with its own trust answer and flags.
     */
    public static function bestEffort(?string $root): self
    {
        $home = HomeDirectory::owned();
        $userConfig = null;
        try {
            $userConfig = \SugarCraft\Crush\Cli\Bootstrap::userConfigPath();
        } catch (\Throwable) {
            // No home this process can name: report no user config rather
            // than inventing one.
        }

        $env = [];
        foreach (getenv() as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $env[$name] = $value;
            }
        }

        return self::fromLaunch(
            $root,
            null,
            $home === null ? null : rtrim($home, '/') . '/' . LayeredSettings::dir(),
            $userConfig,
            $env,
        );
    }
}
