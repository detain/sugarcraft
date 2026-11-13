<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Support;

/**
 * Streaming line reader for cassette-format decoders (finding 081).
 *
 * The whole-file shape — `file_get_contents()` followed by `preg_split()`
 * over the entire body — holds three full-size copies of a recording alive
 * at once (raw string, split array, decoded objects), so peak memory of a
 * plain-JSONL load was ~3× the file before this reader. Yielding one line
 * at a time keeps the process footprint linear in the number of DECODED
 * events (which the Cassette legitimately owns) instead of tripling the
 * file's own bytes on top of them.
 *
 * The yielded shape is a tuple `[lineNumber, text, isTornLast]`:
 * - `lineNumber` counts EVERY physical line — blanks included — so error
 *   text like `invalid JSON on line {$n}` matches what the split-array
 *   shape named for the same file (the formats skip blank lines but must
 *   not renumber the ones after them).
 * - `text` carries no trailing newline; a `\r\n` end is stripped as one
 *   boundary, exactly like `preg_split('/\r?\n/')` (a lone `\r` mid-line
 *   is content and stays).
 * - `isTornLast` is true only on the final emitted line, only when the
 *   body ends without a newline: the kill -9 signature the formats drop
 *   (an empty file, or one ending on `\n`, emits nothing torn).
 */
final class LineIo
{
    /** Chunk size for the gzip reader; a line may straddle chunk edges. */
    private const GZ_CHUNK_BYTES = 8192;

    private function __construct()
    {
    }

    /**
     * Stream the lines of a plain file.
     *
     * @param string $path File to open.
     * @param string $failureMessage Exception text when the path cannot be opened.
     * @return \Generator<int, array{0: int, 1: string, 2: bool}>
     * @throws \RuntimeException If the path cannot be opened.
     */
    public static function lines(string $path, string $failureMessage): \Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException($failureMessage);
        }

        try {
            $lineNo = 0;
            while (($raw = fgets($handle)) !== false) {
                $lineNo++;
                yield [$lineNo, self::stripEol($raw), feof($handle) && !str_ends_with($raw, "\n")];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stream the lines of a gzip file, with a ceiling on the TOTAL
     * DECOMPRESSED bytes (the amplification guard shared with the old
     * whole-buffer reader: a few KB of gzip can expand to gigabytes, and
     * the door must die with a named error, not with the process).
     *
     * @param string $path Gzip file to open.
     * @param string $failureMessage Exception text when the stream cannot be opened.
     * @param int $maxExpandedBytes Hard ceiling on decompressed bytes.
     * @return \Generator<int, array{0: int, 1: string, 2: bool}>
     * @throws \RuntimeException If the stream cannot be opened.
     * @throws \RuntimeException If the body expands past $maxExpandedBytes.
     */
    public static function gzLines(string $path, string $failureMessage, int $maxExpandedBytes): \Generator
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException($failureMessage);
        }

        try {
            $expanded = 0;
            $buffer = '';
            $lineNo = 0;
            while (!gzeof($gz)) {
                $chunk = gzread($gz, self::GZ_CHUNK_BYTES);
                if ($chunk === false) {
                    // Read error mid-stream: the torn-tail contract never
                    // forgives a corrupt gzip CRC, so surface the open-style
                    // failure rather than yielding a partial final line.
                    throw new \RuntimeException($failureMessage);
                }
                if ($chunk === '') {
                    break;
                }
                $expanded += strlen($chunk);
                if ($expanded > $maxExpandedBytes) {
                    throw new \RuntimeException(
                        'gzip cassette ' . $path . ' expands beyond ' . $maxExpandedBytes
                        . ' byte cap - refusing to decompress further',
                    );
                }
                $buffer .= $chunk;
                $cut = strrpos($buffer, "\n");
                if ($cut === false) {
                    continue;
                }
                $block = substr($buffer, 0, $cut + 1);
                $buffer = substr($buffer, $cut + 1);
                foreach (self::blockTuples($block, $lineNo) as $tuple) {
                    yield $tuple;
                }
            }
            if ($buffer !== '') {
                $lineNo++;
                yield [$lineNo, $buffer, true];
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * Turn a byte block that ends on "\n" into tuples, advancing the shared
     * counter in place. The block's final "" element (the phantom after the
     * last newline) is dropped — exactly the tail the split-array shape
     * skips as an empty line, and never needed for numbering because it is
     * always the last element.
     *
     * @return \Generator<int, array{0: int, 1: string, 2: bool}>
     */
    private static function blockTuples(string $block, int &$lineNo): \Generator
    {
        $segments = explode("\n", $block);
        array_pop($segments);
        foreach ($segments as $segment) {
            $lineNo++;
            yield [$lineNo, self::stripEol($segment . "\n"), false];
        }
    }

    private static function stripEol(string $line): string
    {
        if (str_ends_with($line, "\n")) {
            $line = substr($line, 0, -1);
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
        }
        return $line;
    }
}
