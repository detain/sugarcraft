<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\Support\LineIo;

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
        // dies here, by name, instead of haunting the streamed reader.
        if ($maxExpandedBytes < 1) {
            throw new \InvalidArgumentException(
                "maxExpandedBytes must be at least 1 byte, got {$maxExpandedBytes}",
            );
        }
    }

    public function write(Cassette $cassette, string $path): void
    {
        if (!self::isGzipPath($path)) {
            // Write as plain JSONL — same atomic streaming placement (the
            // whole-body string copy this path used to make is finding 081
            // territory: inner.format now streams line by line).
            $this->inner->write($cassette, $path);
            return;
        }

        try {
            // gzip into a reserved temp and rename (finding 018): the old
            // direct gzopen meant a stream that died mid-deflate left a
            // corrupt archive in place of the last good recording. Lines
            // deflate as they are produced instead of after a full encode
            // (finding 081).
            AtomicFileWriter::writeGzippedStream(
                $path,
                function ($gz) use ($cassette, $path): void {
                    foreach ($this->inner->encodeLines($cassette) as $line) {
                        if (@gzwrite($gz, $line . "\n") === false) {
                            throw new \RuntimeException("cannot write gzip cassette bytes to temporary file for {$path}");
                        }
                    }
                },
                $this->compressionLevel,
            );
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("cannot open gzip stream for {$path}: {$e->getMessage()}", previous: $e);
        }
    }

    public function read(string $path): Cassette
    {
        if (!self::isGzipPath($path)) {
            return $this->inner->read($path);
        }

        // Streamed decompression with the expansion ceiling (finding 012)
        // now feeding the line decoder directly (finding 081): the old
        // shape held the full decompressed body AND the split lines AND
        // the decoded cassette at once.
        return $this->inner->decodeTuples(
            LineIo::gzLines($path, "cannot read cassette from {$path}", $this->maxExpandedBytes),
        );
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

}
