<?php

declare(strict_types=1);

namespace SugarCraft\Code\Memory;

use SugarCraft\Code\Context\Compaction\StateSummaryTemplate;

/**
 * An append-only, tagged journal of the summaries compactions write (roadmap
 * 5.4-1, nanobot's history journal).
 *
 * A compaction summary is the most considered account of a session the model
 * ever writes — what was asked, what was done, which files, what was decided —
 * and until now it lived only inside the transcript it compacted, gone from
 * view the moment that session was closed. The journal keeps every one, so a
 * later pass (the dream pass, 5.4-3) can fold what keeps coming back into
 * durable memory.
 *
 * ONE FILE PER PROJECT under the home memory directory,
 * `~/.candy-code/memory/.compaction-journal-<project key>.jsonl` (`shared`
 * for a launch with no root), beside the auto-memory throttle — never inside
 * the repository, which is the user's checkout. One JSON object per line:
 *
 *     {"v":1,"at":"…","tags":["compaction","manual"],"project":"<key>",
 *      "compaction":"<id>","records":["…"],"state":"…"}
 *
 * `tags` always opens with {@see TAG} and then names the trigger (`manual` for
 * `/compact`, `auto` for the automatic tier). Secrets are redacted before
 * anything reaches the disk ({@see SecretRedactor}). The file is created
 * owner-only and appended under an exclusive lock, so two sessions of one
 * project never interleave a line.
 *
 * The file is append-only to its writers and consumable by its reader: the
 * dream pass keeps a byte cursor and trims the prefix it has folded
 * ({@see scanFrom()}, {@see trimBefore()}), so what sits on disk is the work
 * not yet read, never the whole log of the project (ISS-071).
 *
 * Journalling never fails a compaction: {@see append()} answers false on any
 * I/O failure and the summary lands in the transcript regardless.
 */
final class CompactionJournal
{
    /** The tag every entry carries first. */
    public const TAG = 'compaction';

    /** The journal's format version, written on every line. */
    public const VERSION = 1;

    /** A record longer than this, in characters, is cut — the journal is a digest, not a transcript. */
    public const MAX_RECORD_CHARS = 4000;

    /** How many of the newest entries the model-facing {@see recentView()} may offer. */
    public const VIEW_MAX_ENTRIES = 10;

    /** Total content bytes (records + state) the whole {@see recentView()} may carry. */
    public const VIEW_MAX_BYTES = 6000;

    /** Below this share an entry's text is dropped rather than clipped to noise. */
    private const VIEW_MIN_CLIP_BYTES = 96;

    private function __construct(
        private readonly string $path,
        private readonly ?string $projectKey,
    ) {
    }

    /** The journal at $path, its entries tagged with $projectKey. */
    public static function at(string $path, ?string $projectKey = null): self
    {
        return new self($path, $projectKey);
    }

    /** The journal of $home's project, beside its other home-only state. */
    public static function forStore(MemoryStore $home): self
    {
        return new self(
            $home->path() . '/.compaction-journal-' . ($home->projectKey() ?? 'shared') . '.jsonl',
            $home->projectKey(),
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Append one compaction's summaries: the per-exchange records and, when
     * the map carries one, the state block
     * ({@see StateSummaryTemplate::SUMMARY_KEY}). An empty map writes nothing.
     *
     * @param array<string, string> $summaries the landing's summary map
     * @param list<string>          $tags      further tags after {@see TAG}
     */
    public function append(string $compactionId, array $summaries, array $tags = []): bool
    {
        $state = $summaries[StateSummaryTemplate::SUMMARY_KEY] ?? null;
        unset($summaries[StateSummaryTemplate::SUMMARY_KEY]);
        $redactor = SecretRedactor::new();
        $records = [];
        foreach ($summaries as $record) {
            $record = trim($redactor->redact((string) $record));
            if ($record !== '') {
                $records[] = mb_strlen($record) > self::MAX_RECORD_CHARS
                    ? mb_substr($record, 0, self::MAX_RECORD_CHARS - 1) . '…'
                    : $record;
            }
        }
        $state = is_string($state) && trim($state) !== '' ? trim($redactor->redact($state)) : null;
        if ($records === [] && $state === null) {
            return false;
        }

        $line = json_encode([
            'v' => self::VERSION,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'tags' => array_values(array_unique([self::TAG, ...array_map('strval', $tags)])),
            'project' => $this->projectKey,
            'compaction' => $compactionId,
            'records' => $records,
            'state' => $state,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return false;
        }

        return $this->write($line . "\n");
    }

    /**
     * The journal's entries, oldest first — those carrying $tag when one is
     * given, the newest $limit of them when $limit is positive. A line that is
     * not one of ours is skipped, never fatal: the file is the user's to read
     * and edit.
     *
     * @return list<array{v:int,at:string,tags:list<string>,project:?string,compaction:string,records:list<string>,state:?string}>
     */
    public function entries(?string $tag = null, int $limit = 0): array
    {
        $raw = is_file($this->path) ? @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        $entries = [];
        foreach ($raw === false ? [] : $raw as $line) {
            $entry = self::decodeLine((string) $line);
            if ($entry === null) {
                continue;
            }
            if ($tag !== null && !in_array($tag, $entry['tags'], true)) {
                continue;
            }
            $entries[] = $entry;
        }

        return $limit > 0 ? array_slice($entries, -$limit) : $entries;
    }

    /**
     * The model-facing read view (C-CTX-3.d): at most the newest $maxEntries
     * compaction entries, and no more than $maxBytes of record/state text in
     * TOTAL, oldest-first. The dream pass reads full-fidelity entries; a
     * recall answer handed to the model must cost a bounded number of prompt
     * bytes, so when the budget runs out the entry it lands on is clipped to
     * equal shares (never to noise: a share below {@see VIEW_MIN_CLIP_BYTES}
     * drops the text instead), and older entries past the budget are left on
     * disk. Entries are already redacted at append; this view only reads.
     *
     * @return list<array{v:int,at:string,tags:list<string>,project:?string,compaction:string,records:list<string>,state:?string}>
     */
    public function recentView(int $maxEntries = self::VIEW_MAX_ENTRIES, int $maxBytes = self::VIEW_MAX_BYTES): array
    {
        $budget = max(0, $maxBytes);
        if ($budget === 0 || $maxEntries <= 0) {
            return [];
        }

        // Walk newest-first so the budget trims the OLDEST entries first:
        // the freshest summary is the one a recall most needs.
        $picked = [];
        foreach (array_reverse($this->entries(self::TAG, $maxEntries)) as $entry) {
            $used = self::entryTextBytes($entry);
            if ($used <= $budget) {
                $picked[] = $entry;
                $budget -= $used;
                continue;
            }
            if ($budget < self::VIEW_MIN_CLIP_BYTES) {
                break;
            }
            $clipped = self::clipEntryTo($entry, $budget);
            if ($clipped['records'] !== [] || $clipped['state'] !== null) {
                $picked[] = $clipped;
            }
            break;
        }

        return array_reverse($picked);
    }

    /** Content bytes one entry carries: every record plus the state, if any. */
    private static function entryTextBytes(array $entry): int
    {
        $bytes = 0;
        foreach ($entry['records'] as $record) {
            $bytes += strlen($record);
        }

        return $bytes + strlen((string) $entry['state']);
    }

    /**
     * $entry with its record/state texts clipped to $budget bytes TOTAL,
     * each share equal and each share at least {@see VIEW_MIN_CLIP_BYTES} —
     * a smaller share is dropped, never squeezed into a one-word stub.
     *
     * @param array{v:int,at:string,tags:list<string>,project:?string,compaction:string,records:list<string>,state:?string} $entry
     *
     * @return array{v:int,at:string,tags:list<string>,project:?string,compaction:string,records:list<string>,state:?string}
     */
    private static function clipEntryTo(array $entry, int $budget): array
    {
        $count = count($entry['records']) + ($entry['state'] === null ? 0 : 1);
        $share = $count === 0 ? 0 : intdiv($budget, $count);
        if ($share < self::VIEW_MIN_CLIP_BYTES) {
            $entry['records'] = [];
            $entry['state'] = null;

            return $entry;
        }

        $entry['records'] = array_values(array_map(
            static fn(string $record): string => self::clipBytes($record, $share),
            $entry['records'],
        ));
        if ($entry['state'] !== null) {
            $entry['state'] = self::clipBytes($entry['state'], $share);
        }

        return $entry;
    }

    /** $text to at most $limit bytes, cut on a UTF-8 boundary and marked. */
    private static function clipBytes(string $text, int $limit): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }
        if ($limit <= 3) {
            return '';
        }

        return mb_strcut($text, 0, $limit - 3, 'UTF-8') . '…';
    }

    /**
     * Read the journal from a byte offset rather than from its start (ISS-071).
     * The offset is a consumed-until cursor: everything before it has been
     * folded, only the tail still describes work. ends[k] is the absolute byte
     * just past entries[k]'s newline — the offset a later read resumes from —
     * so a consumer that takes a prefix of the entries learns exactly how far
     * consumption reached. aligned is false when $offset cannot describe a
     * position in this file (past its end, or resting mid-line); the caller
     * then resyncs from zero. A trailing line without its newline is a half
     * finished append: it is never returned, and the cursor stays before it.
     *
     * @return array{entries: list<array{v:int,at:string,tags:list<string>,project:?string,compaction:string,records:list<string>,state:?string}>, ends: list<int>, aligned: bool, size: int}
     */
    public function scanFrom(int $offset = 0): array
    {
        if (!is_file($this->path)) {
            return ['entries' => [], 'ends' => [], 'aligned' => true, 'size' => 0];
        }
        clearstatcache(true, $this->path);
        $size = filesize($this->path);
        if ($size === false) {
            return ['entries' => [], 'ends' => [], 'aligned' => false, 'size' => 0];
        }
        if ($offset < 0 || $offset > $size) {
            return ['entries' => [], 'ends' => [], 'aligned' => false, 'size' => $size];
        }
        if ($offset > 0) {
            // Line boundaries only: the byte before the cursor must be a newline.
            $probe = @file_get_contents($this->path, false, null, $offset - 1, 1);
            if ($probe !== "\n") {
                return ['entries' => [], 'ends' => [], 'aligned' => false, 'size' => $size];
            }
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return ['entries' => [], 'ends' => [], 'aligned' => false, 'size' => $size];
        }
        $entries = [];
        $ends = [];
        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return ['entries' => [], 'ends' => [], 'aligned' => false, 'size' => $size];
            }
            $position = $offset;
            while (($line = fgets($handle)) !== false) {
                $length = strlen($line);
                if ($line[$length - 1] !== "\n") {
                    break; // incomplete trailing append — leave the cursor before it
                }
                $position += $length;
                $entry = self::decodeLine(rtrim($line, "\r\n"));
                if ($entry === null) {
                    continue;
                }
                $entries[] = $entry;
                $ends[] = $position;
            }
        } finally {
            fclose($handle);
        }

        return ['entries' => $entries, 'ends' => $ends, 'aligned' => true, 'size' => $size];
    }

    /**
     * Drop the journal's first $offset bytes — the entries a consumer has
     * provably folded — keeping any unread tail (ISS-071). Without this the
     * journal grows for the life of the project and every fold re-reads all
     * history. Returns false when the trim could not be trusted (the file
     * shrank or was replaced under the lock, a lock or write failure); the
     * caller then keeps its byte cursor and loses nothing but space.
     */
    public function trimBefore(int $offset): bool
    {
        if ($offset <= 0) {
            return true;
        }
        if (!is_file($this->path)) {
            return false;
        }
        $handle = @fopen($this->path, 'r+b');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            try {
                $stat = fstat($handle);
                $size = is_array($stat) ? ($stat['size'] ?? -1) : -1;
                if ($size < $offset) {
                    return false;
                }
                if ($size === $offset) {
                    return @ftruncate($handle, 0);
                }
                if (fseek($handle, $offset) !== 0) {
                    return false;
                }
                $tail = stream_get_contents($handle);
                if ($tail === false) {
                    return false;
                }
                if (rewind($handle) !== true || !@ftruncate($handle, 0)) {
                    return false;
                }
                $written = fwrite($handle, $tail);
                fflush($handle);

                return $written === strlen($tail);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * One journal line to its canonical entry, or null when the line is not
     * one of ours. The file is the user's to read and edit, so foreign lines
     * are skipped, never fatal.
     *
     * @return ?array{v:int,at:string,tags:list<string>,project:?string,compaction:string,records:list<string>,state:?string}
     */
    private static function decodeLine(string $line): ?array
    {
        $entry = json_decode($line, true);
        if (!is_array($entry) || !is_array($entry['tags'] ?? null) || !is_array($entry['records'] ?? null)
            || !is_string($entry['compaction'] ?? null) || !is_string($entry['at'] ?? null)) {
            return null;
        }

        return [
            'v' => is_int($entry['v'] ?? null) ? $entry['v'] : self::VERSION,
            'at' => $entry['at'],
            'tags' => array_values(array_filter($entry['tags'], 'is_string')),
            'project' => is_string($entry['project'] ?? null) ? $entry['project'] : null,
            'compaction' => $entry['compaction'],
            'records' => array_values(array_filter($entry['records'], 'is_string')),
            'state' => is_string($entry['state'] ?? null) ? $entry['state'] : null,
        ];
    }

    private function write(string $line): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return false;
        }
        $isNew = !is_file($this->path);
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            return false;
        }
        try {
            if ($isNew) {
                @chmod($this->path, 0o600);
            }
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $written = fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $written === strlen($line);
        } finally {
            fclose($handle);
        }
    }
}
