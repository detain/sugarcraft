<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

/**
 * The ordered theme list: builtin Default and TTY first, then every
 * readable `*.theme` file from the search directories, sorted by filename.
 *
 * Search priority follows btop's custom → user → system walk, adapted to
 * candy-top paths: an optional custom dir (btop's `--themes-dir`), the user
 * dir `$XDG_CONFIG_HOME/candy-top/themes` (else `$HOME/.config/candy-top/themes`),
 * then the 43 themes bundled in `candy-top/themes/`. The filename
 * sort is stable, so when two directories ship the same filename the
 * higher-priority copy sits first and wins every lookup — a user can shadow
 * a bundled theme by dropping a same-named file in their config dir.
 *
 * Mirrors aristocratos/btop Theme::updateThemes / setTheme and the
 * options-menu color_theme cycling (src/btop_theme.cpp, src/btop_menu.cpp).
 */
final class ThemeRegistry
{
    /**
     * @param list<ThemeEntry> $entries
     */
    private function __construct(
        private readonly array $entries,
    ) {
    }

    /**
     * Discover from the standard locations.
     *
     * @param ?array<string, string> $env environment to read XDG_CONFIG_HOME/HOME from; null = getenv()
     */
    public static function new(?string $customDir = null, ?array $env = null): self
    {
        $env ??= getenv();
        return self::fromDirs(...array_values(array_filter(
            [$customDir, self::userDir($env), self::bundledDir()],
            static fn (?string $d): bool => $d !== null && $d !== '',
        )));
    }

    /**
     * Discover from explicit directories, highest priority first. Missing or
     * unreadable directories are skipped, as btop clears an unusable theme dir.
     */
    public static function fromDirs(string ...$dirs): self
    {
        $files = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir) || !is_readable($dir)) {
                continue;
            }
            $names = scandir($dir);
            if ($names === false) {
                continue;
            }
            foreach ($names as $name) {
                $path = rtrim($dir, '/') . '/' . $name;
                // btop matches fs::path::extension(), which is empty for a bare ".theme" dotfile.
                if ($name !== '.theme' && str_ends_with($name, '.theme') && is_file($path) && is_readable($path) && !in_array($path, $files, true)) {
                    $files[] = $path;
                }
            }
        }
        // usort is stable (PHP >= 8.0), preserving directory priority among equal filenames.
        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        $entries = [ThemeEntry::builtin('Default'), ThemeEntry::builtin('TTY')];
        foreach ($files as $path) {
            $entries[] = ThemeEntry::file($path);
        }
        return new self($entries);
    }

    /** The upstream themes shipped with candy-top. */
    public static function bundledDir(): string
    {
        return dirname(__DIR__, 2) . '/themes';
    }

    /**
     * Per-user theme directory, btop's `conf_dir / "themes"` with candy-top's
     * config root.
     *
     * @param array<string, string> $env
     */
    public static function userDir(array $env): ?string
    {
        $xdg = $env['XDG_CONFIG_HOME'] ?? '';
        if ($xdg !== '') {
            return rtrim($xdg, '/') . '/candy-top/themes';
        }
        $home = $env['HOME'] ?? '';
        return $home === '' ? null : rtrim($home, '/') . '/.config/candy-top/themes';
    }

    /** @return list<ThemeEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<string> display names, in list order (duplicates possible when shadowed) */
    public function names(): array
    {
        return array_map(static fn (ThemeEntry $e): string => $e->name(), $this->entries);
    }

    /**
     * Entry for `$theme`: an exact path match first, else the first entry
     * matching by name, filename, stem, or legacy absolute path; null when none.
     *
     * The exact-path pass is a deliberate deviation: btop's setTheme tries the
     * legacy rule in the same pass, so a shadowed theme stored by full path
     * resolves to the shadowing copy and can never be loaded.
     */
    public function find(string $theme): ?ThemeEntry
    {
        $i = $this->indexOf($theme);
        return $i === null ? null : $this->entries[$i];
    }

    /**
     * What to persist as `color_theme` for `$entry`: its filename when it is
     * the first entry with that filename, else its full path (the shadowed
     * copy). Mirrors btop's options-menu write-back.
     */
    public function configValue(ThemeEntry $entry): string
    {
        foreach ($this->entries as $candidate) {
            if ($candidate->filename() === $entry->filename()) {
                return $candidate->path() === $entry->path() ? $entry->filename() : (string) $entry->path();
            }
        }
        return $entry->path() ?? $entry->name();
    }

    /**
     * Resolve `$theme` to a palette. "TTY" — or any theme while `$ttyMode`
     * is on (btop's `tty_mode` option / linux console) — is the stepped
     * 16-color theme. "Default", any name that matches no entry, and a listed
     * file that has since become unreadable are the Default theme (btop's
     * setTheme + loadFile fallbacks, so a stale `color_theme` never blanks the UI).
     */
    public function load(string $theme, bool $themeBackground = true, bool $ttyMode = false): Palette
    {
        if ($ttyMode || $theme === 'TTY') {
            return TtyTheme::new()->withThemeBackground($themeBackground);
        }
        $path = $this->find($theme)?->path();
        try {
            $config = $path === null ? ThemeConfig::new() : ThemeConfig::fromFile($path);
        } catch (\InvalidArgumentException) {
            $config = ThemeConfig::new();
        }
        return $config->withThemeBackground($themeBackground);
    }

    /**
     * The entry after `$current` (a name, filename, path, or
     * {@see configValue()}), wrapping; an unknown current yields the first entry.
     */
    public function next(string $current): ThemeEntry
    {
        $i = $this->indexOf($current);
        $i = $i === null || $i + 1 >= count($this->entries) ? 0 : $i + 1;
        return $this->entries[$i];
    }

    /** The entry before `$current`, wrapping; an unknown current yields the last entry. */
    public function prev(string $current): ThemeEntry
    {
        $i = $this->indexOf($current) ?? count($this->entries);
        $i = $i - 1 < 0 ? count($this->entries) - 1 : $i - 1;
        return $this->entries[$i];
    }

    private function indexOf(string $theme): ?int
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry->path() !== null && $entry->path() === $theme) {
                return $i;
            }
        }
        foreach ($this->entries as $i => $entry) {
            if ($entry->matches($theme)) {
                return $i;
            }
        }
        return null;
    }
}
