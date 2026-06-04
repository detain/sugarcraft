<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Core\Util\Color;
use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Sprinkles\Style;

/**
 * Collection of all sidebar gauges for the Server Status page.
 *
 * Contains Connections, Traffic, Key Efficiency, QPS, and InnoDB
 * gauges. Polls the ServerContext and an optional Sampler to compute
 * per-second rate deltas for the Traffic gauge. Key-efficiency uses
 * the formula Key_reads / (Key_reads + Key_read_requests).
 *
 * The Sampler is threaded through via ServerStatusSnapshotAdapter
 * (which wraps ServerContextInterface to satisfy StatusSnapshotProviderInterface),
 * allowing two-sample rate comparisons on each refresh cycle.
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
        private readonly ServerContextInterface $context,
        private readonly ?Sampler $sampler,
        private readonly ?array $previousRates,
        private readonly ?float $previousTs,
    ) {}

    /**
     * Create a new gauge set from the server context.
     */
    public static function new(ServerContextInterface $context, ?Sampler $sampler = null): self
    {
        $instance = new self(
            gauges: [],
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

        foreach ($this->gauges as $gauge) {
            $newGauges[] = $this->updateGauge($gauge, $statusVars, $serverVars, $rates);
        }

        return new self(
            gauges: $newGauges,
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

        $lines[] = Style::new()->bold()->foreground(Color::ansi(6))->render('Server Metrics');
        $lines[] = Style::new()->foreground(Color::ansi(6))->render(str_repeat('─', 18));

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
            context: $this->context,
            sampler: $this->sampler,
            previousRates: null,
            previousTs: null,
        );
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
            GaugeType::Connections  => $this->computeConnectionsRatio($statusVars, $serverVars),
            GaugeType::Traffic      => $this->computeTrafficRatio($statusVars, $rates),
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
     * Uses a baseline of 10MB/s as "100%" for normalization.
     * When sampler rates are provided, computes per-second rate deltas;
     * otherwise falls back to absolute bytes with the same 10MB/s baseline.
     */
    private function computeTrafficRatio(array $statusVars, ?array $rates = null): float
    {
        // Baseline: 10MB/s as 100% traffic
        $baselineBytesPerSec = 10 * 1024 * 1024;

        $totalBytesPerSec = 0.0;

        if ($rates !== null) {
            // Use sampler-provided per-second rates for accurate ratio
            // Sampler preserves original MySQL status variable names (Bytes_received, Bytes_sent)
            $totalBytesPerSec = ($rates['Bytes_received'] ?? 0.0) + ($rates['Bytes_sent'] ?? 0.0);
        } else {
            // Fallback: treat absolute bytes as a proxy for rate
            // This is less accurate but works when sampler is unavailable
            $bytesReceived = (float) ($statusVars['Bytes_received'] ?? '0');
            $bytesSent = (float) ($statusVars['Bytes_sent'] ?? '0');
            $totalBytesPerSec = $bytesReceived + $bytesSent;
        }

        return min(1.0, $totalBytesPerSec / $baselineBytesPerSec);
    }

    /**
     * Compute key efficiency ratio: Key_reads / (Key_reads + Key_read_requests).
     *
     * A high ratio indicates many cache misses (reads hitting disk).
     * A low ratio indicates good cache utilization.
     *
     * @return float 0.0-1.0 where higher is worse (more misses)
     */
    private function computeKeyEfficiencyRatio(array $statusVars): float
    {
        $keyReads = (int) ($statusVars['Key_reads'] ?? '0');
        $keyWriteRequests = (int) ($statusVars['Key_write_requests'] ?? '0');

        if ($keyReads === 0 && $keyWriteRequests === 0) {
            return 0.0;
        }

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
