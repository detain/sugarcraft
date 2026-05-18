<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Weather;

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
     * @throws \RuntimeException on network failure
     * @return WeatherSnapshot
     */
    public function fetch(string $location): WeatherSnapshot;
}
