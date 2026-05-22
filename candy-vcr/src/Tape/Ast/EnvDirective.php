<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Env KEY "value" directive — exports environment variables to the child process.
 */
final readonly class EnvDirective implements Directive
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
