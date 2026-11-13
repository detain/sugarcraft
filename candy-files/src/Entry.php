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
     * size compacted to KB/MB/GB/TB.
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
     * rendered: complete escape sequences first (OSC strings consumed
     * whole with their parameters, likewise CSI; other ESC-initiated
     * ECMA-48 sequences lose only their introducer to the generic
     * leg — a DCS string is not consumed up to its ST, so its
     * parameter body stays behind), then a sweep of every leftover
     * C0 control, ESC, and DEL byte, then C1 controls in BOTH
     * spellings: the UTF-8-encoded form (\xc2\x80-\xc2\x9f) and lone
     * 8-bit bytes (0x80-0x9F) outside a valid UTF-8 sequence, per the
     * sugar-core Ansi/Sanitize doctrine — a raw \x9b acts as CSI for
     * many terminals, so it must never reach one. Canonical multi-byte
     * text is matched whole first and survives (日本語, é); a dangling
     * lead byte of an invalid sequence degrades to printable junk, the
     * same intentional outcome the ESC legs accept.
     *
     * The old implementation exempted ESC (\x1b) itself from the sweep and
     * only knew the CSI shape, so an OSC set-title, a charset selection
     * (ESC B) or a save-cursor (ESC 7) crafted into a filename survived
     * verbatim and was rendered by sugar-style — terminal injection.
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
        // Lone 8-bit C1 bytes are controls too (0x9b is CSI for VT-style
        // hosts). Byte-mode walk: canonical UTF-8 sequences are matched
        // whole by the leading alternation branches and kept, so only a
        // C1 byte that is NOT part of a valid sequence falls through to
        // the final branch and is dropped. No /u — same reason as above.
        $s = preg_replace_callback(
            '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|[\x80-\x9f]/',
            static fn(array $m): string => strlen($m[0]) === 1 ? '' : $m[0],
            $s,
        ) ?? '';
        // CF-27 (closes audit 039): BIDI overrides/embeddings (U+202A-U+202E),
        // isolates (U+2066-U+2069) and the BOM/zero-width no-break space
        // (U+FEFF) are the spoofing layer — 'invoice.txt.<U+202E>gpj.exe'
        // PAINTS as 'invoice.txt.jpgexe' on a bidi-aware terminal, hiding
        // the real extension. They survive every control sweep above because
        // they are format characters, not controls, so they get the visible
        // <U+XXXX> marker treatment sugar-core's Sanitize established.
        // Byte-mode str_replace: same discipline as the C1 walk — no /u on
        // a string that may carry malformed sequences, and a fixed 3-byte
        // needle cannot mis-fire on valid UTF-8.
        return str_replace(
            [
                "\xE2\x80\xAA", "\xE2\x80\xAB", "\xE2\x80\xAC", "\xE2\x80\xAD", "\xE2\x80\xAE",
                "\xE2\x81\xA6", "\xE2\x81\xA7", "\xE2\x81\xA8", "\xE2\x81\xA9",
                "\xEF\xBB\xBF",
            ],
            [
                '<U+202A>', '<U+202B>', '<U+202C>', '<U+202D>', '<U+202E>',
                '<U+2066>', '<U+2067>', '<U+2068>', '<U+2069>',
                '<U+FEFF>',
            ],
            $s,
        );
    }
}
