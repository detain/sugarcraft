<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Elm-style subscription value object.
 *
 * A subscription declares the program's intent to receive recurring events
 * (ticks, key events, signals). The runtime reconciles the subscription
 * set after each update cycle — new subscriptions are started, dropped
 * ones are cancelled, stable ones are kept.
 */
final class Subscription
{
    /**
     * @param non-empty-string $id       Unique identifier for reconciliation
     * @param Kind            $kind     The category of subscription
     * @param array<string,mixed> $params Kind-specific parameters — the schema
     *     Program::startSubscription actually reads (sc-35/DX-14, surfaced
     *     from the sc-08 engine): Tick → `seconds` (required, > 0);
     *     Key → `interval` (poll seconds, defaulted to 0.1 by
     *     {@see Subscriptions::withKey()}); Signal → `signo`; Custom →
     *     `interval` (defaults to 1.0). Hand-build callables (the raw
     *     constructor bypasses a factory) must supply these keys in this
     *     shape or the named InvalidArgumentException fires at arm time.
     * @param \Closure(): Msg  $produce Produces the Msg when the event fires
     */
    public function __construct(
        public readonly string $id,
        public readonly Kind $kind,
        public readonly array $params,
        public readonly \Closure $produce,
    ) {
    }
}
