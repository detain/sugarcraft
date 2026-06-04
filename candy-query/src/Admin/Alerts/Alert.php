<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

/**
 * Represents a fired alert threshold notification.
 *
 * Encapsulates the metric that triggered, its severity level,
 * and the human-readable message. Immutable — combine with
 * AlertManager for threshold checking and AlertNotifier for dispatch.
 */
final class Alert
{
    /**
     * @param Severity $severity  Alert severity tier
     * @param string $metric      Name of the metric that triggered (e.g. "connection_usage")
     * @param string $message     Human-readable description of the alert
     * @param float $value        Actual metric value when alert fired
     * @param float $threshold    Threshold value that was exceeded
     * @param \DateTimeImmutable $firedAt When the alert was triggered
     * @param MetricKind $metricKind Controls how value/threshold are formatted in toToastMessage()
     */
    public function __construct(
        public readonly Severity $severity,
        public readonly string $metric,
        public readonly string $message,
        public readonly float $value,
        public readonly float $threshold,
        public readonly \DateTimeImmutable $firedAt = new \DateTimeImmutable(),
        public readonly MetricKind $metricKind = MetricKind::Ratio,
    ) {}

    /**
     * Create a warning-level alert.
     */
    public static function warning(string $metric, string $message, float $value, float $threshold, MetricKind $kind = MetricKind::Ratio): self
    {
        return new self(Severity::Warning, $metric, $message, $value, $threshold, new \DateTimeImmutable(), $kind);
    }

    /**
     * Create a critical-level alert.
     */
    public static function critical(string $metric, string $message, float $value, float $threshold, MetricKind $kind = MetricKind::Ratio): self
    {
        return new self(Severity::Critical, $metric, $message, $value, $threshold, new \DateTimeImmutable(), $kind);
    }

    /**
     * Create an info-level alert.
     */
    public static function info(string $metric, string $message, float $value, float $threshold, MetricKind $kind = MetricKind::Ratio): self
    {
        return new self(Severity::Info, $metric, $message, $value, $threshold, new \DateTimeImmutable(), $kind);
    }

    /**
     * True when this is a critical-severity alert.
     */
    public function isCritical(): bool
    {
        return $this->severity === Severity::Critical;
    }

    /**
     * True when this is a warning-severity alert.
     */
    public function isWarning(): bool
    {
        return $this->severity === Severity::Warning;
    }

    /**
     * True when this is an info-severity alert.
     */
    public function isInfo(): bool
    {
        return $this->severity === Severity::Info;
    }

    /**
     * Format alert as a short one-liner suitable for toast display.
     */
    public function toToastMessage(): string
    {
        return match ($this->metricKind) {
            MetricKind::Ratio => sprintf(
                '%s: %s (%.1f%% > %.1f%%)',
                $this->metric,
                $this->message,
                $this->value * 100,
                $this->threshold * 100,
            ),
            MetricKind::Seconds => sprintf(
                '%s: %s (%.1fs > %.1fs)',
                $this->metric,
                $this->message,
                $this->value,
                $this->threshold,
            ),
            MetricKind::Count => sprintf(
                '%s: %s (%d > %d)',
                $this->metric,
                $this->message,
                (int) $this->value,
                (int) $this->threshold,
            ),
        };
    }
}
