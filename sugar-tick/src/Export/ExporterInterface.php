<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

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
