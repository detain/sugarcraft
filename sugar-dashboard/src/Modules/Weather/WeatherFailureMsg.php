<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

use SugarCraft\Core\Msg;

/**
 * Carries a failed weather lookup back into update().
 *
 * The fetch used to REJECT its promise on final failure. Rejections are
 * dispatched as ExceptionMsg, which no consumer in this dashboard ever
 * read — so every dead lookup was silent and the panel stayed on its
 * "no data" render forever with no record of why. Resolving a typed
 * message instead gives the failure a home the view can show.
 */
final class WeatherFailureMsg implements Msg
{
    public function __construct(
        public readonly \DateTimeImmutable $attemptedAt,
        public readonly string $reason,
    ) {
    }
}
