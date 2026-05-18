<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules\Weather;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Modules\Weather\HttpClient;
use SugarCraft\Dash\Modules\Weather\TickMsg;
use SugarCraft\Dash\Modules\Weather\WeatherModule;
use SugarCraft\Dash\Modules\Weather\WeatherSnapshot;
use SugarCraft\Dash\Modules\Weather\WttrInClient;

final class WttrInClientTest extends TestCase
{
    public function testWttrInClientImplementsHttpClient(): void
    {
        $client = new WttrInClient();
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testParseResponseStructure(): void
    {
        $client = new WttrInClient();

        $data = [
            'current_condition' => [
                [
                    'temp_C' => '18',
                    'weatherDesc' => [['value' => 'Partly cloudy']],
                ],
            ],
            'nearest_area' => [
                [
                    'areaName' => [['value' => 'Seattle']],
                    'region' => [['value' => 'Washington']],
                ],
            ],
        ];

        $method = new \ReflectionMethod($client, 'parse');
        $method->setAccessible(true);

        $snapshot = $method->invoke($client, $data, 'auto');

        $this->assertInstanceOf(WeatherSnapshot::class, $snapshot);
        $this->assertSame(18.0, $snapshot->tempC);
        $this->assertSame('Partly cloudy', $snapshot->condition);
        $this->assertSame('Seattle', $snapshot->location);
    }

    public function testParseWithMissingAreaNameFallsBackToRegion(): void
    {
        $client = new WttrInClient();

        $data = [
            'current_condition' => [
                [
                    'temp_C' => '20',
                    'weatherDesc' => [['value' => 'Sunny']],
                ],
            ],
            'nearest_area' => [
                [
                    'region' => [['value' => 'California']],
                ],
            ],
        ];

        $method = new \ReflectionMethod($client, 'parse');
        $method->setAccessible(true);

        $snapshot = $method->invoke($client, $data, 'auto');

        $this->assertSame('California', $snapshot->location);
    }

    public function testParseWithMissingAllLocationDataUsesRequested(): void
    {
        $client = new WttrInClient();

        $data = [
            'current_condition' => [
                [
                    'temp_C' => '25',
                    'weatherDesc' => [['value' => 'Clear']],
                ],
            ],
            'nearest_area' => [],
        ];

        $method = new \ReflectionMethod($client, 'parse');
        $method->setAccessible(true);

        $snapshot = $method->invoke($client, $data, 'Seattle');

        $this->assertSame('Seattle', $snapshot->location);
    }

    public function testParseUnknownConditionDefaults(): void
    {
        $client = new WttrInClient();

        $data = [
            'current_condition' => [
                [
                    'temp_C' => '10',
                ],
            ],
            'nearest_area' => [],
        ];

        $method = new \ReflectionMethod($client, 'parse');
        $method->setAccessible(true);

        $snapshot = $method->invoke($client, $data, 'auto');

        $this->assertSame('Unknown', $snapshot->condition);
    }

    public function testWeatherSnapshotSerializeRoundtrip(): void
    {
        $original = new WeatherSnapshot(
            tempC: 21.5,
            condition: 'Overcast',
            location: 'Portland',
            fetchedAt: new \DateTimeImmutable('2026-05-18T12:00:00+00:00'),
        );

        $array = $original->toArray();
        $restored = WeatherSnapshot::fromArray($array);

        $this->assertSame($original->tempC, $restored->tempC);
        $this->assertSame($original->condition, $restored->condition);
        $this->assertSame($original->location, $restored->location);
        $this->assertSame($original->fetchedAt->format(\DateTimeInterface::ATOM), $restored->fetchedAt->format(\DateTimeInterface::ATOM));
    }
}
