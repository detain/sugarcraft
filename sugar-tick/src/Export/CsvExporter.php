<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Exports heartbeats as CSV with comma-separated values.
 */
final class CsvExporter implements ExporterInterface
{
    /** @return list<string> */
    public function headers(): array
    {
        return ['time', 'project', 'language', 'file', 'duration', 'tags'];
    }

    /**
     * Neutralize formula-injection prefixes in a cell value.
     */
    private static function safeCell(string $v): string
    {
        if ($v === '') {
            return $v;
        }
        $first = $v[0];
        if (in_array($first, ['=', '+', '-', '@', "\t"], true)) {
            return "'" . $v;
        }
        if (str_starts_with($v, "\r")) {
            return "'" . $v;
        }
        return $v;
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
                self::safeCell($hb->project),
                self::safeCell($hb->language),
                self::safeCell($hb->file),
                $hb->duration,
                implode(',', $hb->tags),
            ],
            $heartbeats,
        );
    }

    /**
     * Encode heartbeats as a proper RFC-4180 CSV string.
     *
     * @param array<Heartbeat> $heartbeats
     */
    public function encode(array $heartbeats): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $this->headers());
        foreach ($this->rows($heartbeats) as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
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
