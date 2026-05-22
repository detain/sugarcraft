<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Wait <duration> directive — waits for a condition or timeout.
 * Deferred: v2 scope.
 */
final readonly class WaitDirective implements Directive
{
    public function __construct(
        public float $seconds,
    ) {
    }
}
