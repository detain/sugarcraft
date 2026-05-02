<?php

declare(strict_types=1);

namespace CandyCore\Prompt;

/**
 * Mixin trait for {@see Field} implementations: provides a chainable
 * {@see withHideFunc()} setter and the matching {@see isHidden()}
 * accessor. Field classes `use HasHideFunc;` to opt into the runtime
 * visibility predicate without re-implementing it.
 *
 * The closure is preserved across mutations because each Field's
 * `mutate()` (or per-class clone helper) carries the trait property
 * forward via the immutable-with-pattern.
 */
trait HasHideFunc
{
    /** @var ?\Closure(array<string,mixed>): bool */
    private ?\Closure $hideFunc = null;

    /**
     * @param ?\Closure(array<string,mixed>): bool $fn
     * @return static
     */
    public function withHideFunc(?\Closure $fn): static
    {
        $clone = clone $this;
        $clone->hideFunc = $fn;
        return $clone;
    }

    /** @param array<string,mixed> $values */
    public function isHidden(array $values): bool
    {
        return $this->hideFunc !== null && ($this->hideFunc)($values) === true;
    }
}
