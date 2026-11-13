<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Support;

/**
 * The byte ceilings candy-kv-073 asked for: a multi-hundred-MB document
 * used to buffer itself (and, on the symfony YAML route, several copies of
 * itself) straight into an OOM kill that bypassed every "Import failed:"
 * handler — the kernel, not the CLI, answered the oversized input.
 *
 * The ceiling is an admission gate, not a truncation: over-cap documents
 * refuse with the size and the cap in the message, and nothing is written.
 * `--max-bytes` (and the library parameter of the same intent) lifts the
 * gate for callers who genuinely mean a bigger dump; 0 means no ceiling.
 */
final class SizeCap
{
    /** Gate G10 default: 256 MiB is above every realistic personal vault. */
    public const DEFAULT_MAX_BYTES = 268_435_456;

    /** Chunk size for bounded stream reads — one buffer, refilled, never a whole-document slurp. */
    private const CHUNK_BYTES = 1_048_576;

    /**
     * Refuse a known size before touching the bytes.
     *
     * @throws \RuntimeException when $bytes exceeds the ceiling
     */
    public static function refuseOversized(?int $bytes, int $maxBytes, string $source): void
    {
        if ($maxBytes === 0) {
            return; // explicit uncapped door
        }
        if ($bytes !== null && $bytes > $maxBytes) {
            throw new \RuntimeException(
                \sprintf(
                    '%s is %d bytes, which exceeds the %d-byte size ceiling. The import doors raise it with --max-bytes (0 removes it); stored values must stay under it.',
                    $source,
                    $bytes,
                    $maxBytes,
                )
            );
        }
    }

    /**
     * Read a stream up to the ceiling, refusing the moment it grows past
     * it — an unbounded STDIN never reaches the decoder, let alone the OOM
     * killer. Returns null only on a plain read failure (the caller's door
     * owns that domain message); an over-cap stream throws right here.
     *
     * @param resource $stream
     */
    public static function readStream($stream, int $maxBytes, string $source): ?string
    {
        $payload = '';
        while (!\feof($stream)) {
            $chunk = \fread($stream, self::CHUNK_BYTES);
            if ($chunk === false) {
                return null; // read error — the caller's domain refusal answers
            }
            $payload .= $chunk;
            if ($maxBytes !== 0 && \strlen($payload) > $maxBytes) {
                self::refuseOversized(\strlen($payload), $maxBytes, $source);
            }
            if ($chunk === '') {
                break; // stalled pipe: stop growing, let EOF/timeout own it
            }
        }

        return $payload;
    }
}
