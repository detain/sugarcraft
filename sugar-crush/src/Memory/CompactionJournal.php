<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;

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
 * `~/.sugar-crush/memory/.compaction-journal-<project key>.jsonl` (`shared`
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
            $entry = json_decode($line, true);
            if (!is_array($entry) || !is_array($entry['tags'] ?? null) || !is_array($entry['records'] ?? null)
                || !is_string($entry['compaction'] ?? null) || !is_string($entry['at'] ?? null)) {
                continue;
            }
            if ($tag !== null && !in_array($tag, $entry['tags'], true)) {
                continue;
            }
            $entries[] = [
                'v' => is_int($entry['v'] ?? null) ? $entry['v'] : self::VERSION,
                'at' => $entry['at'],
                'tags' => array_values(array_filter($entry['tags'], 'is_string')),
                'project' => is_string($entry['project'] ?? null) ? $entry['project'] : null,
                'compaction' => $entry['compaction'],
                'records' => array_values(array_filter($entry['records'], 'is_string')),
                'state' => is_string($entry['state'] ?? null) ? $entry['state'] : null,
            ];
        }

        return $limit > 0 ? array_slice($entries, -$limit) : $entries;
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
