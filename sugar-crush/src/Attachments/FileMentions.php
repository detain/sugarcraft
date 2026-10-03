<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Attachments;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;

/**
 * `@file` mentions in a prompt (audit 15b-15): finding them, turning them into
 * attachments, and completing a half-typed one.
 *
 * A mention is `@` at the start of the prompt or after whitespace, followed by
 * a path - bare (`@src/Chat.php`) or quoted for a path with spaces
 * (`@"my notes.md"`). Relative paths resolve against the project root, `~/`
 * against the home directory, and an absolute path is taken as written: the
 * user typed it, so it is the user's own choice of what to show the model,
 * the same as pasting the file's text would be.
 *
 * WHAT IS NOT A MENTION STAYS TEXT. An `@name` that names no file ("ask
 * @team") is prose and is sent as typed, silently; one that LOOKS like a path
 * (a `/` or an extension) and resolves to nothing gets a notice, because that
 * is a typo the user would want to hear about before the model answers a
 * question about a file it was never shown. The prompt text itself is never
 * rewritten - the mention stays in it, so the model reads `@src/Chat.php`
 * beside the `<file path="src/Chat.php">` block it refers to.
 *
 * THE FILE IS READ ONCE, HERE, and the snapshot rides on the attachment - see
 * {@see Attachment} for why. That read happens on the Enter that submits the
 * prompt, so it is bounded: at most {@see MAX_MENTIONS} files, text capped at
 * {@see TEXT_MAX_BYTES} (longer files are truncated, and say so), images at
 * {@see IMAGE_MAX_BYTES} (larger ones are refused with a notice, because a
 * half image is not an image).
 */
final class FileMentions
{
    /** Most mentions one prompt may attach; later ones get a notice. */
    public const MAX_MENTIONS = 20;

    /** Longest text snapshot inlined; a longer file is truncated, visibly. */
    public const TEXT_MAX_BYTES = 256 * 1024;

    /**
     * Largest image attached. 5 MiB is Anthropic's per-image ceiling - the
     * strictest of the wires this app speaks - so an image under it is one
     * every vision provider here accepts.
     */
    public const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /** Most directory entries one completion looks at. */
    private const COMPLETION_SCAN_LIMIT = 2000;

    /**
     * `@path` or `@"path with spaces"`, at the start or after whitespace. The
     * lookbehind is what keeps `me@example.com` prose.
     */
    private const MENTION_PATTERN = '/(?<![^\s])@(?:"([^"\r\n]+)"|([^\s"]+))/u';

    /** Trailing punctuation a sentence puts after a mention ("see @a.php."). */
    private const TRAILING_PUNCTUATION = '.,;:!?)]}\'';

    /**
     * Every mention in $text, resolved and read.
     *
     * @return array{attachments: list<Attachment>, notices: list<string>}
     */
    public static function resolve(string $text, string $root): array
    {
        $attachments = [];
        $notices = [];
        $seen = [];

        if (preg_match_all(self::MENTION_PATTERN, $text, $matches, PREG_SET_ORDER) === false) {
            return ['attachments' => [], 'notices' => []];
        }

        foreach ($matches as $match) {
            $quoted = ($match[1] ?? '') !== '';
            $written = $quoted ? $match[1] : $match[2];
            $path = self::locate($written, $root, $quoted);

            if ($path === null) {
                if (self::looksLikeAPath($written)) {
                    $notices[] = sprintf('@%s matched no file, so it was sent as plain text.', self::shown($written));
                }

                continue;
            }

            $display = $path['written'];
            if (is_dir($path['absolute'])) {
                $notices[] = sprintf('@%s is a directory and was not attached; mention the files in it instead.', self::shown($display));

                continue;
            }

            $key = realpath($path['absolute']) ?: $path['absolute'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (count($attachments) >= self::MAX_MENTIONS) {
                $notices[] = sprintf(
                    '@%s was not attached: one prompt attaches at most %d files.',
                    self::shown($display),
                    self::MAX_MENTIONS,
                );

                continue;
            }

            [$attachment, $notice] = self::read($path['absolute'], $display);
            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
            if ($notice !== null) {
                $notices[] = $notice;
            }
        }

        return ['attachments' => $attachments, 'notices' => $notices];
    }

    /**
     * The `@` token the caret sits at the end of, or null when it is not in
     * one. `start` is the byte offset of the `@`; `partial` is what follows it
     * (bare form only - a quoted mention is not completed).
     *
     * @return array{start: int, partial: string}|null
     */
    public static function tokenAt(string $buffer, int $caret): ?array
    {
        $caret = max(0, min($caret, strlen($buffer)));
        $before = substr($buffer, 0, $caret);
        if (preg_match('/(?<![^\s])@([^\s"]*)$/u', $before, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // The rest of the token must end at the caret: completing in the
        // middle of `@src/Ch|at.php` would splice the completion into it.
        if ($caret < strlen($buffer) && !ctype_space($buffer[$caret])) {
            return null;
        }

        return ['start' => $m[0][1], 'partial' => $m[1][0]];
    }

    /**
     * Complete a half-typed mention path the way a shell completes: a unique
     * match comes back whole (a directory with its trailing `/`), several come
     * back as their longest common prefix. Null when nothing matches or the
     * matches add nothing to what is typed. `unique` says the path is one
     * whole FILE, so the caller may close the mention with a space.
     *
     * @return array{path: string, unique: bool}|null
     */
    public static function complete(string $partial, string $root): ?array
    {
        $slash = strrpos($partial, '/');
        $dirPart = $slash === false ? '' : substr($partial, 0, $slash + 1);
        $prefix = $slash === false ? $partial : substr($partial, $slash + 1);

        $dir = self::absolute($dirPart === '' ? '.' : $dirPart, $root);
        if ($dir === null || !is_dir($dir)) {
            return null;
        }

        $handle = @opendir($dir);
        if ($handle === false) {
            return null;
        }

        $names = [];
        $scanned = 0;
        while (($entry = readdir($handle)) !== false && $scanned++ < self::COMPLETION_SCAN_LIMIT) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Hidden entries only when the user has started typing the dot.
            if ($entry[0] === '.' && !str_starts_with($prefix, '.')) {
                continue;
            }
            if (str_starts_with($entry, $prefix)) {
                $names[] = $entry;
            }
        }
        closedir($handle);

        if ($names === []) {
            return null;
        }

        sort($names, SORT_STRING);
        if (count($names) === 1) {
            $only = $names[0];
            $isDir = is_dir(rtrim($dir, '/') . '/' . $only);
            $path = $dirPart . $only . ($isDir ? '/' : '');

            return $path === $partial && $isDir ? null : ['path' => $path, 'unique' => !$isDir];
        }

        $common = $names[0];
        foreach ($names as $name) {
            $length = 0;
            $max = min(strlen($common), strlen($name));
            while ($length < $max && $common[$length] === $name[$length]) {
                $length++;
            }
            $common = substr($common, 0, $length);
        }

        return strlen($common) > strlen($prefix) ? ['path' => $dirPart . $common, 'unique' => false] : null;
    }

    /**
     * The media type of image bytes, by magic number - PNG, JPEG, GIF and
     * WebP, the four every vision wire here accepts - or null.
     */
    public static function sniffImage(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * Read one resolved file into an attachment, or explain why not.
     *
     * @return array{0: ?Attachment, 1: ?string}
     */
    private static function read(string $absolute, string $display): array
    {
        $size = @filesize($absolute);
        $handle = @fopen($absolute, 'rb');
        if ($size === false || $handle === false) {
            return [null, sprintf('@%s could not be read, so it was not attached.', self::shown($display))];
        }

        try {
            $head = (string) fread($handle, 16);
            $mime = self::sniffImage($head);

            if ($mime !== null) {
                if ($size > self::IMAGE_MAX_BYTES) {
                    return [null, sprintf(
                        '@%s was not attached: the image is %s, over the %s limit.',
                        self::shown($display),
                        self::bytes($size),
                        self::bytes(self::IMAGE_MAX_BYTES),
                    )];
                }
                $bytes = $head . (string) stream_get_contents($handle);

                return [new Attachment($display, AttachmentType::Image, $bytes, $mime), null];
            }

            $text = $head . (string) stream_get_contents($handle, self::TEXT_MAX_BYTES - strlen($head));
        } finally {
            fclose($handle);
        }

        if (str_contains($text, "\0")) {
            return [null, sprintf(
                '@%s was not attached: it is neither text nor a PNG, JPEG, GIF or WebP image.',
                self::shown($display),
            )];
        }

        $notice = null;
        if ($size > self::TEXT_MAX_BYTES) {
            // Cut on a character boundary, never inside a UTF-8 sequence.
            $text = mb_strcut($text, 0, self::TEXT_MAX_BYTES, 'UTF-8');
            $text .= sprintf("\n[… truncated: the file is %s; the first %s are included]", self::bytes($size), self::bytes(self::TEXT_MAX_BYTES));
            $notice = sprintf(
                '@%s is %s; only its first %s were attached.',
                self::shown($display),
                self::bytes($size),
                self::bytes(self::TEXT_MAX_BYTES),
            );
        }

        // Invalid UTF-8 would make every JSON wire refuse the whole request.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return [new Attachment($display, AttachmentType::File, $text), $notice];
    }

    /**
     * Find the file a written mention names, trying it with sentence
     * punctuation trimmed off the end when the whole token names nothing.
     *
     * @return array{absolute: string, written: string}|null
     */
    private static function locate(string $written, string $root, bool $quoted): ?array
    {
        $candidates = [$written];
        if (!$quoted) {
            $trimmed = rtrim($written, self::TRAILING_PUNCTUATION);
            if ($trimmed !== $written && $trimmed !== '') {
                $candidates[] = $trimmed;
            }
        }

        foreach ($candidates as $candidate) {
            $absolute = self::absolute($candidate, $root);
            if ($absolute !== null && file_exists($absolute)) {
                return ['absolute' => $absolute, 'written' => $candidate];
            }
        }

        return null;
    }

    private static function absolute(string $path, string $root): ?string
    {
        if ($path === '') {
            return null;
        }
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = (string) (getenv('HOME') ?: '');
            if ($home === '') {
                return null;
            }

            return rtrim($home, '/') . substr($path, 1);
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        if ($root === '') {
            return null;
        }

        return rtrim($root, '/') . '/' . $path;
    }

    private static function looksLikeAPath(string $written): bool
    {
        $trimmed = rtrim($written, self::TRAILING_PUNCTUATION);

        return str_contains($trimmed, '/') || preg_match('/\.[A-Za-z0-9]{1,8}$/', $trimmed) === 1;
    }

    /** A user-typed path as a notice quotes it: one line, bounded. */
    private static function shown(string $path): string
    {
        $flat = (string) preg_replace('/[\p{C}]+/u', ' ', $path);
        if ($flat === '' && $path !== '') {
            $flat = (string) preg_replace('/[[:cntrl:]]+/', ' ', $path);
        }

        return mb_strlen($flat) > 120 ? mb_substr($flat, 0, 119) . '…' : $flat;
    }

    private static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MiB',
            $bytes >= 1024 => rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KiB',
            default => $bytes . ' B',
        };
    }
}
