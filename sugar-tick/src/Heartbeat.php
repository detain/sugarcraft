<?php

declare(strict_types=1);

namespace CandyCore\Tick;

/**
 * One activity sample. The editor plug-ins POST these via a tiny
 * shell-out — they're the natural unit of storage in the JSONL
 * activity log.
 */
final class Heartbeat
{
    public function __construct(
        public readonly int $time,        // unix seconds
        public readonly string $project,
        public readonly string $language,
        public readonly string $file,
        public readonly int $duration = 60,  // seconds attributed to this beat
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            time:     (int)   ($row['time']     ?? time()),
            project:  (string)($row['project']  ?? 'unknown'),
            language: (string)($row['language'] ?? 'unknown'),
            file:     (string)($row['file']     ?? ''),
            duration: (int)   ($row['duration'] ?? 60),
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
        ];
    }
}
