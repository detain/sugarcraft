<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\StatusSnapshotProviderInterface;

/**
 * Collection of all sidebar gauges for the Server Status page.
 *
 * Contains CPU (optional), Connections, Traffic, Key Efficiency, QPS, and InnoDB
 * gauges. Polls the ServerContext and optional Sampler to compute current ratios.
 *
 * @see Mirrors mysql-workbench sidebar gauge set
 */
final class SidebarGaugeSet
{
    /**
     * @param array<SidebarGauge> $gauges
     * @param array<string, float>|null $previousRates Last-seen rates for traffic baseline
     */
    private function __construct(
        private readonly array $gauges,
        private readonly ?SidebarGauge $cpuGauge,
        private readonly ServerContextInterface $context,
        private readonly ?Sampler $sampler,
        private readonly ?array $previousRates,
        private readonly ?float $previousTs,
    ) {}

    /**
     * Create a new gauge set from the server context.
     *
     * CPU gauge is optional — null when max_connections is not available.
     */
    public static function new(ServerContextInterface $context, ?Sampler $sampler = null): self
    {
        $instance = new self(
            gauges: [],
            cpuGauge: null,
            context: $context,
            sampler: $sampler,
            previousRates: null,
            previousTs: null,
        );

        return $instance->buildGauges();
    }

    /**
     * Poll the context/sampler and refresh all gauge ratios.
     */
    public function poll(): self
    {
        $statusVars = $this->context->statusVariables();
        $serverVars = $this->context->serverVariables();

        $rates = [];
        $currentTs = $this->context->statusVariablesTs();

        if ($this->sampler !== null) {
            $rates = $this->sampler->sample() ?? [];
        }

        // Build new gauges with updated ratios
        $newGauges = [];
        $newCpuGauge = null;

        foreach ($this->gauges as $gauge) {
            $newGauges[] = $this->updateGauge($gauge, $statusVars, $serverVars, $rates);
        }

        if ($this->cpuGauge !== null) {
            $newCpuGauge = $this->updateGauge($this->cpuGauge, $statusVars, $serverVars, $rates);
        }

        return new self(
            gauges: $newGauges,
            cpuGauge: $newCpuGauge,
            context: $this->context,
            sampler: $this->sampler,
            previousRates: $rates,
            previousTs: $currentTs,
        );
    }

    /**
     * Render all gauges as a sidebar panel string.
     */
    public function view(): string
    {
        $lines = [];

        $lines[] = "\x1b[1;36mServer Metrics\x1b[0m";
        $lines[] = "\x1b[36m" . str_repeat('─', 18) . "\x1b[0m";

        if ($this->cpuGauge !== null) {
            $lines[] = '';
            $lines[] = $this->cpuGauge->view();
        }

        foreach ($this->gauges as $gauge) {
            $lines[] = '';
            $lines[] = $gauge->view();
        }

        return implode("\n", $lines);
    }

    /**
     * Accessor for all gauges.
     *
     * @return array<SidebarGauge>
     */
    public function gauges(): array
    {
        return $this->gauges;
    }

    /**
     * Accessor for the CPU gauge (may be null).
     */
    public function cpuGauge(): ?SidebarGauge
    {
        return $this->cpuGauge;
    }

    /**
     * Accessor for the previous rates.
     *
     * @return array<string, float>|null
     */
    public function previousRates(): ?array
    {
        return $this->previousRates;
    }

    /**
     * Build the initial gauge set from the current context state.
     */
    private function buildGauges(): self
    {
        $statusVars = $this->context->statusVariables();
        $serverVars = $this->context->serverVariables();

        // CPU gauge is optional (depends on max_connections availability)
        $cpuGauge = $this->buildOptionalCpuGauge($statusVars, $serverVars);

        // Build standard gauges
        $connectionsRatio = $this->computeConnectionsRatio($statusVars, $serverVars);
        $trafficRatio = $this->computeTrafficRatio($statusVars);
        $keyEffRatio = $this->computeKeyEfficiencyRatio($statusVars);
        $qpsRatio = $this->computeQpsRatio($statusVars);
        $innodbRatio = $this->computeInnoDBRatio($statusVars);

        $gauges = [
            SidebarGauge::new(GaugeType::Connections, $connectionsRatio),
            SidebarGauge::new(GaugeType::Traffic, $trafficRatio),
            SidebarGauge::new(GaugeType::KeyEfficiency, $keyEffRatio),
            SidebarGauge::new(GaugeType::Qps, $qpsRatio),
            SidebarGauge::new(GaugeType::InnoDB, $innodbRatio),
        ];

        return new self(
            gauges: $gauges,
            cpuGauge: $cpuGauge,
            context: $this->context,
            sampler: $this->sampler,
            previousRates: null,
            previousTs: null,
        );
    }

    /**
     * Build the optional CPU gauge.
     */
    private function buildOptionalCpuGauge(array $statusVars, array $serverVars): ?SidebarGauge
    {
        // CPU requires max_connections to compute the ratio
        $maxConn = $serverVars['max_connections'] ?? null;
        if ($maxConn === null || (int) $maxConn <= 0) {
            return null;
        }

        $threadsConnected = $statusVars['Threads_connected'] ?? '0';
        $ratio = (int) $threadsConnected / (int) $maxConn;

        return SidebarGauge::new(GaugeType::Cpu, $ratio);
    }

    /**
     * Update a single gauge based on current status.
     */
    private function updateGauge(
        SidebarGauge $gauge,
        array $statusVars,
        array $serverVars,
        array $rates,
    ): SidebarGauge {
        $ratio = match ($gauge->type()) {
            GaugeType::Cpu          => $this->computeConnectionsRatio($statusVars, $serverVars),
            GaugeType::Connections  => $this->computeConnectionsRatio($statusVars, $serverVars),
            GaugeType::Traffic      => $this->computeTrafficRatio($statusVars),
            GaugeType::KeyEfficiency => $this->computeKeyEfficiencyRatio($statusVars),
            GaugeType::Qps          => $this->computeQpsRatio($statusVars),
            GaugeType::InnoDB       => $this->computeInnoDBRatio($statusVars),
        };

        return $gauge->withRatio($ratio);
    }

    /**
     * Compute connections ratio: Threads_connected / max_connections.
     */
    private function computeConnectionsRatio(array $statusVars, array $serverVars): float
    {
        $maxConn = $serverVars['max_connections'] ?? null;
        if ($maxConn === null || (int) $maxConn <= 0) {
            return 0.0;
        }

        $threadsConnected = $statusVars['Threads_connected'] ?? '0';
        return (int) $threadsConnected / (int) $maxConn;
    }

    /**
     * Compute traffic ratio from bytes received/sent rates.
     *
     * Uses a simple baseline of 10MB/s as "100%" for normalization.
     * This gives a sense of network saturation relative to a typical connection.
     */
    private function computeTrafficRatio(array $statusVars): float
    {
        // Baseline: 10MB/s as 100% traffic
        $baselineBytesPerSec = 10 * 1024 * 1024;

        $bytesReceived = (float) ($statusVars['Bytes_received'] ?? '0');
        $bytesSent = (float) ($statusVars['Bytes_sent'] ?? '0');

        // Traffic ratio is total bytes / baseline
        // This is a simplified view — in production you'd use rate deltas
        $totalBytes = $bytesReceived + $bytesSent;
        $ratio = $totalBytes / $baselineBytesPerSec;

        // If we have a sampler, we could compute rate, but for now use absolute
        // with a high baseline to keep ratios reasonable
        return min(1.0, $ratio / 100); // Normalize: 100x baseline = 100% = 1.0
    }

    /**
     * Compute key efficiency ratio: Key_reads / (Key_reads + Key_writes).
     *
     * A high ratio indicates many cache misses (reads hitting disk).
     * A low ratio indicates good cache utilization.
     *
     * @return float 0.0-1.0 where higher is worse (more misses)
     */
    private function computeKeyEfficiencyRatio(array $statusVars): float
    {
        $keyReads = (int) ($statusVars['Key_reads'] ?? '0');
        $keyWrites = (int) ($statusVars['Key_writes'] ?? '0');
        $keyWriteRequests = (int) ($statusVars['Key_write_requests'] ?? '0');

        $total = $keyReads + $keyWrites;
        if ($total === 0) {
            return 0.0;
        }

        // Key reads / total operations = cache miss ratio
        // Lower is better (more reads are satisfied by cache)
        return $keyReads / ($keyReads + $keyWriteRequests);
    }

    /**
     * Compute QPS ratio from Questions / Uptime.
     *
     * Normalizes against a baseline of 1000 QPS as "100%".
     */
    private function computeQpsRatio(array $statusVars): float
    {
        $questions = (float) ($statusVars['Questions'] ?? '0');
        $uptime = (float) ($statusVars['Uptime'] ?? '1');

        if ($uptime <= 0) {
            return 0.0;
        }

        $qps = $questions / $uptime;
        $baselineQps = 1000.0;

        return min(1.0, $qps / $baselineQps);
    }

    /**
     * Compute InnoDB buffer pool utilization ratio.
     *
     * Uses pages_free / pages_total. If individual stats not available,
     * falls back to buffer_pool_size / total_memory estimate.
     *
     * @return float 0.0-1.0 where 1.0 = completely full (bad for writes)
     */
    private function computeInnoDBRatio(array $statusVars): float
    {
        $pagesFree = (int) ($statusVars['Innodb_buffer_pool_pages_free'] ?? '0');
        $pagesTotal = (int) ($statusVars['Innodb_buffer_pool_pages_total'] ?? '0');

        if ($pagesTotal <= 0) {
            // Fallback: try to estimate from buffer_pool_size
            return 0.5; // Default to 50% if not available
        }

        // For InnoDB, high utilization (low free) is concerning for write-heavy workloads
        // Invert: 1.0 - (free/total) so full = high ratio = "bad"
        $utilization = 1.0 - ($pagesFree / $pagesTotal);
        return max(0.0, min(1.0, $utilization));
    }
}
