<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Exports heartbeats as CSV with comma-separated values.
 * Note: does not use fputcsv/str_getcsv for simplicity.
 */
final class CsvExporter implements ExporterInterface
{
    /** @return list<string> */
    public function headers(): array
    {
        return ['time', 'project', 'language', 'file', 'duration', 'tags'];
    }

    /**
     * @param array<Heartbeat> $heartbeats
     * @return list<list<string|int|float>>
     */
    public function rows(array $heartbeats): array
    {
        return array_map(
            static fn(Heartbeat $hb): array => [
                $hb->time,
                $hb->project,
                $hb->language,
                $hb->file,
                $hb->duration,
                implode(',', $hb->tags),
            ],
            $heartbeats,
        );
    }

    public function format(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv';
    }
}
