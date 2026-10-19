<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * The dark-default sink: swallows every event, allocates nothing, performs
 * no I/O. Returned by {@see OtelConfig::sinkFromEnvironment()} when no OTLP
 * endpoint is configured, so an unconfigured process pays literally one
 * no-op method call per folded event and nothing else.
 */
final class NullEventSink implements EventSink
{
    public function consume(object $event): void
    {
        // Deliberately empty: this IS the feature.
    }

    public function flush(): void
    {
        // Deliberately empty.
    }
}
