<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

use SugarCraft\Toast\Position;

/**
 * Immutable threshold configuration for alert conditions.
 *
 * Defines which metrics to watch and at what values alerts fire.
 * Ships with sensible defaults (::new()) and a stricter preset (::strict())
 * for production environments where early warning matters.
 *
 * All ratio thresholds are expressed as 0.0–1.0 floats (e.g. 0.8 = 80%).
 */
final class AlertThresholds
{
    /** @param list<string> $watchedMetrics */
    private function __construct(
        // Connection thresholds
        private readonly float $connectionWarningThreshold = 0.6,
        private readonly bool $connectionWarningThresholdSet = false,
        private readonly float $connectionCriticalThreshold = 0.8,
        private readonly bool $connectionCriticalThresholdSet = false,
        // Aborted connection rate threshold (per connection ratio)
        private readonly float $abortedRateThreshold = 0.05,
        private readonly bool $abortedRateThresholdSet = false,
        // Slow query threshold (seconds)
        private readonly float $slowQueryThreshold = 5.0,
        private readonly bool $slowQueryThresholdSet = false,
        // Thread running threshold (ratio of max_connections)
        private readonly float $threadRunningThreshold = 0.5,
        private readonly bool $threadRunningThresholdSet = false,
        // Connection errors threshold (absolute count)
        private readonly int $connectionErrorsThreshold = 100,
        private readonly bool $connectionErrorsThresholdSet = false,
        // Which metrics to actually watch (empty = all)
        private readonly array $watchedMetrics = [],
        private readonly bool $watchedMetricsSet = false,
        // Toast settings
        private readonly bool $toastEnabled = true,
        private readonly bool $toastEnabledSet = false,
        private readonly Position $toastPosition = Position::TopRight,
        private readonly bool $toastPositionSet = false,
        private readonly ?float $toastDuration = 5.0,
        private readonly bool $toastDurationSet = false,
    ) {}

    /**
     * Fresh instance with no explicit thresholds set.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Standard thresholds suitable for most environments.
     *
     * Connection warning at 60%, critical at 80%, aborted rate at 5%,
     * slow query at 5 seconds, thread running at 50%. Toast enabled
     * at TopRight with 5s auto-dismiss.
     */
    public static function default(): self
    {
        return self::new()
            ->withConnectionWarningThreshold(0.6)
            ->withConnectionCriticalThreshold(0.8)
            ->withAbortedRateThreshold(0.05)
            ->withSlowQueryThreshold(5.0)
            ->withThreadRunningThreshold(0.5);
    }

    /**
     * Stricter thresholds for sensitive/production environments.
     *
     * Warning at 50%, critical at 70%, aborted rate at 1%,
     * slow query at 1 second, thread running at 30%. Toast enabled
     * at TopRight with 8s auto-dismiss for longer visibility.
     */
    public static function strict(): self
    {
        return self::new()
            ->withConnectionWarningThreshold(0.5)
            ->withConnectionCriticalThreshold(0.7)
            ->withAbortedRateThreshold(0.01)
            ->withSlowQueryThreshold(1.0)
            ->withThreadRunningThreshold(0.3);
    }

    // ─── Connection thresholds ────────────────────────────────────────

    /**
     * Warning threshold for connection usage ratio (0.0–1.0).
     *
     * Fires when threads_connected / max_connections exceeds this value.
     * Default: 0.6 (60%).
     */
    public function connectionWarningThreshold(): float
    {
        return $this->connectionWarningThreshold;
    }

    public function withConnectionWarningThreshold(float $value): self
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new \InvalidArgumentException('Connection warning threshold must be between 0.0 and 1.0');
        }
        return $this->mutate([
            'connectionWarningThreshold' => $value,
            'connectionWarningThresholdSet' => true,
            'propsAdded' => ['connectionWarningThreshold'],
        ]);
    }

    /**
     * Critical threshold for connection usage ratio (0.0–1.0).
     *
     * Fires when threads_connected / max_connections exceeds this value.
     * Default: 0.8 (80%).
     */
    public function connectionCriticalThreshold(): float
    {
        return $this->connectionCriticalThreshold;
    }

    public function withConnectionCriticalThreshold(float $value): self
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new \InvalidArgumentException('Connection critical threshold must be between 0.0 and 1.0');
        }
        return $this->mutate([
            'connectionCriticalThreshold' => $value,
            'connectionCriticalThresholdSet' => true,
            'propsAdded' => ['connectionCriticalThreshold'],
        ]);
    }

    // ─── Aborted connection rate ──────────────────────────────────────

    /**
     * Aborted connection rate threshold (0.0–1.0+).
     *
     * Fires when aborted_connects / total_connections exceeds this ratio.
     * High values may indicate auth failures or network issues.
     * Default: 0.05 (5%).
     */
    public function abortedRateThreshold(): float
    {
        return $this->abortedRateThreshold;
    }

    public function withAbortedRateThreshold(float $value): self
    {
        if ($value < 0.0) {
            throw new \InvalidArgumentException('Aborted rate threshold must be >= 0.0');
        }
        return $this->mutate([
            'abortedRateThreshold' => $value,
            'abortedRateThresholdSet' => true,
            'propsAdded' => ['abortedRateThreshold'],
        ]);
    }

    // ─── Slow query threshold ─────────────────────────────────────────

    /**
     * Slow query time threshold in seconds.
     *
     * Fires when a query exceeds this duration. Default: 5.0s.
     */
    public function slowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    public function withSlowQueryThreshold(float $value): self
    {
        if ($value < 0.0) {
            throw new \InvalidArgumentException('Slow query threshold must be >= 0.0');
        }
        return $this->mutate([
            'slowQueryThreshold' => $value,
            'slowQueryThresholdSet' => true,
            'propsAdded' => ['slowQueryThreshold'],
        ]);
    }

    // ─── Thread running threshold ─────────────────────────────────────

    /**
     * Thread running threshold as ratio of max_connections (0.0–1.0).
     *
     * Fires when Threads_running exceeds this fraction of max_connections.
     * Default: 0.5 (50%).
     */
    public function threadRunningThreshold(): float
    {
        return $this->threadRunningThreshold;
    }

    public function withThreadRunningThreshold(float $value): self
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new \InvalidArgumentException('Thread running threshold must be between 0.0 and 1.0');
        }
        return $this->mutate([
            'threadRunningThreshold' => $value,
            'threadRunningThresholdSet' => true,
            'propsAdded' => ['threadRunningThreshold'],
        ]);
    }

    // ─── Connection errors threshold ────────────────────────────────────

    /**
     * Connection errors threshold (absolute count).
     *
     * Fires when the total connection error count exceeds this value.
     * Default: 100.
     */
    public function connectionErrorsThreshold(): int
    {
        return $this->connectionErrorsThreshold;
    }

    public function withConnectionErrorsThreshold(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Connection errors threshold must be >= 0');
        }
        return $this->mutate([
            'connectionErrorsThreshold' => $value,
            'connectionErrorsThresholdSet' => true,
            'propsAdded' => ['connectionErrorsThreshold'],
        ]);
    }

    // ─── Watched metrics ──────────────────────────────────────────────

    /**
     * Which metrics to actively watch.
     *
     * Empty array means watch all configured thresholds.
     * Non-empty array limits checks to the named metrics only.
     *
     * @return list<string>
     */
    public function watchedMetrics(): array
    {
        return $this->watchedMetrics;
    }

    /**
     * @param list<string> $metrics
     */
    public function withWatchedMetrics(array $metrics): self
    {
        return $this->mutate([
            'watchedMetrics' => $metrics,
            'watchedMetricsSet' => true,
            'propsAdded' => ['watchedMetrics'],
        ]);
    }

    /**
     * True if this threshold watches the given metric name.
     */
    public function watches(string $metric): bool
    {
        if ($this->watchedMetrics === []) {
            return true;
        }
        return \in_array($metric, $this->watchedMetrics, true);
    }

    // ─── Toast settings ───────────────────────────────────────────────

    /**
     * True when toast notifications are enabled.
     */
    public function toastEnabled(): bool
    {
        return $this->toastEnabled;
    }

    public function withToastEnabled(bool $enabled): self
    {
        return $this->mutate([
            'toastEnabled' => $enabled,
            'toastEnabledSet' => true,
            'propsAdded' => ['toastEnabled'],
        ]);
    }

    /**
     * Screen position for toast notifications.
     */
    public function toastPosition(): Position
    {
        return $this->toastPosition;
    }

    public function withToastPosition(Position $pos): self
    {
        return $this->mutate([
            'toastPosition' => $pos,
            'toastPositionSet' => true,
            'propsAdded' => ['toastPosition'],
        ]);
    }

    /**
     * Auto-dismiss duration in seconds, or null for no auto-dismiss.
     */
    public function toastDuration(): ?float
    {
        return $this->toastDuration;
    }

    public function withToastDuration(?float $seconds): self
    {
        return $this->mutate([
            'toastDuration' => $seconds,
            'toastDurationSet' => true,
            'propsAdded' => ['toastDuration'],
        ]);
    }

    // ─── Internal ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): static
    {
        // propsAdded is a tracking key, not a constructor parameter - remove it
        unset($changes['propsAdded']);
        return new static(...\array_merge(\get_object_vars($this), $changes));
    }
}
