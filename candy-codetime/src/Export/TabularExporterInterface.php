<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Export;

use SugarCraft\CodeTime\Heartbeat;

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
     * @return list<list<string|int|float|array<int,string>>> rows of scalar data,
     *              except the tags leg where an exporter keeps the list structured
     *              (JsonExporter emits an array; CsvExporter implodes to a string)
     */
    public function rows(array $heartbeats): array;

    /**
     * Encode heartbeats as a formatted string.
     *
     * @param array<Heartbeat> $heartbeats
     */
    public function encode(array $heartbeats): string;
}
