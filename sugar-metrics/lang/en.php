<?php

/**
 * English (default) translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'cannot open metrics target: {target}',
    'jsonstream.cannot_open_stderr'   => 'cannot open php://stderr',
    'jsonstream.invalid_target'       => 'target must be path, resource, or null',
    'jsonstream.write_failed'         => 'jsonstream write failed: {name}',
    'statsd.socket_not_resource'      => 'existingSocket must be a resource',
    'statsd.connect_failed'           => 'StatsD connect to {endpoint} failed: {errstr} ({errno})',
    'prom.cannot_open'                => 'Prometheus textfile: cannot open {path}',
    'prom.rename_failed'              => 'Prometheus textfile: rename failed: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} child backend(s) failed. Errors: {errors}',
    'descriptor.help_empty'           => 'descriptor help must not be empty',
    'descriptor.help_invalid'         => 'descriptor help must not contain control characters',
    'descriptor.label_empty'          => 'descriptor label keys must be non-empty strings',
    'descriptor.label_invalid'        => 'descriptor label key is not a valid Prometheus label name: {key}',
    'descriptor.name_empty'           => 'descriptor name must not be empty',
    'descriptor.name_invalid'         => 'descriptor name is not a valid Prometheus metric name: {name}',
    'descriptor.type_invalid'         => 'descriptor type must be one of counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream could not encode the record for metric: {name}',
    'jsonstream.flush_failed'         => 'jsonstream flush failed',
    'memory.max_samples'              => 'maxSamplesPerKey must be >= 1, got {value}',
    'memory.max_series'               => 'maxSeries must be >= 0, got {value}',
    'multibackend.no_children'        => 'MultiBackend requires at least one child backend',
    'prom.buckets_invalid'            => 'histogram bucket boundaries must be finite and strictly increasing; violation at index {index}',
    'prom.duplicate_series'           => 'Prometheus textfile: duplicate series {name} with labels {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily must be >= 1, got {limit}',
    'prom.name_collision'             => 'Prometheus textfile: "{first}" and "{second}" sanitize to the same metric name "{folded}"',
    'prom.reserved_label'             => 'histogram metric "{name}" cannot accept a caller-supplied "le" label',
    'prom.two_kinds'                  => 'Prometheus textfile: "{name}" is emitted as both a histogram and a {kind}; one metric name cannot carry two kinds',
    'prom.write_incomplete'           => 'Prometheus textfile: incomplete write to {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit must be >= 1, got {limit}',
    'registry.negative_monotonic'     => 'monotonic {kind} "{name}" refused negative value {value}',
    'registry.register_conflict'      => 'metric "{name}" is already registered as {existing}; cannot re-register as {incoming}',
    'statsd.write_failed'             => 'StatsD write to {endpoint} failed',
];
