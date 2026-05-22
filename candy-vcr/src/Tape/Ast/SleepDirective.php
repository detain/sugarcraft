<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Sleep <duration> directive — advances virtual clock without emitting bytes.
 * Duration suffixes: s (seconds), ms (milliseconds), m (minutes).
 */
final readonly class SleepDirective implements Directive
{
    public function __construct(
        public float $seconds,
    ) {
    }
}
