<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Weather;

use SugarCraft\Core\Msg;

/**
 * Async result of an off-loop weather fetch.
 *
 * Carries the fetched (or cached-fallback) snapshot back into
 * the update loop so the module can apply it without blocking
 * the event loop on HTTP I/O.
 */
final readonly class WeatherResultMsg implements Msg
{
    public function __construct(
        public WeatherSnapshot $snapshot,
    ) {
    }
}
