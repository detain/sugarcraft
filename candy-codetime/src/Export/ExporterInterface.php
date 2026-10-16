<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Export;

use SugarCraft\CodeTime\Heartbeat;

/**
 * Base contract for heartbeat string exporters.
 */
interface ExporterInterface
{
    /**
     * Export heartbeats as a formatted string.
     *
     * @param list<Heartbeat> $heartbeats
     */
    public function export(string $name, array $heartbeats): string;

    public function format(): string;

    public function contentType(): string;
}
