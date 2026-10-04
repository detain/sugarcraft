<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * One `sugarcrush.v1` method (Appendix O §6.3): its name, the scope a caller
 * needs, whether it changes anything (and so takes an `idempotencyKey`), what
 * it does, and the handler that does it.
 *
 * The registry of these is the single source for what the server answers,
 * for `server.hello`'s `features.methods`, and for the generated schema — a
 * method exists on the wire exactly when it is registered here.
 */
final class MethodSpec
{
    /**
     * @param \Closure(CallContext, Params): mixed $handler
     */
    private function __construct(
        public readonly string $name,
        public readonly Scope $scope,
        public readonly bool $sideEffects,
        public readonly string $description,
        public readonly \Closure $handler,
    ) {
    }

    /**
     * @param \Closure(CallContext, Params): mixed $handler returns the `result`
     */
    public static function new(string $name, Scope $scope, string $description, \Closure $handler, bool $sideEffects = false): self
    {
        if (\preg_match('/^[a-z]+\.[a-zA-Z]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('A method name is namespace.verb; got "%s".', $name));
        }

        return new self($name, $scope, $sideEffects, $description, $handler);
    }

    /** The method's namespace: `session` of `session.send`. */
    public function namespace(): string
    {
        return \substr($this->name, 0, (int) \strpos($this->name, '.'));
    }
}
