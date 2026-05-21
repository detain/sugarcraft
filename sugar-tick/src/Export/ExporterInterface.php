<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Contract for heartbeat exporters (CSV, JSON, etc.).
 */
interface ExporterInterface
{
    /** @return list<string> column headers */
    public function headers(): array;

    /**
     * @param array<Heartbeat> $heartbeats
     * @return list<list<string|int|float>> rows of scalar data
     */
    public function rows(array $heartbeats): array;

    public function format(): string;

    public function contentType(): string;
}
