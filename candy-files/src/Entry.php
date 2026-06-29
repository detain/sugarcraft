<?php

declare(strict_types=1);

namespace SugarCraft\Files;

/**
 * One row in a {@see Pane}'s listing — a file, directory, or
 * symlink found in the current directory. Immutable value object.
 *
 * The "..  parent" sentinel (a `..` Entry inserted at the top of
 * any non-root pane) is constructed via {@see parent()} so the
 * navigator can always present it consistently regardless of the
 * underlying filesystem layout.
 */
final class Entry
{
    public function __construct(
        public readonly string $name,
        public readonly bool   $isDir,
        public readonly int    $size,
        public readonly int    $mtime,
        public readonly bool   $isLink = false,
        public readonly bool   $isHidden = false,
    ) {}

    public static function parent(): self
    {
        return new self('..', true, 0, 0);
    }

    public function isParentSentinel(): bool
    {
        return $this->name === '..' && $this->isDir;
    }

    /**
     * Display string for the size column. Directories render
     * "DIR", links render "LINK", regular files render their byte
     * size compacted to KB/MB/GB.
     */
    /**
     * Display string for the size column. Directories render
     * "DIR", links render "LINK", regular files render their byte
     * size compacted to KB/MB/GB.
     */
    public function displaySize(): string
    {
        if ($this->isDir) {
            return 'DIR';
        }
        if ($this->isLink) {
            return 'LINK';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $this->size;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return $i === 0
            ? sprintf('%dB',  (int) $n)
            : sprintf('%.1f%s', $n, $units[$i]);
    }

    /**
     * Strip C0/C1 control bytes and DEL so a filename is safe to
     * render to the terminal without emitting ANSI escape sequences.
     * Also strips the full ANSI CSI sequences (ESC [ ... letter).
     */
    public static function sanitizeName(string $s): string
    {
        // Strip ANSI escape sequences (ESC [ params letter)
        $s = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $s);
        // Strip C0 control chars (excluding ESC\x1b which was handled above),
        // and DEL
        $s = preg_replace('/[\x00-\x1a\x1c-\x1f\x7f]/', '', $s);
        // Strip C1 control chars (U+0080-U+009F, encoded as \xc2\x80-\xc2\x9f in UTF-8)
        // Use separate pattern without /u to avoid PCRE UTF-8 mode conflicts
        $s = preg_replace('/\xc2[\x80-\x9f]/', '', $s);
        return $s;
    }
}
