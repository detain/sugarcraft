<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Internal sentinel returned by the closure from {@see Cmd::every()}. The
 * Program intercepts it and attaches `$produce` to the SHARED periodic
 * timer chain for `$period` — exactly one timer chain per distinct
 * interval, fanned out to every registered consumer (audit
 * sugar-core-004: the old shape armed an independent one-shot re-arm per
 * subscription and the "share a single tick" docblock was fiction).
 *
 * A producer that returns `null` steps down from its seat; the chain
 * tears itself down when the last consumer leaves. Not part of the
 * user-facing Msg surface.
 *
 * @internal
 */
final class EveryRequest implements Msg
{
    /** @param \Closure(\DateTimeImmutable): ?Msg $produce */
    public function __construct(
        public readonly float $period,
        public readonly \Closure $produce,
    ) {
    }
}
