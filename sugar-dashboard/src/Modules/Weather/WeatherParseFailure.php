<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

/**
 * The bytes arrived but are not a wttr.in j1 document: invalid JSON or a
 * shape the parser refuses.
 *
 * See {@see WeatherTransportFailure} for the reason taxonomy — the two
 * classes were split out of one collapsed RuntimeException message so the
 * failure line names the failing layer (D-109).
 */
final class WeatherParseFailure extends \RuntimeException
{
}
