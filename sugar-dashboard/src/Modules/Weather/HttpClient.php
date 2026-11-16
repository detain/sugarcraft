<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

/**
 * Abstract HTTP fetch interface for weather data.
 *
 * Allows tests to mock the network layer and verify cache behaviour
 * without hitting a real API.
 */
interface HttpClient
{
    /**
     * Fetch the current weather for the given location.
     *
     * @param string $location e.g. "Seattle" or "auto" for IP-based detection
     * @throws WeatherTransportFailure when the network channel failed
     *     (open, mid-stream abort, or over-cap reply)
     * @throws WeatherParseFailure when the reply is not a wttr.in j1 document
     * @return WeatherSnapshot
     */
    public function fetch(string $location): WeatherSnapshot;
}
