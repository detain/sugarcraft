<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Alerts;

use SugarCraft\Sql\Admin\Connections\ConnectionCounters;
use SugarCraft\Sql\Admin\Format;
use SugarCraft\Sql\Db\Flavor;

/**
 * Stateless alert checker — evaluates metrics against thresholds.
 *
 * Designed for both polling (DashboardPage 3s cycle) and event-driven
 * contexts. No state is held between calls; each check() invocation
 * is independent.
 *
 * Production path: DashboardPage drives checkAllMetrics() from its poll tick
 * — that is the only wired alert seam in the app. The counters pair
 * (checkConnectionUsage()/checkAndDispatch()) is a public but unwired
 * convenience surface with zero production callers (adminpages-012) and is
 * deprecated toward the 2.0.0 horizon:
 *   $manager = AlertManager::new()
 *       ->withThresholds(AlertThresholds::standard())
 *       ->withNotifier(AlertNotifier::withDefaults());
 *
 *   $alerts = $manager->checkAllMetrics($statusVariables, $serverVariables);
 *   foreach ($alerts as $alert) {
 *       $notifier = $notifier->notify($alert);
 *   }
 */
final class AlertManager
{
    private function __construct(
        private readonly AlertThresholds $thresholds,
        private readonly ?AlertNotifierInterface $notifier = null,
        private readonly ?Flavor $flavor = null,
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
            flavor: $this->flavor,
        );
    }

    /**
     * Return a new AlertManager that reads the given flavor's metric keys.
     *
     * Without a flavor (or with any MySQL-family one) the watches read the
     * MySQL-named status/variable keys, byte-identical to before. With
     * Flavor::Postgres they read the pg_stat_database / pg_settings shapes
     * PostgresAdminProvider emits, so PostgreSQL alerts through the same
     * seam, thresholds and Alert value objects.
     */
    public function withFlavor(?Flavor $flavor): self
    {
        return new self(
            thresholds: $this->thresholds,
            notifier: $this->notifier,
            flavor: $flavor,
        );
    }

    public function flavor(): ?Flavor
    {
        return $this->flavor;
    }

    /**
     * Return a new AlertManager with the given notifier.
     */
    public function withNotifier(AlertNotifierInterface $n): self
    {
        return new self(
            thresholds: $this->thresholds,
            notifier: $n,
            flavor: $this->flavor,
        );
    }

    /**
     * Check connection counters and fire alerts for any thresholds exceeded.
     *
     * @deprecated REMOVED-IN: 2.0.0 — unwired convenience surface (adminpages-012):
     *             nothing in candy-sql calls it, while the dashboard's own tick
     *             covers the same signal through checkAllMetrics()'s
     *             max_connections watch. Hosts that already feed a live
     *             ConnectionCounters may keep calling it until the horizon.
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
     * The dashboard's only wired alert seam (DashboardPage::checkAlerts()).
     * MySQL-family flavors read the MySQL-named keys (`long_query_time`,
     * `Connection_errors_total`, `max_connections` + `Threads_connected`).
     * Flavor::Postgres (withFlavor()) reads the pg shapes instead:
     * `pg_stat_database.numbackends` over the `max_connections` setting, and
     * `log_min_duration_statement` (ms) as the slow-query budget. There is no
     * pg analogue of Connection_errors_total, so that watch stays silent on
     * Postgres rather than inventing a stand-in. A missing or non-positive
     * max_connections skips the connection watch (no fabricated default).
     *
     * @param array<string, string> $statusVariables  SHOW GLOBAL STATUS output
     * @param array<string, string> $serverVariables  SHOW GLOBAL VARIABLES output
     * @return array<string, Alert>  Map of alert key -> Alert instance
     */
    public function checkAllMetrics(array $statusVariables, array $serverVariables): array
    {
        if ($this->flavor === Flavor::Postgres) {
            return $this->checkPostgresMetrics($statusVariables, $serverVariables);
        }

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
                    sprintf('%s detected', Format::plural($connectionErrorsTotal, 'connection error')),
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

            $alert = $this->connectionUsageAlert($threadsConnected, $maxConnections);
            if ($alert !== null) {
                $alerts['max_connections_usage'] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * PostgreSQL arm of checkAllMetrics(): same Alert shapes and keys as the
     * MySQL arm, fed from pg_stat_database / pg_settings.
     *
     * @param array<string, string> $statusVariables
     * @param array<string, string> $serverVariables
     * @return array<string, Alert>
     */
    private function checkPostgresMetrics(array $statusVariables, array $serverVariables): array
    {
        $alerts = [];

        if ($this->thresholds->watches('slow_query')) {
            // log_min_duration_statement is milliseconds; -1 disables logging
            // and 0 logs everything, neither is a "slow budget" to warn on.
            $ms = (float) ($serverVariables['log_min_duration_statement'] ?? -1);
            $seconds = $ms / 1000.0;
            $threshold = $this->thresholds->slowQueryThreshold();

            if ($ms > 0 && $seconds >= $threshold) {
                $alerts['slow_query'] = Alert::warning(
                    'slow_query',
                    sprintf('Slow query time set to %.1fs', $seconds),
                    $seconds,
                    $threshold,
                    MetricKind::Seconds,
                );
            }
        }

        if ($this->thresholds->watches('max_connections') && isset($statusVariables['pg_stat_database.numbackends'])) {
            $alert = $this->connectionUsageAlert(
                (int) $statusVariables['pg_stat_database.numbackends'],
                (int) ($serverVariables['max_connections'] ?? 0),
            );
            if ($alert !== null) {
                $alerts['max_connections_usage'] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * Shared connection-usage verdict for both flavor arms.
     */
    private function connectionUsageAlert(int $connected, int $max): ?Alert
    {
        if ($max <= 0) {
            return null;
        }

        $ratio = (float) $connected / (float) $max;
        if ($ratio < $this->thresholds->connectionWarningThreshold()) {
            return null;
        }

        $severity = $ratio >= $this->thresholds->connectionCriticalThreshold()
            ? Severity::Critical
            : Severity::Warning;

        return new Alert(
            $severity,
            'max_connections',
            sprintf('Connections at %.1f%% of max (%d/%d)', $ratio * 100, $connected, $max),
            $ratio,
            $this->thresholds->connectionCriticalThreshold(),
            new \DateTimeImmutable(),
            MetricKind::Ratio,
        );
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
    public function notifier(): ?AlertNotifierInterface
    {
        return $this->notifier;
    }

    /**
     * Dispatch all alerts to the notifier and return a new notifier with all alerts sent.
     *
     * This is a convenience method that combines checkConnectionUsage with notifier dispatch.
     * Returns the alerts and an updated notifier separately.
     *
     * @deprecated REMOVED-IN: 2.0.0 — inherits its target's verdict (adminpages-012):
     *             zero production callers; embedders wanting check + dispatch over
     *             the wired metric set loop checkAllMetrics() against a notifier
     *             themselves, exactly as DashboardPage does.
     *
     * @return array{alerts: array<string, Alert>, notifier: AlertNotifierInterface}
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
