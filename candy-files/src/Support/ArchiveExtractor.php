<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Event-loop-friendly extraction of selected archive entries into a private
 * staging directory (CF-33). The Manager then hands the staging tree to the
 * ordinary copy engine, so overwrite announcements, confirm gating and the
 * CF-44 in-flight accounting all stay in one place.
 *
 * No upstream - house pattern.
 *
 * LOOP LAW (CF-11): extraction runs as a Generator that yields after every
 * {@see CHUNK} bytes (and after each skipped-entry slice); the driver resumes
 * it on Loop::futureTick, so a large archive never blocks key handling.
 *
 * SAFETY, enforced here and not delegated to the archive library:
 *  - only names that survive {@see Archive::normalize()} are written, always
 *    under the staging root (the staging dir is created fresh, 0700, and no
 *    link is ever created inside it, so a prefix check is a complete
 *    containment proof);
 *  - symlinks, hard links, devices and encrypted zip records are skipped and
 *    counted, never created;
 *  - the bomb meter counts bytes ACTUALLY written (never the header's claimed
 *    size) against {@see Archive::MAX_EXTRACT}; one byte over rejects the
 *    whole extraction;
 *  - extracted files get 0644 / directories 0755 regardless of the archive's
 *    modes - a setuid bit in an archive does not survive.
 */
final class ArchiveExtractor
{
    private const CHUNK = 1048576;

    /** A fresh 0700 staging directory under the system temp dir. */
    public static function newStaging(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/candyfiles-x-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create staging directory');
        }
        return $dir;
    }

    /** Remove a staging tree (our own mkdir'd tree: plain recursive unlink, never following links). */
    public static function discard(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (@scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::discard($dir . '/' . $item);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param list<string> $wanted archive-relative paths (a directory pulls its whole subtree)
     * @return PromiseInterface<array{files:int,bytes:int,skipped:int}>
     */
    public static function extractAsync(string $archive, array $wanted, string $staging): PromiseInterface
    {
        $deferred = new Deferred();
        $gen = self::steps($archive, $wanted, $staging);
        $started = false;
        $step = static function () use (&$step, $gen, $deferred, &$started): void {
            try {
                if ($started) {
                    $gen->next();
                }
                $started = true;
                if ($gen->valid()) {
                    Loop::futureTick($step);
                    return;
                }
                $deferred->resolve($gen->getReturn());
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }
        };
        Loop::futureTick($step);
        return $deferred->promise();
    }

    /**
     * @param list<string> $wanted
     * @return \Generator<int,null,mixed,array{files:int,bytes:int,skipped:int}>
     */
    public static function steps(string $archive, array $wanted, string $staging): \Generator
    {
        $format = Archive::format($archive);
        if ($format === null || Archive::missingExtension($format) !== null) {
            throw new \RuntimeException('unsupported archive');
        }
        $stats = ['files' => 0, 'bytes' => 0, 'skipped' => 0];
        $isWanted = static function (string $path) use ($wanted): bool {
            foreach ($wanted as $w) {
                if ($path === $w || str_starts_with($path, $w . '/')) {
                    return true;
                }
            }
            return false;
        };
        if ($format === 'zip') {
            yield from self::zipSteps($archive, $isWanted, $staging, $stats);
        } else {
            yield from self::tarSteps($archive, $format, $isWanted, $staging, $stats);
        }
        return $stats;
    }

    /**
     * @param \Closure(string): bool $isWanted
     * @param array{files:int,bytes:int,skipped:int} $stats
     */
    private static function zipSteps(string $archive, \Closure $isWanted, string $staging, array &$stats): \Generator
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('cannot open zip');
        }
        try {
            for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    continue;
                }
                $raw = (string) $st['name'];
                $path = Archive::normalize($raw);
                if ($path === null || !$isWanted($path)) {
                    continue;
                }
                if (Archive::zipSpecial($zip, $i, (int) ($st['encryption_method'] ?? 0))) {
                    $stats['skipped']++;
                    continue;
                }
                if (str_ends_with($raw, '/')) {
                    self::makeDir($staging . '/' . $path);
                    continue;
                }
                $in = $zip->getStream($raw);
                if ($in === false) {
                    throw new \RuntimeException('cannot read zip entry');
                }
                try {
                    yield from self::copyBody(static fn(int $n): string => (string) fread($in, $n), static fn(): bool => feof($in), $staging . '/' . $path, (int) $st['mtime'], $stats);
                } finally {
                    fclose($in);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param \Closure(string): bool $isWanted
     * @param array{files:int,bytes:int,skipped:int} $stats
     */
    private static function tarSteps(string $archive, string $format, \Closure $isWanted, string $staging, array &$stats): \Generator
    {
        $fh = Archive::openTar($archive, $format);
        try {
            $reader = new TarReader($fh, $format === 'tar', Archive::maxScan());
            while (($rec = $reader->next()) !== null) {
                $path = Archive::normalize($rec['name']);
                $isDir = $rec['type'] === '5' || str_ends_with($rec['name'], '/');
                $regular = $rec['type'] === '0' || $rec['type'] === '7';
                if ($path === null || !$isWanted($path)) {
                    yield from self::drain($reader);
                    continue;
                }
                if ($isDir) {
                    self::makeDir($staging . '/' . $path);
                    continue;
                }
                if (!$regular) {
                    $stats['skipped']++;
                    continue;
                }
                yield from self::copyBody(static fn(int $n): string => $reader->read($n), static fn(): bool => !$reader->hasBody(), $staging . '/' . $path, $rec['mtime'], $stats);
            }
        } finally {
            fclose($fh);
        }
    }

    /** Skip an unwanted record in slices so a huge one cannot starve the loop. */
    private static function drain(TarReader $reader): \Generator
    {
        while (!$reader->discard(self::CHUNK)) {
            yield null;
        }
    }

    /**
     * @param \Closure(int): string $read
     * @param \Closure(): bool $eof
     * @param array{files:int,bytes:int,skipped:int} $stats
     */
    private static function copyBody(\Closure $read, \Closure $eof, string $target, int $mtime, array &$stats): \Generator
    {
        self::makeDir(dirname($target));
        $out = @fopen($target, 'wb');
        if ($out === false) {
            throw new \RuntimeException('cannot write staged file');
        }
        try {
            while (!$eof()) {
                $chunk = $read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                $stats['bytes'] += strlen($chunk);
                if ($stats['bytes'] > Archive::maxExtract()) {
                    throw new \RuntimeException('extraction exceeds the decompression size limit');
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('short write while extracting');
                }
                yield null;
            }
        } finally {
            fclose($out);
        }
        @chmod($target, 0644);
        if ($mtime > 0) {
            @touch($target, $mtime);
        }
        $stats['files']++;
        yield null;
    }

    private static function makeDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create staged directory (name conflict)');
        }
    }
}
