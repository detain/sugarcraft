<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;

/**
 * Gzip-compressed JSONL cassette format. Wraps JsonlFormat with streaming
 * gzip compression that flushes per-line to maintain streaming semantics.
 *
 * Auto-detects gzip compression when the file path ends with `.gz`.
 * Compressed cassettes are typically 5-10x smaller than plain JSONL,
 * making them suitable for CI storage and git repositories.
 *
 * Uses zlib's GZIP encoding (RFC 1952) for broad compatibility.
 * Compression level defaults to 6 (balanced speed/compression).
 */
final class CompressedJsonlFormat implements Format
{
    private const DEFAULT_COMPRESSION_LEVEL = 6;

    public function __construct(
        private readonly JsonlFormat $inner = new JsonlFormat(),
        private readonly int $compressionLevel = self::DEFAULT_COMPRESSION_LEVEL,
    ) {}

    public function write(Cassette $cassette, string $path): void
    {
        $contents = $this->encode($cassette);

        if (!self::isGzipPath($path)) {
            // Write as plain JSONL
            if (@file_put_contents($path, $contents) === false) {
                throw new \RuntimeException("candy-vcr: cannot write cassette to {$path}");
            }
            return;
        }

        $gz = @gzopen($path, 'wb' . $this->compressionLevel);
        if ($gz === false) {
            throw new \RuntimeException("candy-vcr: cannot open gzip stream for {$path}");
        }

        try {
            // Write line-by-line to maintain streaming behavior.
            // Each line is JSON-encoded separately by JsonlFormat.
            $lines = explode("\n", $contents);
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $written = gzwrite($gz, $line . "\n");
                if ($written === false) {
                    throw new \RuntimeException("candy-vcr: gzip write failed for {$path}");
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    public function read(string $path): Cassette
    {
        $raw = $this->readAll($path);
        if ($raw === false) {
            throw new \RuntimeException("candy-vcr: cannot read cassette from {$path}");
        }
        return $this->decode($raw);
    }

    public function encode(Cassette $cassette): string
    {
        return $this->inner->encode($cassette);
    }

    public function decode(string $contents): Cassette
    {
        return $this->inner->decode($contents);
    }

    /**
     * Check if a path should be treated as gzip based on its extension.
     */
    public static function isGzipPath(string $path): bool
    {
        return str_ends_with($path, '.gz');
    }

    /**
     * Read entire gzip file into a string.
     *
     * @return string|false The decompressed contents, or false on failure.
     */
    private function readAll(string $path): string|false
    {
        if (!self::isGzipPath($path)) {
            return @file_get_contents($path);
        }

        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            return false;
        }

        try {
            $contents = '';
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 8192);
                if ($chunk === false) {
                    return false;
                }
                $contents .= $chunk;
            }
            return $contents;
        } finally {
            gzclose($gz);
        }
    }
}
