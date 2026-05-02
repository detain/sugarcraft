<?php

declare(strict_types=1);

namespace CandyCore\Bits\FilePicker;

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
        return rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->name;
    }

    public function display(): string
    {
        return $this->isDir ? $this->name . '/' : $this->name;
    }

    /**
     * Suggest a single-character icon for this entry — directory,
     * common file-type extensions, or a generic file glyph.
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
        while ($size >= 1024.0 && $i < count($units) - 1) {
            $size /= 1024.0;
            $i++;
        }
        return $i === 0
            ? sprintf('%dB',  (int) $size)
            : sprintf('%.1f%s', $size, $units[$i]);
    }
}
