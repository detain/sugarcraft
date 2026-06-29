<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Contract for tabular heartbeat exporters (CSV, JSON).
 * Extends ExporterInterface with rows/headers for tabular output.
 */
interface TabularExporterInterface extends ExporterInterface
{
    /** @return list<string> column headers */
    public function headers(): array;

    /**
     * @param array<Heartbeat> $heartbeats
     * @return list<list<string|int|float>> rows of scalar data
     */
    public function rows(array $heartbeats): array;

    /**
     * Encode heartbeats as a formatted string.
     *
     * @param array<Heartbeat> $heartbeats
     */
    public function encode(array $heartbeats): string;
}
