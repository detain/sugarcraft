<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

/**
 * Registration DTO for metric descriptors.
 *
 * Descriptors allow backends to pre-emit TYPE and HELP lines
 * before any samples are recorded. The Prometheus textfile
 * collector does NOT require them — a family that only ever
 * appears with samples is valid exposition — so descriptors are
 * metadata + declaration: they give a family its HELP text, and
 * they announce families that are currently idle so a dashboard
 * distinguishes "zero" from "never wired up".
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
    /**
     * House-idiom factory (sugar-metrics-014): `Descriptor::new(name:, help:, type:)`.
     */
    public static function new(string $name, string $help, string $type, array $labelKeys = []): self
    {
        return new self($name, $help, $type, $labelKeys);
    }

    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly string $type,
        public readonly array $labelKeys = [],
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException(Lang::t('descriptor.name_empty'));
        }
        // The name rides the exposition line unescaped; without this
        // charset check a help/name built from user input could inject
        // a whole second TYPE line. The /D anchor matters: a bare $ also
        // matches before a trailing newline, so "foo\n" would pass while
        // still forking an extra line in the published exposition.
        if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(Lang::t('descriptor.name_invalid', ['name' => Util::displaySafe($name)]));
        }
        if ($help === '') {
            throw new \InvalidArgumentException(Lang::t('descriptor.help_empty'));
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $help) === 1) {
            throw new \InvalidArgumentException(Lang::t('descriptor.help_invalid'));
        }
        if (in_array($type, ['counter', 'gauge', 'histogram', 'summary'], true) === false) {
            throw new \InvalidArgumentException(Lang::t('descriptor.type_invalid', ['type' => Util::displaySafe($type)]));
        }
        foreach ($labelKeys as $k) {
            if (is_string($k) === false || $k === '') {
                throw new \InvalidArgumentException(Lang::t('descriptor.label_empty'));
            }
            // Label names are stricter than metric names: the colon is
            // legal in a metric name but NOT in a label name, and label
            // keys ride the exposition line unescaped just like the name.
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $k) !== 1) {
                throw new \InvalidArgumentException(Lang::t('descriptor.label_invalid', ['key' => Util::displaySafe($k)]));
            }
        }
    }
}
