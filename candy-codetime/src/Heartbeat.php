<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

/**
 * One activity sample. Editor plug-ins record these by shelling out
 * to the local `candy-codetime push` command — they're the natural
 * unit of storage in the JSONL activity log.
 */
final class Heartbeat
{
    /**
     * Upper bound for a single beat's duration: one year of seconds. The
     * `push` argument door clamps longer durations down to it (it refuses
     * only non-numeric values), and {@see self::fromArray()} clamps stored
     * rows to the same window — read and write must speak one
     * bound or a hand-echoed poison line (near PHP_INT_MAX) sails past the
     * writer's clamp and detonates downstream arithmetic as a TypeError inside
     * Stats::timeline()/IcalExporter, killing every launch that reads the file.
     */
    public const MAX_DURATION_SECONDS = 31_536_000;

    /**
     * Upper bound for a stored timestamp: 2100-01-01 00:00:00 UTC. Beats far in
     * the future are storage poison by construction (a real beat cannot carry
     * a date the clock has not reached); past the epoch ceiling of date()/
     * DateTime on 64-bit builds a max-int row renders "out of range" fatals in
     * every report path that formats it. Same law as the duration bound: the
     * read path re-clamps to what the write path could ever have stored.
     */
    public const MAX_TIME_SECONDS = 4_102_444_800;

    /**
     * @param array<string> $tags  optional labels for this activity beat
     * @throws \InvalidArgumentException  if time or duration is negative
     */
    public function __construct(
        public readonly int $time,        // unix seconds
        public readonly string $project,
        public readonly string $language,
        public readonly string $file,
        public readonly int $duration = 60,  // seconds attributed to this beat
        public readonly array $tags = [],
    ) {
        if ($time < 0) {
            throw new \InvalidArgumentException('Heartbeat time must be non-negative');
        }
        if ($duration < 0) {
            throw new \InvalidArgumentException('Heartbeat duration must be non-negative');
        }
    }

    /**
     * Hydrate one stored row (JSONL line or sqlite record).
     *
     * Fields are COERCED, not validated: this data already passed through a
     * disk or a socket, and a single malformed row must never brick every
     * later read of the day (the review's poisoned-row DoS). The numeric legs
     * re-apply exactly the window the write path enforces — see
     * {@see self::MAX_DURATION_SECONDS} / {@see self::MAX_TIME_SECONDS} — so
     * the reader cannot re-widen what the writer clamped.
     *
     * @param array<string,mixed> $row  One decoded JSONL line (or sqlite row)
     */
    public static function fromArray(array $row): self
    {
        $tags = $row['tags'] ?? [];
        if (is_array($tags)) {
            $tags = array_values(array_filter($tags, 'is_string'));
        } else {
            $tags = [];
        }

        return new self(
            time:     min(self::MAX_TIME_SECONDS, max(0, (int)   ($row['time']     ?? time()))),
            project:  (string)($row['project']  ?? 'unknown'),
            language: (string)($row['language'] ?? 'unknown'),
            file:     (string)($row['file']     ?? ''),
            duration: min(self::MAX_DURATION_SECONDS, max(0, (int)($row['duration'] ?? 60))),
            tags:     $tags,
        );
    }

    public function toArray(): array
    {
        return [
            'time'     => $this->time,
            'project'  => $this->project,
            'language' => $this->language,
            'file'     => $this->file,
            'duration' => $this->duration,
            'tags'     => $this->tags,
        ];
    }

    /** @param array<string> $tags */
    public function withTags(array $tags): self
    {
        return new self(
            time: $this->time,
            project: $this->project,
            language: $this->language,
            file: $this->file,
            duration: $this->duration,
            tags: $tags,
        );
    }
}
