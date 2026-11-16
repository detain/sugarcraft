<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Modules\Weather;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Msg\TickMsg;
use SugarCraft\Dashboard\Modules\Weather\HttpClient;
use SugarCraft\Dashboard\Modules\Weather\WeatherFailureMsg;
use SugarCraft\Dashboard\Modules\Weather\WeatherModule;
use SugarCraft\Dashboard\Modules\Weather\WeatherResultMsg;
use SugarCraft\Dashboard\Modules\Weather\WeatherSnapshot;

/**
 * D-109 module-door pins: the wall clock is read at the update door only
 * (ClockModule::timeBuildCount seam pattern), view() is byte-idempotent
 * across repaints, and an unmeasured temperature renders the honest
 * UNMEASURED pattern instead of a confident 0°C.
 */
final class WeatherModuleIdempotenceTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/sugar-dashboard-weather-idem-' . bin2hex(random_bytes(4));
        WeatherModule::resetWallReadCountForTesting();
    }

    protected function tearDown(): void
    {
        $path = $this->cacheDir . '/.cache/sugar-dashboard';
        if (is_dir($path)) {
            foreach ((array) scandir($path) as $entry) {
                if (is_string($entry) && is_file($path . '/' . $entry)) {
                    @unlink($path . '/' . $entry);
                }
            }
            @rmdir($path);
        }
        @rmdir($this->cacheDir . '/.cache');
        @rmdir($this->cacheDir);
    }

    private function module(?HttpClient $client = null): SeamTestableWeatherModule
    {
        return new SeamTestableWeatherModule($client ?? $this->createMock(HttpClient::class), $this->cacheDir);
    }

    public function testViewIsByteIdempotentAndReadsNoClock(): void
    {
        $module = $this->module();
        $snapshot = new WeatherSnapshot(12.4, 'Drizzle', 'Bergen', new \DateTimeImmutable());
        [$next,] = $module->update(new WeatherResultMsg($snapshot));
        self::assertInstanceOf(WeatherModule::class, $next);

        WeatherModule::resetWallReadCountForTesting();

        $first = $next->view();
        $second = $next->view();
        $third = $next->view();

        $this->assertSame($first, $second, 'repaint must not change a byte');
        $this->assertSame($first, $third);
        $this->assertStringContainsString('12°C Drizzle', $first, 'measured temps keep the rounded pattern');
        $this->assertSame(0, WeatherModule::wallReadCountForTesting(), 'view() must never read the wall clock');
    }

    public function testTickWithFreshCacheSamplesTheWallOnce(): void
    {
        $module = $this->module();
        $module->seedFreshCache(new WeatherSnapshot(3.0, 'Snow', 'Oslo', new \DateTimeImmutable('-2 minutes')));

        [$next,] = $module->update(TickMsg::for('weather'));

        $this->assertInstanceOf(WeatherModule::class, $next);
        $this->assertSame(1, WeatherModule::wallReadCountForTesting(), 'one wall sample per update door, not per repaint');
        $this->assertStringContainsString('2 min ago', $next->view());
        $this->assertSame(1, WeatherModule::wallReadCountForTesting(), 'the repaint above added no sample');
    }

    public function testFailureDoorSamplesNothing(): void
    {
        // A failure carries no snapshot age (the unavailable branch renders
        // the reason, not the clock) — the door must stay sample-free.
        $module = $this->module();

        [$next,] = $module->update(new WeatherFailureMsg(new \DateTimeImmutable(), 'transport: scripted down'));

        $this->assertInstanceOf(WeatherModule::class, $next);
        $this->assertSame(0, WeatherModule::wallReadCountForTesting());
    }

    public function testUnmeasuredTempRendersTheDashPatternNotZero(): void
    {
        $module = $this->module();
        [$next,] = $module->update(new WeatherResultMsg(
            new WeatherSnapshot(null, 'Whiteout', 'Nova Station', new \DateTimeImmutable())
        ));

        $view = $next->view();

        $this->assertStringContainsString('—°C Whiteout', $view, 'null reading shows the UNMEASURED pattern');
        $this->assertStringNotContainsString('0°C', $view, 'a hiccup must never render as a confident zero');
    }

    public function testNullTempRoundTripsThroughTheCache(): void
    {
        $module = $this->module();
        $module->seedFreshCache(new WeatherSnapshot(null, 'Haze', 'Doha', new \DateTimeImmutable('-30 seconds')));

        [$next,] = $module->update(TickMsg::for('weather'));
        $loaded = $this->invokePrivate($next, 'loadCache', []);

        $this->assertInstanceOf(WeatherSnapshot::class, $loaded);
        $this->assertNull($loaded->tempC, 'an unmeasured reading stays unmeasured on disk too');
    }

    public function testRenderPathCarriesNoRawWallRead(): void
    {
        // Counter pin cannot catch a raw time() that bypasses sampleWall()
        // (mutation M3 stayed green otherwise) — the source-leg guard is
        // the lib's own sanctioned pattern (GaugeCircleTest/MeterTest
        // substr_count source pins): WeatherModule may read the wall clock
        // EXACTLY once, inside sampleWall(), nowhere else.
        $src = (string) file_get_contents((new \ReflectionClass(WeatherModule::class))->getFileName());

        $this->assertSame(
            1,
            substr_count($src, 'time()'),
            'the ONLY time() call in the module lives in sampleWall(); view()/ageLabel() must stay clock-free'
        );
        $this->assertStringContainsString('private function sampleWall(): int', $src);
        $this->assertMatchesRegularExpression('/private function sampleWall\(\): int\s*\{[^}]*time\(\)/', $src, 'the single time() read sits inside sampleWall()');
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }
}

/**
 * Local cachePath seam host (the sibling test file's TestableWeatherModule
 * keeps its own name; this file defines its own to stay independently
 * runnable).
 */
final class SeamTestableWeatherModule extends WeatherModule
{
    public function __construct(HttpClient $httpClient, private readonly string $testCacheDir)
    {
        parent::__construct($httpClient, 'auto');
    }

    protected function cachePath(): string
    {
        return $this->testCacheDir . '/.cache/sugar-dashboard/weather.json';
    }

    public function seedFreshCache(WeatherSnapshot $snapshot): void
    {
        $dir = dirname($this->cachePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($this->cachePath(), json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));
    }
}
