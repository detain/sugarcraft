<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Weather;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Dash\Output\Sanitize;

/**
 * Weather module that fetches live data from wttr.in and falls back to cache.
 *
 * Mirrors the lattice weather module pattern.
 * Ticks every 30 minutes; falls back to stale cache on network failure.
 */
class WeatherModule extends BaseModule
{
    private const TICK_INTERVAL = 1800.0;
    private const TTL_SECONDS = 1800;

    private ?WeatherSnapshot $current;
    private \DateTimeImmutable $lastFetch;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $location = 'auto',
    ) {
        $this->current = null;
        $this->lastFetch = new \DateTimeImmutable('@0');
    }

    public function name(): string
    {
        return 'weather';
    }

    public function init(): ?\Closure
    {
        return Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg) {
            // Fast path: fresh in-memory cache — apply immediately, no I/O.
            $cached = $this->loadCache();
            if ($cached !== null) {
                $age = time() - $cached->fetchedAt->getTimestamp();
                if ($age < self::TTL_SECONDS) {
                    $next = $this->withSnapshot($cached, $cached->fetchedAt);
                    return [$next, $this->makeTickCmd()];
                }
            }

            // Cache miss or stale — schedule async HTTP fetch off the loop.
            // Fallback to stale cache on rejection (network failure).
            return [
                $this,
                Cmd::batch(
                    Cmd::promise(function (): \React\Promise\PromiseInterface {
                        return $this->fetchWeatherHttp();
                    }),
                    $this->makeTickCmd(),
                ),
            ];
        }

        if ($msg instanceof WeatherResultMsg) {
            $next = $this->withSnapshot($msg->snapshot, new \DateTimeImmutable());
            return [$next, null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->current === null) {
            return "—°C unavailable";
        }

        $temp = $this->current->tempC;
        $condition = $this->current->condition;
        $location = $this->current->location;

        return sprintf(
            "%.0f°C %s\n%s",
            $temp,
            Sanitize::untrusted($condition),
            Sanitize::untrusted($location)
        );
    }

    public function minSize(): array
    {
        return [20, 4];
    }

    private function withSnapshot(WeatherSnapshot $snapshot, \DateTimeImmutable $lastFetch): static
    {
        $clone = clone $this;
        $clone->current = $snapshot;
        $clone->lastFetch = $lastFetch;
        return $clone;
    }

    /**
     * Fetch weather from HTTP, cache on success.
     *
     * Returns a promise that resolves to WeatherResultMsg or rejects on
     * failure when no stale cache exists.  Never throws synchronously —
     * all errors surface as promise rejections handled by Cmd::promise.
     *
     * @return \React\Promise\PromiseInterface<WeatherResultMsg>
     */
    private function fetchWeatherHttp(): \React\Promise\PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();

        try {
            $snapshot = $this->httpClient->fetch($this->location);
            $this->saveCache($snapshot);
            $deferred->resolve(new WeatherResultMsg($snapshot));
        } catch (\Throwable $e) {
            // Network failure with stale cache available — resolve with stale
            // snapshot so the view still shows data. No stale cache: reject.
            $cached = $this->loadCache();
            if ($cached !== null) {
                $deferred->resolve(new WeatherResultMsg($cached));
            } else {
                $deferred->reject($e);
            }
        }

        return $deferred->promise();
    }

    private function makeTickCmd(): \Closure
    {
        return Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new TickMsg());
    }

    private function loadCache(): ?WeatherSnapshot
    {
        $file = AtomicJsonFile::new($this->cachePath());
        if (!$file->exists()) {
            return null;
        }

        // Cache read is best-effort: a corrupt or unreadable cache file must
        // degrade to "no data", never surface as an exception up the tick path.
        try {
            return WeatherSnapshot::fromArray($file->read());
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveCache(WeatherSnapshot $snapshot): void
    {
        // Cache write is best-effort: a full disk / permission error must not
        // abort a successful fetch, so failures are swallowed.
        try {
            AtomicJsonFile::new($this->cachePath())->write($snapshot->toArray());
        } catch (\Throwable) {
            // ignore — stale/missing cache is acceptable
        }
    }

    protected function cachePath(): string
    {
        // getenv('HOME') is more portable than $_SERVER['HOME'] across CGI/FastCGI
        $home = getenv('HOME') ?: $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? sys_get_temp_dir();
        return $home . '/.cache/sugar-dash/weather.json';
    }
}
