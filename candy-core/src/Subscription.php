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
 *
 * @internal
 */
final class Subscription
{
    /**
     * @param non-empty-string $id       Unique identifier for reconciliation
     * @param Kind            $kind     The category of subscription
     * @param array<string,mixed> $params Kind-specific parameters
     * @param \Closure(): Msg  $produce Produces the Msg when the event fires
     */
    public function __construct(
        public readonly string $id,
        public readonly Kind $kind,
        public readonly array $params,
        public readonly \Closure $produce,
    ) {}
}
