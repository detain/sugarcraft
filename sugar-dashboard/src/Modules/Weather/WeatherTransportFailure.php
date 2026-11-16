<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

/**
 * The network channel itself failed: the stream could not be opened, the
 * reply was aborted, or it exceeded the size cap.
 *
 * Distinct from {@see WeatherParseFailure} on purpose — the panel's
 * "last attempt failed" line must tell the operator WHY (D-109): a
 * transport failure usually heals on the next tick, a parse failure means
 * the endpoint changed under us and will not.
 */
final class WeatherTransportFailure extends \RuntimeException
{
}
