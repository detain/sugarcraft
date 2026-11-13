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

    /** unix seconds */
    public readonly int $time;
    public readonly string $project;
    public readonly string $language;
    public readonly string $file;
    /** seconds attributed to this beat */
    public readonly int $duration;
    /** @var array<string> optional labels for this activity beat */
    public readonly array $tags;

    /**
     * @param array<string> $tags  optional labels for this activity beat
     * @throws \InvalidArgumentException  if time or duration is negative
     */
    public function __construct(
        int $time,
        string $project,
        string $language,
        string $file,
        int $duration = 60,
        array $tags = [],
    ) {
        if ($time < 0) {
            throw new \InvalidArgumentException('Heartbeat time must be non-negative');
        }
        if ($duration < 0) {
            throw new \InvalidArgumentException('Heartbeat duration must be non-negative');
        }
        // CT-S15: the MAX_* constants are a law of the object, not a courtesy
        // of the read path — direct construction meets the same clamp fromArray
        // applies, so the write bound equals the read bound at every door.
        $this->time       = min(self::MAX_TIME_SECONDS, $time);
        $this->duration   = min(self::MAX_DURATION_SECONDS, $duration);
        $this->project    = $project;
        $this->language   = $language;
        $this->file       = $file;
        $this->tags       = $tags;
    }

    /**
     * Hydrate one stored row (JSONL line or sqlite record).
     *
     * The text legs are coerced, not validated: this data already passed
     * through a disk or a socket, and a single odd string must never brick
     * every later read of the day (the review's poisoned-row DoS). `time`
     * alone is required, and negative or oversized numerics meet the write
     * door's clamp (CT-S15): a stored row with no usable timestamp is a
     * corrupt record, not a live beat — hydrating it with wall-clock NOW
     * ghosts dead storage through every report. Doors that mean "now" say so
     * at capture (see the push stdin arm); readers never invent time.
     *
     * @param array<string,mixed> $row  One decoded JSONL line (or sqlite row)
     * @throws \InvalidArgumentException  if the row carries no usable time
     */
    public static function fromArray(array $row): self
    {
        $tags = $row['tags'] ?? [];
        if (is_array($tags)) {
            $tags = array_values(array_filter($tags, 'is_string'));
        } else {
            $tags = [];
        }

        if (!array_key_exists('time', $row) || !is_numeric($row['time'])) {
            throw new \InvalidArgumentException('Heartbeat row carries no usable time');
        }

        // Negative numerics are coerced to zero here (the poisoned-row DoS
        // lane), while the constructor throws — write doors refuse, read
        // doors recover, and the upper bounds are the constructor's clamp.
        return new self(
            time:     max(0, (int) $row['time']),
            project:  (string)($row['project']  ?? 'unknown'),
            language: (string)($row['language'] ?? 'unknown'),
            file:     (string)($row['file']     ?? ''),
            duration: max(0, (int)($row['duration'] ?? 60)),
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
