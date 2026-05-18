<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules\Weather;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Modules\Weather\HttpClient;
use SugarCraft\Dash\Modules\Weather\TickMsg;
use SugarCraft\Dash\Modules\Weather\WeatherModule;
use SugarCraft\Dash\Modules\Weather\WeatherSnapshot;

final class WeatherModuleTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/sugar-dash-weather-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    private function removeDirectory(string $dir): void
    {
        $cacheFile = $dir . '/.cache/sugar-dash/weather.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
        if (is_dir($dir . '/.cache/sugar-dash')) {
            @rmdir($dir . '/.cache/sugar-dash');
        }
        if (is_dir($dir . '/.cache')) {
            @rmdir($dir . '/.cache');
        }
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    public function testNameReturnsWeather(): void
    {
        $client = $this->createMock(HttpClient::class);
        $module = new TestableWeatherModule($client, 'test', $this->cacheDir);
        $this->assertSame('weather', $module->name());
    }

    public function testInitReturnsTickCmd(): void
    {
        $client = $this->createMock(HttpClient::class);
        $module = new TestableWeatherModule($client, 'auto', $this->cacheDir);
        $cmd = $module->init();
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testUpdateWithUnknownMsgReturnsSelf(): void
    {
        $client = $this->createMock(HttpClient::class);
        $module = new TestableWeatherModule($client, 'auto', $this->cacheDir);
        $msg = new class implements Msg {};

        $result = $module->update($msg);

        $this->assertIsArray($result);
        [$nextModule, $cmd] = $result;
        $this->assertInstanceOf(WeatherModule::class, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewReturnsUnavailableWhenNoData(): void
    {
        $client = $this->createMock(HttpClient::class);
        $module = new TestableWeatherModule($client, 'auto', $this->cacheDir);

        $view = $module->view();
        $this->assertSame('—°C unavailable', $view);
    }

    public function testMinSize(): void
    {
        $client = $this->createMock(HttpClient::class);
        $module = new TestableWeatherModule($client, 'auto', $this->cacheDir);

        $minSize = $module->minSize();
        $this->assertSame(20, $minSize[0]);
        $this->assertSame(4, $minSize[1]);
    }

    public function testUpdateWithTickMsgFetchesFreshData(): void
    {
        $snapshot = new WeatherSnapshot(22.0, 'Sunny', 'TestCity', new \DateTimeImmutable());

        $mockClient = $this->createMock(HttpClient::class);
        $mockClient->expects($this->once())
            ->method('fetch')
            ->with('auto')
            ->willReturn($snapshot);

        $module = new TestableWeatherModule($mockClient, 'auto', $this->cacheDir);
        $msg = new TickMsg();

        $result = $module->update($msg);

        [$nextModule, $cmd] = $result;
        $this->assertInstanceOf(WeatherModule::class, $nextModule);
        $this->assertNotSame($module, $nextModule);
        $this->assertInstanceOf(\Closure::class, $cmd);

        $view = $nextModule->view();
        $this->assertStringContainsString('22°C', $view);
        $this->assertStringContainsString('Sunny', $view);
        $this->assertStringContainsString('TestCity', $view);
    }

    public function testUpdateWithTickMsgFallsBackToStaleCacheOnNetworkFailure(): void
    {
        $staleSnapshot = new WeatherSnapshot(
            15.0,
            'Cloudy',
            'CachedCity',
            new \DateTimeImmutable('@' . (time() - 3600)),
        );

        $mockClient = $this->createMock(HttpClient::class);
        $mockClient->expects($this->once())
            ->method('fetch')
            ->with('auto')
            ->willThrowException(new \RuntimeException('Network error'));

        $module = new TestableWeatherModule($mockClient, 'auto', $this->cacheDir);
        $module->writeCacheForTest($staleSnapshot);

        $result = $module->update(new TickMsg());
        [$nextModule] = $result;

        $view = $nextModule->view();
        $this->assertStringContainsString('15°C', $view);
        $this->assertStringContainsString('Cloudy', $view);
        $this->assertStringContainsString('CachedCity', $view);
    }

    public function testMultipleUpdatesCreateNewInstances(): void
    {
        $snapshot = new WeatherSnapshot(20.0, 'Clear', 'City', new \DateTimeImmutable());

        $mockClient = $this->createMock(HttpClient::class);
        $mockClient->method('fetch')->willReturn($snapshot);

        $module = new TestableWeatherModule($mockClient, 'auto', $this->cacheDir);
        $msg = new TickMsg();

        [$next1] = $module->update($msg);
        [$next2] = $next1->update($msg);

        $this->assertNotSame($module, $next1);
        $this->assertNotSame($next1, $next2);
    }

    public function testTickMsgInstance(): void
    {
        $msg = new TickMsg();
        $this->assertInstanceOf(Msg::class, $msg);
    }
}

/**
 * TestableWeatherModule — overrides cachePath to use a per-test temp directory.
 */
final class TestableWeatherModule extends WeatherModule
{
    private string $testCacheDir;

    public function __construct(HttpClient $httpClient, string $location, string $testCacheDir)
    {
        parent::__construct($httpClient, $location);
        $this->testCacheDir = $testCacheDir;
    }

    protected function cachePath(): string
    {
        return $this->testCacheDir . '/.cache/sugar-dash/weather.json';
    }

    public function writeCacheForTest(WeatherSnapshot $snapshot): void
    {
        $dir = dirname($this->cachePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = $this->cachePath();
        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        @file_put_contents($path, $json, LOCK_EX);
    }
}
