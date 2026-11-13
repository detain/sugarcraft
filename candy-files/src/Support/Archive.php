<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

use SugarCraft\Files\Entry;

/**
 * Read-only archive index for the CF-33 browse view (.zip, .tar, .tar.gz /
 * .tgz, .tar.bz2 / .tbz2).
 *
 * No upstream - house pattern. superfile and mc both browse archives
 * through external helpers; here the index is built in-process so the
 * safety rules below are enforced in ONE place rather than trusted to a
 * subprocess.
 *
 * VIRTUAL PATHS. A pane inside an archive carries a cwd of the form
 * `/real/path/a.zip!/inner/dir` (jar-URL style). {@see split()} only
 * recognises it when the left of the `!` is an existing regular FILE with
 * an archive extension, so a real directory that happens to contain a `!`
 * stays an ordinary directory.
 *
 * SAFETY (the zip-slip / bomb contract):
 *  - every entry name is normalised by {@see normalize()}: absolute paths,
 *    drive letters, NUL bytes and any `..` segment (either slash flavour)
 *    make the entry UNSAFE - it is dropped from the index and counted, so
 *    it can neither be browsed nor extracted;
 *  - symlinks, hard links, devices and FIFOs are never listed or
 *    materialised (counted as special) - a link in the archive can never
 *    be used to redirect a later write;
 *  - listing is bounded: {@see MAX_ENTRIES} records and, for compressed
 *    tars (which must be decompressed to be indexed), {@see MAX_SCAN}
 *    uncompressed bytes - past either the archive is refused, not
 *    partially shown.
 *
 * The parsed index is cached per archive (keyed by mtime + size, last four
 * archives), so navigating directories inside one archive re-parses nothing.
 */
final class Archive
{
    public const SEP = '!';
    public const MAX_ENTRIES = 50000;
    /** Uncompressed bytes a compressed tar may expand to while being indexed. */
    public const MAX_SCAN = 268435456;
    /** Total uncompressed bytes one extraction may write. */
    public const MAX_EXTRACT = 536870912;

    private static ?int $scanLimit = null;
    private static ?int $extractLimit = null;
    private static ?int $entryLimit = null;

    /** Record ceiling in force. */
    public static function maxEntries(): int
    {
        return self::$entryLimit ?? self::MAX_ENTRIES;
    }

    /** Uncompressed scan ceiling in force (tests lower it; null restores the constant). */
    public static function maxScan(): int
    {
        return self::$scanLimit ?? self::MAX_SCAN;
    }

    /** Extraction ceiling in force. */
    public static function maxExtract(): int
    {
        return self::$extractLimit ?? self::MAX_EXTRACT;
    }

    /** Test seam: override the bomb ceilings (null = the shipped constants). */
    public static function useLimits(?int $scan, ?int $extract, ?int $entries = null): void
    {
        self::$scanLimit = $scan;
        self::$extractLimit = $extract;
        self::$entryLimit = $entries;
        self::$cache = [];
    }

    /** @var array<string, array{sig:string, index:array{entries:list<array{path:string,isDir:bool,size:int,mtime:int}>,hidden:int}}> */
    private static array $cache = [];

    /** @return 'zip'|'tar'|'tgz'|'tbz'|null */
    public static function format(string $name): ?string
    {
        $n = strtolower($name);
        return match (true) {
            str_ends_with($n, '.zip') => 'zip',
            str_ends_with($n, '.tar.gz'), str_ends_with($n, '.tgz') => 'tgz',
            str_ends_with($n, '.tar.bz2'), str_ends_with($n, '.tbz2'), str_ends_with($n, '.tbz') => 'tbz',
            str_ends_with($n, '.tar') => 'tar',
            default => null,
        };
    }

    /** Name of the PHP extension the format needs and the host lacks, or null when it can be read. */
    public static function missingExtension(string $format): ?string
    {
        return match ($format) {
            'zip' => class_exists(\ZipArchive::class) ? null : 'zip',
            'tgz' => in_array('compress.zlib', stream_get_wrappers(), true) ? null : 'zlib',
            'tbz' => in_array('compress.bzip2', stream_get_wrappers(), true) ? null : 'bz2',
            default => null,
        };
    }

    /**
     * Split a virtual path into [archive file, inner path without slashes
     * at the ends], or null for an ordinary path.
     *
     * @return array{0:string,1:string}|null
     */
    public static function split(string $path): ?array
    {
        if (!str_contains($path, self::SEP)) {
            return null;
        }
        $offset = 0;
        while (($pos = strpos($path, self::SEP, $offset)) !== false) {
            $offset = $pos + 1;
            $after = substr($path, $pos + 1);
            if ($after !== '' && $after[0] !== '/') {
                continue;
            }
            $file = substr($path, 0, $pos);
            if ($file !== '' && self::format($file) !== null && is_file($file)) {
                return [$file, trim($after, '/')];
            }
        }
        return null;
    }

    /** Is $path a virtual path into an archive? */
    public static function inside(string $path): bool
    {
        return self::split($path) !== null;
    }

    /**
     * A safe relative path (forward slashes, no `./`, no trailing slash) or
     * null when the entry name could escape its extraction root.
     */
    public static function normalize(string $raw): ?string
    {
        if ($raw === '' || str_contains($raw, "\0")) {
            return null;
        }
        $p = str_replace('\\', '/', $raw);
        if ($p[0] === '/' || preg_match('/^[A-Za-z]:/', $p) === 1) {
            return null;
        }
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $out[] = $seg;
        }
        return $out === [] ? null : implode('/', $out);
    }

    /**
     * Wrap a lister so virtual archive paths answer from the index and
     * everything else falls through untouched.
     *
     * @param \Closure(string): (list<Entry>|null) $inner
     * @return \Closure(string): (list<Entry>|null)
     */
    public static function lister(\Closure $inner): \Closure
    {
        return static function (string $path) use ($inner): ?array {
            $split = self::split($path);
            return $split === null ? $inner($path) : self::listing($split[0], $split[1]);
        };
    }

    /**
     * The immediate children of $inner ('' = archive root) as Entries.
     * Null when the archive cannot be indexed or $inner names nothing.
     *
     * @return list<Entry>|null
     */
    public static function listing(string $file, string $inner): ?array
    {
        $index = self::index($file);
        if ($index === null) {
            return null;
        }
        $prefix = $inner === '' ? '' : $inner . '/';
        $children = [];
        $found = $inner === '';
        foreach ($index['entries'] as $e) {
            $p = $e['path'];
            if ($inner !== '' && $p === $inner) {
                $found = $found || $e['isDir'];
                continue;
            }
            if ($prefix !== '' && !str_starts_with($p, $prefix)) {
                continue;
            }
            $found = true;
            $rest = substr($p, strlen($prefix));
            $slash = strpos($rest, '/');
            if ($slash !== false) {
                $seg = substr($rest, 0, $slash);
                $children[$seg] ??= ['isDir' => true, 'size' => 0, 'mtime' => 0];
                continue;
            }
            $children[$rest] = ['isDir' => $e['isDir'], 'size' => $e['isDir'] ? 0 : $e['size'], 'mtime' => $e['mtime']];
        }
        if (!$found) {
            return null;
        }
        $out = [];
        foreach ($children as $name => $c) {
            $out[] = new Entry((string) $name, $c['isDir'], $c['size'], $c['mtime'], isHidden: str_starts_with((string) $name, '.'));
        }
        return $out;
    }

    /**
     * Records hidden from the view (unsafe names, links, devices) for the
     * status line that greets the user on entry.
     */
    public static function hiddenCount(string $file): int
    {
        return self::index($file)['hidden'] ?? 0;
    }

    /**
     * @return array{entries:list<array{path:string,isDir:bool,size:int,mtime:int}>,hidden:int}|null
     */
    public static function index(string $file): ?array
    {
        $format = self::format($file);
        if ($format === null || self::missingExtension($format) !== null) {
            return null;
        }
        $st = @stat($file);
        if ($st === false) {
            return null;
        }
        $sig = $st['mtime'] . ':' . $st['size'];
        if (isset(self::$cache[$file]) && self::$cache[$file]['sig'] === $sig) {
            return self::$cache[$file]['index'];
        }
        try {
            $index = $format === 'zip' ? self::indexZip($file) : self::indexTar($file, $format);
        } catch (\Throwable) {
            return null;
        }
        self::$cache[$file] = ['sig' => $sig, 'index' => $index];
        if (count(self::$cache) > 4) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }
        return $index;
    }

    /** Drop cached indexes (tests; long sessions never need it - the signature self-invalidates). */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /** @return array{entries:list<array{path:string,isDir:bool,size:int,mtime:int}>,hidden:int} */
    private static function indexZip(string $file): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('cannot open zip');
        }
        $n = $zip->numFiles;
        if ($n > self::maxEntries()) {
            $zip->close();
            throw new \RuntimeException('too many entries');
        }
        $entries = [];
        $hidden = 0;
        for ($i = 0; $i < $n; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $raw = (string) $st['name'];
            $path = self::normalize($raw);
            if ($path === null || self::zipSpecial($zip, $i, (int) ($st['encryption_method'] ?? 0))) {
                $hidden++;
                continue;
            }
            $entries[] = [
                'path' => $path,
                'isDir' => str_ends_with($raw, '/'),
                'size' => (int) $st['size'],
                'mtime' => (int) $st['mtime'],
            ];
        }
        $zip->close();
        return ['entries' => $entries, 'hidden' => $hidden];
    }

    /** Symlink or encrypted zip record: never listed, never extracted. */
    public static function zipSpecial(\ZipArchive $zip, int $i, int $encryption): bool
    {
        if ($encryption > 0) {
            return true;
        }
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
            $type = ($attr >> 16) & 0170000;
            return $type !== 0 && $type !== 0100000 && $type !== 0040000;
        }
        return false;
    }

    /** @return array{entries:list<array{path:string,isDir:bool,size:int,mtime:int}>,hidden:int} */
    private static function indexTar(string $file, string $format): array
    {
        $fh = self::openTar($file, $format);
        try {
            $reader = new TarReader($fh, $format === 'tar', self::maxScan());
            $entries = [];
            $hidden = 0;
            while (($rec = $reader->next()) !== null) {
                if (count($entries) + $hidden >= self::maxEntries()) {
                    throw new \RuntimeException('too many entries');
                }
                $path = self::normalize($rec['name']);
                $isDir = $rec['type'] === '5' || str_ends_with($rec['name'], '/');
                $regular = $rec['type'] === '0' || $rec['type'] === '7';
                if ($path === null || (!$isDir && !$regular)) {
                    $hidden++;
                    continue;
                }
                $entries[] = ['path' => $path, 'isDir' => $isDir, 'size' => $isDir ? 0 : $rec['size'], 'mtime' => $rec['mtime']];
            }
            return ['entries' => $entries, 'hidden' => $hidden];
        } finally {
            fclose($fh);
        }
    }

    /** @return resource */
    public static function openTar(string $file, string $format)
    {
        $uri = match ($format) {
            'tgz' => 'compress.zlib://' . $file,
            'tbz' => 'compress.bzip2://' . $file,
            default => $file,
        };
        $fh = @fopen($uri, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('cannot open archive');
        }
        return $fh;
    }
}
