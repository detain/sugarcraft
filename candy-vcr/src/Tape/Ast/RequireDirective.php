<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * `Require <module>` — upstream's tape-precondition loader (`Require git`
 * asserts the toolchain dependency). candy-vcr compiles tapes with no
 * package manager to consult, so the requirement is recorded as a loud
 * diagnostic (warn / strict-abort) rather than the old silent drop
 * (finding 005).
 */
final readonly class RequireDirective implements Directive
{
    public function __construct(
        public string $module,
    ) {
    }
}
