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
        $unitCount = count($units);
        while ($n >= 1024 && $i < $unitCount - 1) {
            $n /= 1024;
            $i++;
        }
        return $i === 0
            ? sprintf('%dB',  (int) $n)
            : sprintf('%.1f%s', $n, $units[$i]);
    }

    /**
     * Strip every terminal-control construct so a filename is inert when
     * rendered: complete escape sequences first (OSC/DCS strings consumed
     * whole with their parameters, CSI, then all remaining ESC-initiated
     * ECMA-48 sequences), then a sweep of every leftover C0 control, ESC,
     * and DEL byte, then UTF-8-encoded C1 controls.
     *
     * The old implementation exempted ESC (\x1b) itself from the sweep and
     * only knew the CSI shape, so an OSC set-title, a charset selection
     * (ESC B) or a save-cursor (ESC 7) crafted into a filename survived
     * verbatim and was rendered by candy-sprinkles — terminal injection.
     * Sweeping to bare text is intentional: a stripped sequence's
     * parameters may remain as printable characters (never as controls).
     */
    public static function sanitizeName(string $s): string
    {
        // Whole-string escape productions, re-applied until stable so
        // sequences nested inside a stripped string's parameters (…\x1b]0;a
        // \x1b]0;b\x07c\x07) cannot re-assemble into a live introducer.
        // Bounded: one malformed remnant per pass dies in the sweep below.
        for ($pass = 0; $pass < 4; $pass++) {
            $stripped = preg_replace_callback(
                '/\x1b\][^\x1b\x07]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]|\x1b[\x20-\x2f]*[\x30-\x7e]/',
                static fn(array $m): string => '',
                $s,
            ) ?? '';
            if ($stripped === $s) {
                break;
            }
            $s = $stripped;
        }
        // Sweep every C0 control (ESC included now), and DEL.
        $s = preg_replace('/[\x00-\x1f\x7f]/', '', $s) ?? '';
        // Strip C1 control chars (U+0080-U+009F, encoded as \xc2\x80-\xc2\x9f in UTF-8)
        // Use separate pattern without /u to avoid PCRE UTF-8 mode conflicts
        $s = preg_replace('/\xc2[\x80-\x9f]/', '', $s) ?? '';
        return $s;
    }
}
