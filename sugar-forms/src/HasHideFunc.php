<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

/**
 * Mixin trait for {@see Field} implementations: provides a chainable
 * {@see withHideFunc()} setter and the matching {@see isHidden()}
 * accessor. Field classes `use HasHideFunc;` to opt into the runtime
 * visibility predicate without re-implementing it.
 *
 * The closure is preserved across mutations because each Field's
 * `mutate()` (or per-class clone helper) carries the trait property
 * forward via the immutable-with-pattern.
 *
 * Why `clone` + direct write here instead of threading through mutate()
 * (E736 plan 3.6): the concrete Field classes' `mutate()` signatures know
 * only their own constructor state — trait slots deliberately stay outside
 * that contract so mixing a trait in costs zero edits. The write lands on
 * the fresh clone before it is returned, so no other reference can observe
 * a half-mutated object; the pattern is the trait's one sanctioned
 * direct-write and behaves exactly like a `with*()` copy.
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