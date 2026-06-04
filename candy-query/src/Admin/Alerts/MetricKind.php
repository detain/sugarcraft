<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

/**
 * Describes the semantic kind of a metric, controlling how it is formatted
 * in toast messages and other user-facing displays.
 *
 * @see Alert::toToastMessage() for the formatting logic that consumes this enum.
 */
enum MetricKind
{
    /**
     * A ratio in the range 0.0–1.0 that should be displayed as a percentage.
     * E.g. connection_usage at 0.75 → "75.0%".
     *
     * Used for: connection_usage, thread_running, cache_hit_rate, max_connections_usage.
     */
    case Ratio;

    /**
     * A duration in seconds. Displayed with an "s" suffix.
     * E.g. slow_query at 5.0 → "5.0s".
     *
     * Used for: slow_query_time.
     */
    case Seconds;

    /**
     * An absolute count / integer. Displayed as a plain integer.
     * E.g. connection_errors at 150 → "150".
     *
     * Used for: connection_errors_total.
     */
    case Count;
}
