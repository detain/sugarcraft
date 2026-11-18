<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Modules\Weather;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Async\Await;
use SugarCraft\Dashboard\Modules\Weather\HttpClient;
use SugarCraft\Dashboard\Modules\Weather\WeatherFailureMsg;
use SugarCraft\Dashboard\Modules\Weather\WeatherModule;
use SugarCraft\Dashboard\Modules\Weather\WeatherResultMsg;
use SugarCraft\Dashboard\Modules\Weather\WeatherSnapshot;
use SugarCraft\Dashboard\Modules\Weather\WeatherTransportFailure;
use SugarCraft\Dashboard\Msg\TickMsg;

/**
 * P1-5 pin: the weather fetch runs in a forked child, so the update door returns
 * at once while the client is still waiting on the network, and the reply (or
 * the failure reason) reaches the model through the promise.
 */
final class WeatherFetchOffLoopTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/sugar-dashboard-weather-offloop-' . bin2hex(random_bytes(4));
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the off-loop weather fetch needs ext-pcntl and ext-posix');
        }
    }

    protected function tearDown(): void
    {
        $dir = $this->cacheDir . '/.cache/sugar-dashboard';
        if (is_dir($dir)) {
            foreach ((array) scandir($dir) as $entry) {
                if (is_string($entry) && is_file($dir . '/' . $entry)) {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
        @rmdir($this->cacheDir . '/.cache');
        @rmdir($this->cacheDir);
    }

    /** Start a fetch through the public update door and return the promise Cmd's AsyncCmd. */
    private function startFetch(WeatherModule $module): \SugarCraft\Core\AsyncCmd
    {
        [, $cmd] = $module->update(TickMsg::for('weather'));
        $batch = $cmd();

        return ($batch->cmds[0])();
    }

    public function testTheUpdateDoorDoesNotWaitForTheNetwork(): void
    {
        $client = new class (1.5) implements HttpClient {
            public function __construct(private readonly float $seconds)
            {
            }

            public function fetch(string $location): WeatherSnapshot
            {
                usleep((int) ($this->seconds * 1_000_000));

                return new WeatherSnapshot(12.4, 'Drizzle', 'Bergen', new \DateTimeImmutable());
            }
        };
        $module = new OffLoopWeatherModule($client, $this->cacheDir);

        $start = microtime(true);
        $async = $this->startFetch($module);
        $elapsed = microtime(true) - $start;

        self::assertLessThan(
            1.0,
            $elapsed,
            'the fetch child is forked, so starting the fetch does not hold the loop for the 1.5 s reply',
        );

        $msg = Await::until($async->promise, 15.0, Loop::get());
        self::assertInstanceOf(WeatherResultMsg::class, $msg);
        self::assertSame('Drizzle', $msg->snapshot->condition);
        self::assertSame('Bergen', $msg->snapshot->location);
    }

    public function testAFailedFetchCarriesTheClientsReasonToTheModel(): void
    {
        $client = new class implements HttpClient {
            public function fetch(string $location): WeatherSnapshot
            {
                throw new WeatherTransportFailure('scripted down');
            }
        };
        $module = new OffLoopWeatherModule($client, $this->cacheDir);

        $msg = Await::until($this->startFetch($module)->promise, 15.0, Loop::get());

        self::assertInstanceOf(WeatherFailureMsg::class, $msg);
        self::assertStringContainsString('scripted down', $msg->reason);
    }
}

/** Redirects the cache into the test's temp home; cachePath() is the module's documented test seam. */
final class OffLoopWeatherModule extends WeatherModule
{
    public function __construct(HttpClient $httpClient, private readonly string $testCacheDir)
    {
        parent::__construct($httpClient, 'auto');
    }

    protected function cachePath(): string
    {
        return $this->testCacheDir . '/.cache/sugar-dashboard/weather.json';
    }
}
