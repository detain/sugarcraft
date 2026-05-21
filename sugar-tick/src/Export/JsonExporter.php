<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Exports heartbeats as a JSON array of objects.
 */
final class JsonExporter implements ExporterInterface
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

    public function format(): string
    {
        return 'json';
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}
