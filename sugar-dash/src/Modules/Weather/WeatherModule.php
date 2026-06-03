<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Weather;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

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
            $snapshot = $this->fetchWeather();
            $next = $this->withSnapshot($snapshot, new \DateTimeImmutable());
            return [$next, Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new TickMsg())];
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

        return sprintf("%.0f°C %s\n%s", $temp, $condition, $location);
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

    private function fetchWeather(): WeatherSnapshot
    {
        $cached = $this->loadCache();
        if ($cached !== null) {
            $age = time() - $cached->fetchedAt->getTimestamp();
            if ($age < self::TTL_SECONDS) {
                return $cached;
            }
        }

        try {
            $snapshot = $this->httpClient->fetch($this->location);
            $this->saveCache($snapshot);
            return $snapshot;
        } catch (\RuntimeException $e) {
            if ($cached !== null) {
                return $cached;
            }
            throw $e;
        }
    }

    private function loadCache(): ?WeatherSnapshot
    {
        $path = $this->cachePath();
        // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
        if (!is_file($path)) {
            return null;
        }

        // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        /** @var array<string, mixed>|null */
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        try {
            return WeatherSnapshot::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveCache(WeatherSnapshot $snapshot): void
    {
        $dir = dirname($this->cachePath());
        // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = $this->cachePath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
            @unlink($tmp);
            return;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    protected function cachePath(): string
    {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '/tmp';
        return $home . '/.cache/sugar-dash/weather.json';
    }
}
