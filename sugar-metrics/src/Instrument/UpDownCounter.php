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
    /**
     * @param array<string,string> $tags Static tags applied on every add() call;
     *                                   per-call tags win on key conflicts.
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help = '',
        private readonly array $tags = [],
    ) {}

    /**
     * Add `$amount` to the current value. Positive increments
     * increase the sum; negative increments decrease it.
     *
     * The instrument's static tags are emitted on every call; the
     * `$tags` argument adds (or overrides) per-call tags on top.
     *
     * @param array<string,string> $tags
     */
    public function add(float $amount, array $tags = []): void
    {
        $merged = $this->tags === [] ? $tags : \array_merge($this->tags, $tags);
        $this->registry->upDownCounter($this->name, $amount, $merged);
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
