<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

/**
 * One row of the theme list: a builtin ("Default", "TTY") or a `.theme`
 * file on disk. Two files with the same filename in different directories
 * are distinct entries — btop lists both and the higher-priority directory
 * shadows the other on lookup.
 *
 * Mirrors aristocratos/btop Theme::themes entries (src/btop_theme.hpp).
 */
final class ThemeEntry
{
    private function __construct(
        private readonly string $name,
        private readonly ?string $path,
    ) {
    }

    public static function builtin(string $name): self
    {
        return new self($name, null);
    }

    public static function file(string $path): self
    {
        return new self(pathinfo($path, PATHINFO_FILENAME), $path);
    }

    /** "Default", "TTY", or the file stem (what btop's options menu shows). */
    public function name(): string
    {
        return $this->name;
    }

    /** Full path, null for a builtin. */
    public function path(): ?string
    {
        return $this->path;
    }

    /** Basename with extension (btop stores this in `color_theme`), or the builtin name. */
    public function filename(): string
    {
        return $this->path === null ? $this->name : basename($this->path);
    }

    public function isBuiltin(): bool
    {
        return $this->path === null;
    }

    /**
     * btop's setTheme match: full path, filename, stem, or an absolute
     * (legacy) config path whose filename matches.
     */
    public function matches(string $theme): bool
    {
        if ($this->path === null) {
            return $theme === $this->name;
        }
        return $theme === $this->path
            || $theme === basename($this->path)
            || $theme === $this->name
            || (str_starts_with($theme, '/') && basename($theme) === basename($this->path));
    }
}
