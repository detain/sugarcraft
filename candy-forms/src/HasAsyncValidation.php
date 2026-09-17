<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Public face of async validation (plan 5.2, round 89 lane y3) for
 * {@see AsyncValidatable} fields.
 *
 * Storage law (E741): the slot itself lives in {@see CarriesNonCtorState}
 * — every `new self(...)` rebuild funnels through the shared carrier, which
 * copies the closure — so this trait only adds the fluent setter, the bare
 * accessor and the promise-producing validator. A class using this trait
 * MUST therefore also use {@see CarriesNonCtorState} (the census in
 * TraitStateCarryFamilyTest fails closed otherwise) and SHOULD implement
 * {@see AsyncValidatable}.
 *
 * The attached closure receives the field's current value and MUST return
 * `PromiseInterface<?string>` — resolving with an error message or null.
 * Anything else is a programming error and surfaces as a REJECTION naming
 * the offending key, never as a silent pass.
 */
trait HasAsyncValidation
{
    /**
     * Attach the async leg of validation. Replaces any previous async
     * validator; the synchronous validators stay in place and keep running
     * first.
     *
     * @param \Closure(mixed):PromiseInterface<?string> $fn
     */
    public function withAsyncValidator(\Closure $fn): static
    {
        $next             = clone $this;
        $next->asyncValidator = $fn;
        return $next;
    }

    public function asyncValidator(): ?\Closure
    {
        return $this->asyncValidator;
    }

    /**
     * Synchronous gate first, async leg second (see the interface docblock
     * for why). With no async validator attached this is exactly
     * `resolve(revalidate()->getError())` — the async view of a purely
     * synchronous field.
     *
     * Mirrors no upstream symbol: huh has no async surface (SugarCraft
     * extension, plan 5.2).
     *
     * @return PromiseInterface<?string>
     */
    public function validateAsync(): PromiseInterface
    {
        $sync = $this->revalidate()->getError();
        if ($sync !== null && $sync !== '') {
            return \React\Promise\resolve($sync);
        }

        $fn = $this->asyncValidator;
        if ($fn === null) {
            return \React\Promise\resolve(null);
        }

        try {
            $promise = $fn($this->value());
            if (!$promise instanceof PromiseInterface) {
                throw new \InvalidArgumentException(Lang::t('validation.async_return_type', [
                    'key'  => $this->key(),
                    'type' => get_debug_type($promise),
                ]));
            }
        } catch (\Throwable $e) {
            $failed = new Deferred();
            $failed->reject($e);
            return $failed->promise();
        }

        $key = $this->key();
        return $promise->then(static function (mixed $result) use ($key): ?string {
            if ($result === null || \is_string($result)) {
                return $result;
            }
            throw new \InvalidArgumentException(Lang::t('validation.async_result_type', [
                'key'  => $key,
                'type' => get_debug_type($result),
            ]));
        });
    }
}
