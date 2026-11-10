<?php

declare(strict_types=1);

namespace SugarCraft\Forms\FilePicker;

/**
 * One filesystem entry the {@see FilePicker} has discovered.
 */
final class Entry
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isDir,
        public readonly bool $isHidden,
        public readonly int $size  = 0,
        public readonly int $mtime = 0,
    ) {}

    public function path(string $cwd): string
    {
        return self::join($cwd, $this->name);
    }

    /**
     * Join a directory and entry name using the separator the caller's path
     * already speaks. On Windows DIRECTORY_SEPARATOR is '\' yet callers may
     * hold slash-shaped paths (forwarded dial strings, '/'-typed cwds, test
     * fixtures); gluing those with '\' produces mixed paths that is_dir()
     * and string comparisons then miss. A path with any '/' in it is treated
     * as slash-shaped; otherwise the platform separator rules.
     */
    public static function join(string $cwd, string $name): string
    {
        $separator = str_contains($cwd, '/') ? '/' : DIRECTORY_SEPARATOR;

        return rtrim($cwd, $separator) . $separator . $name;
    }

    public function display(): string
    {
        return $this->isDir ? $this->name . '/' : $this->name;
    }

    /**
     * Suggest a single-character icon for this entry — directory,
     * common file-type extensions, or a generic file glyph.
     *
     * Compatibility note (E736 plan 7.3): every glyph here is a
     * non-BMP / emoji-block codepoint (e.g. 📁 U+1F4C1). Rendering
     * requires a terminal font with emoji coverage, and most emulators
     * report these cells as DOUBLE-width, so icon columns should budget
     * two cells. Terminals without the font show blanks or tofu boxes.
     * An ASCII fallback option ([D]/[F]) was considered and deferred:
     * it changes the picker's public surface and visual contract, so it
     * needs its own design pass rather than an audit fix.
     */
    public function icon(): string
    {
        if ($this->isDir) {
            return '📁';
        }
        $ext = strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
        return match ($ext) {
            'php', 'py', 'js', 'ts', 'go', 'rb', 'rs' => '📜',
            'md', 'txt', 'rst'                         => '📄',
            'json', 'yaml', 'yml', 'toml', 'ini'       => '🧾',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp' => '🖼',
            'mp3', 'wav', 'flac', 'ogg'                => '🎵',
            'mp4', 'mov', 'mkv', 'avi'                 => '🎬',
            'zip', 'tar', 'gz', 'bz2', 'xz', '7z'      => '📦',
            default                                    => '📄',
        };
    }

    /**
     * Format `$size` as a short SI-style string ("4.2K", "1.7M").
     * Bubble Tea's filepicker uses the same compact form.
     */
    public function formatSize(): string
    {
        if ($this->isDir) {
            return '';
        }
        $units = ['B', 'K', 'M', 'G', 'T'];
        $size  = (float) $this->size;
        $i = 0;
        $unitCount = count($units);
        while ($size >= 1024.0 && $i < $unitCount - 1) {
            $size /= 1024.0;
            $i++;
        }
        return $i === 0
            ? sprintf('%dB',  (int) $size)
            : sprintf('%.1f%s', $size, $units[$i]);
    }
}
