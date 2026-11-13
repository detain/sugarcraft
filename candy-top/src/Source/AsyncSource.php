<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source;

use React\Promise\PromiseInterface;

/**
 * A {@see Source} that can sample without blocking the loop. {@see isAsync()}
 * is the honest switch: false means `sampleAsync()` would only wrap the
 * blocking `sample()`, and a panel then keeps its synchronous Cmd (so the
 * Linux path and every fixture-driven test stay byte-identical).
 */
interface AsyncSource extends Source
{
    public function isAsync(): bool;

    /** @return PromiseInterface<array{0: object, 1: Source}> */
    public function sampleAsync(): PromiseInterface;
}
