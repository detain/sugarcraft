<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

/**
 * Persistent bookmark store for the `a` / `B` chords (CF-30).
 *
 * No upstream - house pattern. superfile keeps its pinned folders in a
 * hand-edited TOML file; here the file is app-owned JSON under the XDG
 * data home, written atomically (tmp file in the same directory, then
 * rename) so a crash mid-save leaves the old list, never a torn one. The
 * directory is created 0700 and the file 0600 - the list is a map of
 * where the user works.
 *
 * INTERIM HOME: the shared config home (CF-51) is not built; bookmarks
 * are state, not config, so `$XDG_DATA_HOME/candy-files/bookmarks.json`
 * (falling back to `~/.local/share`, or %APPDATA% on Windows) is the
 * correct long-term spot anyway.
 *
 * Reads are forgiving by design (a missing, empty, or corrupt file is an
 * empty list - a bad bookmarks file must never keep the file manager from
 * starting); writes report failure so the UI can say so.
 */
final class Bookmarks
{
    /** Hard cap so a runaway file cannot grow the overlay without bound. */
    public const MAX = 256;

    /** @param array<string, string|false>|null $env override for tests; null reads the live environment */
    public static function path(?array $env = null): string
    {
        $read = static function (string $key) use ($env): string {
            $v = $env === null ? \getenv($key) : ($env[$key] ?? false);

            return \is_string($v) && $v !== '' ? $v : '';
        };
        $base = $read('XDG_DATA_HOME');
        if ($base === '') {
            $appData = DIRECTORY_SEPARATOR === '\\' ? $read('APPDATA') : '';
            $base = $appData !== '' ? $appData : rtrim(Home::resolve($env), '/\\') . '/.local/share';
        }

        return rtrim($base, '/\\') . '/candy-files/bookmarks.json';
    }

    /** @return list<string> absolute directory paths, order preserved, de-duplicated */
    public static function load(?string $file = null): array
    {
        $file ??= self::path();
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $data = \json_decode($raw, true);
        $list = \is_array($data) && \is_array($data['bookmarks'] ?? null) ? $data['bookmarks'] : [];
        $out = [];
        foreach ($list as $p) {
            if (\is_string($p) && $p !== '' && !\in_array($p, $out, true)) {
                $out[] = $p;
            }
            if (\count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /** @param list<string> $paths */
    public static function save(array $paths, ?string $file = null): bool
    {
        $file ??= self::path();
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return false;
        }
        $json = \json_encode(['version' => 1, 'bookmarks' => \array_values($paths)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $tmp = $file . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (@\file_put_contents($tmp, $json . "\n") === false) {
            @\unlink($tmp);

            return false;
        }
        @\chmod($tmp, 0600);
        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);

            return false;
        }

        return true;
    }
}
