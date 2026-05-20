<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Registry;

/**
 * A synchronous instrument that adds positive and negative
 * increments — used when a value can go up or down (e.g.,
 * active connections, item counts in a queue).
 *
 * Mirrors opentelemetry.io/api/metrics#UpDownCounter.
 */
final class UpDownCounter
{
    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help = '',
    ) {}

    /**
     * Add `$amount` to the current value. Positive increments
     * increase the sum; negative increments decrease it.
     */
    public function add(float $amount, array $tags = []): void
    {
        $this->registry->upDownCounter($this->name, $amount, $tags);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function help(): string
    {
        return $this->help;
    }
}
