<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

use SugarCraft\Query\Admin\Connections\ConnectionCounters;

/**
 * Stateless alert checker — evaluates metrics against thresholds.
 *
 * Designed for both polling (DashboardPage 3s cycle) and event-driven
 * contexts. No state is held between calls; each check() invocation
 * is independent.
 *
 * Usage:
 *   $manager = AlertManager::new()
 *       ->withThresholds(AlertThresholds::default())
 *       ->withNotifier(AlertNotifier::withDefaults());
 *
 *   $alerts = $manager->checkConnectionUsage($counters);
 *   foreach ($alerts as $alert) {
 *       $notifier = $notifier->notify($alert);
 *   }
 */
final class AlertManager
{
    private function __construct(
        private readonly AlertThresholds $thresholds,
        private readonly ?AlertNotifier $notifier = null,
    ) {}

    /**
     * Fresh AlertManager with default thresholds and no notifier.
     */
    public static function new(): self
    {
        return new self(AlertThresholds::new());
    }

    /**
     * Return a new AlertManager with the given threshold configuration.
     */
    public function withThresholds(AlertThresholds $t): self
    {
        return new self(
            thresholds: $t,
            notifier: $this->notifier,
        );
    }

    /**
     * Return a new AlertManager with the given notifier.
     */
    public function withNotifier(AlertNotifier $n): self
    {
        return new self(
            thresholds: $this->thresholds,
            notifier: $n,
        );
    }

    /**
     * Check connection counters and fire alerts for any thresholds exceeded.
     *
     * @return array<string, Alert>  Map of alert key -> Alert instance
     */
    public function checkConnectionUsage(ConnectionCounters $counters): array
    {
        $alerts = [];

        // Connection usage ratio check
        if ($this->thresholds->watches('connection_usage')) {
            $ratio = $counters->connectionUsageRatio();

            if ($ratio >= $this->thresholds->connectionCriticalThreshold()) {
                $alerts['connection_critical'] = Alert::critical(
                    'connection_usage',
                    'Connection usage critically high',
                    $ratio,
                    $this->thresholds->connectionCriticalThreshold(),
                    MetricKind::Ratio,
                );
            } elseif ($ratio >= $this->thresholds->connectionWarningThreshold()) {
                $alerts['connection_warning'] = Alert::warning(
                    'connection_usage',
                    'Connection usage elevated',
                    $ratio,
                    $this->thresholds->connectionWarningThreshold(),
                    MetricKind::Ratio,
                );
            }
        }

        // Aborted connection rate check
        if ($this->thresholds->watches('aborted_rate')) {
            $abortedRate = $counters->abortedConnectionRate();

            if ($abortedRate >= $this->thresholds->abortedRateThreshold()) {
                $alerts['aborted_rate'] = Alert::warning(
                    'aborted_rate',
                    'Aborted connection rate elevated',
                    $abortedRate,
                    $this->thresholds->abortedRateThreshold(),
                    MetricKind::Ratio,
                );
            }
        }

        // Thread running threshold check
        if ($this->thresholds->watches('thread_running')) {
            $threadRunningRatio = $counters->maxConnections > 0
                ? (float) $counters->threadsRunning / (float) $counters->maxConnections
                : 0.0;

            if ($threadRunningRatio >= $this->thresholds->threadRunningThreshold()) {
                $alerts['thread_running'] = Alert::warning(
                    'thread_running',
                    'Thread running count elevated',
                    $threadRunningRatio,
                    $this->thresholds->threadRunningThreshold(),
                    MetricKind::Ratio,
                );
            }
        }

        return $alerts;
    }

    /**
     * Check status and server variables for threshold violations.
     *
     * @param array<string, string> $statusVariables  SHOW GLOBAL STATUS output
     * @param array<string, string> $serverVariables  SHOW GLOBAL VARIABLES output
     * @return array<string, Alert>  Map of alert key -> Alert instance
     */
    public function checkAllMetrics(array $statusVariables, array $serverVariables): array
    {
        $alerts = [];

        // Slow query time check (long_query_time variable)
        if ($this->thresholds->watches('slow_query')) {
            $slowQueryTime = (float) ($serverVariables['long_query_time'] ?? 0.0);
            $slowQueryThreshold = $this->thresholds->slowQueryThreshold();

            // Only alert if slow_query_time is non-zero and above threshold
            if ($slowQueryTime > 0 && $slowQueryTime >= $slowQueryThreshold) {
                $alerts['slow_query'] = Alert::warning(
                    'slow_query',
                    sprintf('Slow query time set to %.1fs', $slowQueryTime),
                    $slowQueryTime,
                    $slowQueryThreshold,
                    MetricKind::Seconds,
                );
            }
        }

        // Connection errors total check
        if ($this->thresholds->watches('connection_errors')) {
            $connectionErrorsTotal = (int) ($statusVariables['Connection_errors_total'] ?? 0);

            // Fire if we have significant connection errors (threshold is configurable)
            $errorThreshold = $this->thresholds->connectionErrorsThreshold();
            if ($connectionErrorsTotal >= $errorThreshold) {
                $alerts['connection_errors'] = Alert::warning(
                    'connection_errors',
                    sprintf('Connection errors detected: %d total', $connectionErrorsTotal),
                    (float) $connectionErrorsTotal,
                    (float) $errorThreshold,
                    MetricKind::Count,
                );
            }
        }

        // Max used connections check (Peak of Threads_connected)
        if ($this->thresholds->watches('max_connections')) {
            $maxConnections = (int) ($serverVariables['max_connections'] ?? 151);
            $threadsConnected = (int) ($statusVariables['Threads_connected'] ?? 0);

            if ($maxConnections > 0) {
                $usageRatio = (float) $threadsConnected / (float) $maxConnections;

                // Only alert if we're above warning threshold
                if ($usageRatio >= $this->thresholds->connectionWarningThreshold()) {
                    $severity = $usageRatio >= $this->thresholds->connectionCriticalThreshold()
                        ? Severity::Critical
                        : Severity::Warning;

                    $alerts['max_connections_usage'] = new Alert(
                        $severity,
                        'max_connections',
                        sprintf(
                            'Connections at %.1f%% of max (%d/%d)',
                            $usageRatio * 100,
                            $threadsConnected,
                            $maxConnections,
                        ),
                        $usageRatio,
                        $this->thresholds->connectionCriticalThreshold(),
                        new \DateTimeImmutable(),
                        MetricKind::Ratio,
                    );
                }
            }
        }

        return $alerts;
    }

    /**
     * Get the current thresholds.
     */
    public function thresholds(): AlertThresholds
    {
        return $this->thresholds;
    }

    /**
     * Get the current notifier, if set.
     */
    public function notifier(): ?AlertNotifier
    {
        return $this->notifier;
    }

    /**
     * Dispatch all alerts to the notifier and return a new notifier with all alerts sent.
     *
     * This is a convenience method that combines checkConnectionUsage with notifier dispatch.
     * Returns the alerts and an updated notifier separately.
     *
     * @return array{alerts: array<string, Alert>, notifier: AlertNotifier}
     */
    public function checkAndDispatch(ConnectionCounters $counters): array
    {
        $alerts = $this->checkConnectionUsage($counters);

        $notifier = $this->notifier;
        if ($notifier !== null) {
            foreach ($alerts as $alert) {
                $notifier = $notifier->notify($alert);
            }
        }

        return ['alerts' => $alerts, 'notifier' => $notifier ?? AlertNotifier::new()];
    }
}
