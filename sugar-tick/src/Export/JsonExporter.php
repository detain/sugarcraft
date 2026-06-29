<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Exports heartbeats as a JSON array of objects.
 *
 * `rows()` returns positional arrays for the tabular contract.
 * `encode()` returns a JSON array of objects keyed by headers().
 */
final class JsonExporter implements TabularExporterInterface
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
                $hb->tags,
            ],
            $heartbeats,
        );
    }

    /**
     * Encode heartbeats as a JSON array of objects keyed by headers().
     *
     * @param array<Heartbeat> $heartbeats
     */
    public function encode(array $heartbeats): string
    {
        $objects = array_map(
            static fn(Heartbeat $hb) => [
                'time' => $hb->time,
                'project' => $hb->project,
                'language' => $hb->language,
                'file' => $hb->file,
                'duration' => $hb->duration,
                'tags' => $hb->tags,
            ],
            $heartbeats,
        );
        return json_encode($objects, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function export(string $name, array $heartbeats): string
    {
        return $this->encode($heartbeats);
    }

    public function format(): string
    {
        return 'json';
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}
