<?php

declare(strict_types=1);

namespace SugarCraft\Code\Memory;

/**
 * Per-process memo of decoded memory notes (AUD §7 rank 40).
 *
 * {@see \SugarCraft\Code\Context\MemoryBlock::capture()} and
 * {@see \SugarCraft\Code\Context\MemoryRecallBlock::capture()} both list the
 * stores, and every listing ran {@see MemoryStore}'s YAML decode on every note
 * file, once per block per turn. The memo keeps the decoded outcome of a note
 * (its entry, or the reason it was refused) and hands it back while the note
 * has not moved.
 *
 * KEYED BY THE BYTES, NOT BY A STAT, for the reason
 * {@see \SugarCraft\Code\Config\MergedConfigMemo} gives: a same-second,
 * same-size hand edit leaves mtime and size untouched, and a memo blind to it
 * would serve the old note for the life of the process. The caller still pays
 * the small read of the file and saves the decode. The key is every input the
 * decode reads: the path (the id is its stem), the file's mtime (a note with
 * no recorded dates is dated by it), and a digest of the bytes.
 *
 * STATIC, NOT PER STORE: a project's repository store is rebuilt by
 * {@see \SugarCraft\Code\Context\ProjectMemoryWriter::forRoot()} on each call,
 * so a memo held by the instance would be empty at every read. The engine
 * forks its turn child from the parent, which primes recall there, so the
 * child inherits what the parent decoded.
 *
 * BOUNDED in the keep-the-newer-half shape of
 * {@see \SugarCraft\Code\Context\MemoryBlock}'s tracked-note memo: a
 * long-lived TUI that consults many stores never grows it without limit.
 * Store writes drop their row explicitly through {@see forget()}, which the
 * byte key makes redundant for correctness and costs nothing.
 */
final class NoteParseMemo
{
    /** Rows held before the older half is evicted. */
    public const MAX_ENTRIES = 4096;

    /**
     * path => [key, decoded entry or refusal reason]
     *
     * @var array<string, array{0: array{0: int, 1: string}, 1: MemoryEntry|string}>
     */
    private static array $rows = [];

    /** How many times a note was decoded: the test seam for "it did not re-parse". */
    private static int $decodes = 0;

    private function __construct()
    {
    }

    /**
     * The decoded note at $file, from the memo when its mtime and bytes match
     * the last decode, else from $decode (which returns the entry or throws).
     *
     * A thrown refusal is memoised as its message and rethrown as an
     * {@see \UnexpectedValueException} on a hit, so the caller's catch sees
     * the same reason either way. A note whose mtime cannot be read is never
     * memoised: its fallback date is the current time, not a function of the
     * file.
     *
     * @param \Closure(): MemoryEntry $decode
     * @throws \Throwable whatever $decode throws, or the memoised refusal
     */
    public static function decode(string $file, int|false $mtime, string $raw, \Closure $decode): MemoryEntry
    {
        if ($mtime === false) {
            self::$decodes++;

            return $decode();
        }

        $key = [$mtime, hash('xxh128', $raw) . ':' . \strlen($raw)];
        $row = self::$rows[$file] ?? null;
        if ($row !== null && $row[0] === $key) {
            if (\is_string($row[1])) {
                throw new \UnexpectedValueException($row[1]);
            }

            return $row[1];
        }

        self::$decodes++;
        try {
            $entry = $decode();
        } catch (\Throwable $e) {
            self::remember($file, $key, $e->getMessage());

            throw $e;
        }
        self::remember($file, $key, $entry);

        return $entry;
    }

    /** Drop $file's row; the next read decodes it again. */
    public static function forget(string $file): void
    {
        unset(self::$rows[$file]);
    }

    /** Decodes since process start (or the last {@see resetForTesting()}). */
    public static function decodes(): int
    {
        return self::$decodes;
    }

    /** Rows currently held. */
    public static function size(): int
    {
        return \count(self::$rows);
    }

    /** A fresh process's memo: the test seam. */
    public static function resetForTesting(): void
    {
        self::$rows = [];
        self::$decodes = 0;
    }

    /**
     * @param array{0: int, 1: string} $key
     */
    private static function remember(string $file, array $key, MemoryEntry|string $value): void
    {
        unset(self::$rows[$file]);
        if (\count(self::$rows) >= self::MAX_ENTRIES) {
            self::$rows = \array_slice(self::$rows, self::MAX_ENTRIES >> 1, null, true);
        }
        self::$rows[$file] = [$key, $value];
    }
}
