<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Weather;

/**
 * Fetches weather data from wttr.in.
 *
 * Uses the J1 JSON endpoint: https://wttr.in/<location>?format=j1
 *
 * @see https://github.com/chubin/wttr.in#json-api
 */
final class WttrInClient implements HttpClient
{
    private const BASE_URL = 'https://wttr.in/%s?format=j1';

    public function fetch(string $location): WeatherSnapshot
    {
        $url = sprintf(self::BASE_URL, rawurlencode($location));

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'user_agent' => 'SugarCraft/1.0 weather-module',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException("Failed to fetch weather from wttr.in for location: {$location}");
        }

        /** @var array<string, mixed> */
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        return $this->parse($data, $location);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parse(array $data, string $requestedLocation): WeatherSnapshot
    {
        $current = $data['current_condition'][0] ?? [];

        $tempC = (float) ($current['temp_C'] ?? 0);
        $condition = $this->extractCondition($current);
        $location = $this->extractLocation($data, $requestedLocation);

        return new WeatherSnapshot(
            tempC: $tempC,
            condition: $condition,
            location: $location,
            fetchedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, mixed> $current
     */
    private function extractCondition(array $current): string
    {
        $desc = $current['weatherDesc'][0]['value'] ?? null;
        if (is_string($desc)) {
            return $desc;
        }
        return 'Unknown';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractLocation(array $data, string $requested): string
    {
        $area = $data['nearest_area'][0] ?? null;
        if ($area === null) {
            return $requested === 'auto' ? 'Unknown' : $requested;
        }

        $areaName = $area['areaName'][0]['value'] ?? null;
        if (is_string($areaName)) {
            return $areaName;
        }

        $region = $area['region'][0]['value'] ?? null;
        if (is_string($region)) {
            return $region;
        }

        return $requested === 'auto' ? 'Unknown' : $requested;
    }
}
