<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

/**
 * Shared unknown-property guard for the constraint wrappers.
 *
 * The wrapper classes ({@see Length}, {@see Min}, {@see Max},
 * {@see Percentage}, {@see Ratio}, {@see Fill}) keep their payload inside a
 * private `$inner` value object, so `Solver::toConstraintsConstraints()`
 * reads it through `__get`. Each wrapper exposes only its own field names
 * and this trait carries the ONE rejection arm: any other name raises the
 * same `\Error` every wrapper used to carry a copy-pasted duplicate of.
 *
 * `static::class` inside a trait method resolves to the using class, so the
 * message keeps naming the concrete wrapper.
 */
trait ReadsConstraintPayload
{
    /**
     * Resolve `$name` against the wrapper's payload map or throw.
     *
     * @param array<string,mixed> $payload field name => value, per wrapper
     */
    private function payloadValue(string $name, array $payload): mixed
    {
        return $payload[$name]
            ?? throw new \Error("Property {$name} does not exist on " . static::class);
    }
}
