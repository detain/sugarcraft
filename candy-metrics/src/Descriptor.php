<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

/**
 * Registration DTO for metric descriptors.
 *
 * Descriptors allow backends to pre-emit TYPE and HELP lines
 * before any samples are recorded, which is required by the
 * Prometheus textfile collector for uninitialized metrics.
 *
 * @readonly
 */
final class Descriptor
{
    /**
     * @param non-empty-string                           $name      Fully-qualified metric name.
     * @param non-empty-string                           $help      Human-readable description.
     * @param 'counter'|'gauge'|'histogram'|'summary'   $type      Prometheus metric type.
     *                                                                      "summary" emits a TYPE header only (quantiles / exemplars unsupported).
     * @param list<non-empty-string>                     $labelKeys Ordered list of label names for this metric.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly string $type,
        public readonly array $labelKeys = [],
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Descriptor name must be non-empty');
        }
        if ($help === '') {
            throw new \InvalidArgumentException('Descriptor help must be non-empty');
        }
        if (!in_array($type, ['counter', 'gauge', 'histogram', 'summary'], true)) {
            throw new \InvalidArgumentException("Descriptor type must be one of: counter, gauge, histogram, summary; got '{$type}'");
        }
        foreach ($labelKeys as $k) {
            if ($k === '') {
                throw new \InvalidArgumentException('Descriptor label keys must be non-empty strings');
            }
        }
    }
}
