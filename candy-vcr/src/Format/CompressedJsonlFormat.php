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
    use RestrictsCassettePermissions;
    private const DEFAULT_COMPRESSION_LEVEL = 6;

    /**
     * Ceiling on the TOTAL DECOMPRESSED bytes a `.gz` cassette may expand to
     * (finding 012). A few KB of gzip can inflate to gigabytes — the gzread
     * loop used to concat until the process died, and file size says nothing
     * about expanded size (that is the whole point of compression).
     *
     * Deliberately wider than {@see \SugarCraft\Vcr\Tape\Lexer::MAX_TAPE_BYTES}
     * (8 MiB): a cassette is a RECORDING, its event count is not bounded by
     * the tape door's budget, and plain JSONL has no read cap at all — the
     * 64 MiB seat bounds the AMPLIFICATION damage (a 1000x bomb dies at a
     * named error with ~64 MiB of peak memory, not at an unbounded slurp)
     * without rejecting honest long-session cassettes. The ceiling is a
     * constructor option so callers with tighter budgets (and tests) can
     * lower the door; it is not a law of the format itself.
     */
    public const MAX_EXPANDED_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly JsonlFormat $inner = new JsonlFormat(),
        private readonly int $compressionLevel = self::DEFAULT_COMPRESSION_LEVEL,
        private readonly int $maxExpandedBytes = self::MAX_EXPANDED_BYTES,
    ) {
        // Fail fast at the boundary: a zero/negative ceiling would either
        // reject every cassette or let the guard arithmetic wrap — nonsense
        // dies here, by name, instead of haunting readAll().
        if ($maxExpandedBytes < 1) {
            throw new \InvalidArgumentException(
                "maxExpandedBytes must be at least 1 byte, got {$maxExpandedBytes}",
            );
        }
    }

    public function write(Cassette $cassette, string $path): void
    {
        $contents = $this->encode($cassette);

        if (!self::isGzipPath($path)) {
            // Write as plain JSONL
            if (@file_put_contents($path, $contents) === false) {
                throw new \RuntimeException("cannot write cassette to {$path}");
            }
            self::restrictToOwner($path);
            return;
        }

        $gz = @gzopen($path, 'wb' . $this->compressionLevel);
        if ($gz === false) {
            throw new \RuntimeException("cannot open gzip stream for {$path}");
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
                    throw new \RuntimeException("gzip write failed for {$path}");
                }
            }
        } finally {
            gzclose($gz);
        }
        // .gz cassettes are cassettes too: owner-readable only (see write()).
        self::restrictToOwner($path);
    }

    public function read(string $path): Cassette
    {
        $raw = $this->readAll($path);
        if ($raw === false) {
            throw new \RuntimeException("cannot read cassette from {$path}");
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
                // The expansion ceiling: judge BEFORE the concat so the
                // over-cap bytes never join the live string, then name the
                // door the bomb died at (finding 012).
                if (strlen($contents) + strlen($chunk) > $this->maxExpandedBytes) {
                    throw new \RuntimeException(
                        'gzip cassette ' . $path . ' expands beyond ' . $this->maxExpandedBytes
                        . ' byte cap - refusing to decompress further',
                    );
                }
                $contents .= $chunk;
            }
            return $contents;
        } finally {
            gzclose($gz);
        }
    }
}
