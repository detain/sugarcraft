<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Core\Util\Sanitize;

/**
 * Weather module that fetches live data from wttr.in and falls back to cache.
 *
 * Ticks every $tickSeconds (half an hour by default); falls back to the
 * stale disk cache when a lookup fails, and records the failure reason
 * when there is nothing stale to show. A cold start fetches immediately
 * instead of waiting a full interval to consult the cache.
 */
class WeatherModule extends BaseModule
{
    private const TICK_INTERVAL = 1800.0;
    private const TTL_SECONDS = 1800;

    private ?WeatherSnapshot $current;
    private \DateTimeImmutable $lastFetch;

    /** Reason of the most recent failed lookup, or null if none failed. */
    private ?string $lastFailure = null;

    /** When the most recent lookup was attempted (success or failure). */
    private ?\DateTimeImmutable $lastAttempt = null;

    /**
     * @param float $tickSeconds refresh cadence; @param int $ttlSeconds
     *     cache-freshness window. Both were frozen class constants before.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $location = 'auto',
        private readonly float $tickSeconds = self::TICK_INTERVAL,
        private readonly int $ttlSeconds = self::TTL_SECONDS,
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
        // Cold boot consults cache/HTTP immediately (the send) and only
        // then arms the periodic tick. Arming the tick alone meant a
        // freshly launched dashboard showed no weather for a full
        // interval even when a perfectly good cache file was on disk.
        return Cmd::batch(
            Cmd::send(new TickMsg()),
            $this->makeTickCmd(),
        );
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg) {
            // Fast path: fresh cache on disk — apply immediately, no I/O
            // beyond the cache read.
            $cached = $this->loadCache();
            if ($cached !== null) {
                $age = time() - $cached->fetchedAt->getTimestamp();
                if ($age < $this->ttlSeconds) {
                    $next = $this->withSnapshot($cached);
                    return [$next, $this->makeTickCmd()];
                }
            }

            // Cache miss or stale — run the HTTP fetch. The fetch itself
            // is synchronous inside the command the program dispatches,
            // bounded by the HTTP client's own timeout; the promise only
            // adapts the settled result to message dispatch. It does NOT
            // run off the event loop.
            // Stale-cache fallback happens inside the fetch (see
            // fetchWeatherHttp); final failures resolve a WeatherFailureMsg.
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
            $next = $this->withSnapshot($msg->snapshot);
            return [$next, null];
        }

        if ($msg instanceof WeatherFailureMsg) {
            $clone = clone $this;
            $clone->lastAttempt = $msg->attemptedAt;
            $clone->lastFailure = $msg->reason;
            return [$clone, null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->current === null) {
            if ($this->lastFailure === null) {
                // Nothing has been attempted (or every attempt is still in
                // flight) — say "loading", not a false "unavailable".
                return '—°C loading…';
            }

            return sprintf(
                '—°C unavailable (last attempt failed: %s)',
                Sanitize::untrusted($this->clip($this->lastFailure)),
            );
        }

        $temp = $this->current->tempC;
        $condition = $this->current->condition;
        $location = $this->current->location;

        return sprintf(
            "%.0f°C %s\n%s (%s)",
            $temp,
            Sanitize::untrusted($condition),
            Sanitize::untrusted($location),
            $this->ageLabel(),
        );
    }

    /**
     * Humanised age of the displayed snapshot. A stale-cache fallback and a
     * fresh fetch are no longer rendered identically — the age is the truth
     * the panel was hiding.
     */
    private function ageLabel(): string
    {
        $age = max(0, time() - $this->lastFetch->getTimestamp());

        if ($age < 90) {
            return 'just now';
        }

        if ($age < 5400) {
            return intdiv($age, 60) . ' min ago';
        }

        return intdiv($age, 86400) . ' d ' . intdiv($age % 86400, 3600) . ' h ago';
    }

    private function clip(string $text): string
    {
        return strlen($text) > 80 ? substr($text, 0, 80) . '…' : $text;
    }

    public function minSize(): array
    {
        return [20, 4];
    }

    private function withSnapshot(WeatherSnapshot $snapshot): static
    {
        $clone = clone $this;
        $clone->current = $snapshot;
        // The snapshot carries when IT was fetched — trusting that (rather
        // than stamping "now") is what keeps the displayed age honest when
        // a stale cache or a network fallback path supplies it.
        $clone->lastFetch = $snapshot->fetchedAt;
        $clone->lastFailure = null;
        $clone->lastAttempt = $snapshot->fetchedAt;
        return $clone;
    }

    /**
     * Fetch weather from HTTP, cache on success.
     *
     * Returns a promise that resolves to WeatherResultMsg on success (or
     * stale-cache fallback) and to WeatherFailureMsg when nothing is left
     * to show. Never throws synchronously and never rejects: rejections
     * surfaced as ExceptionMsg, which no consumer read, so final failures
     * used to vanish silently.
     *
     * @return \React\Promise\PromiseInterface<Msg>
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
            // snapshot so the view still shows data. No stale cache: record
            // the failure as a typed message instead of a silent rejection.
            $cached = $this->loadCache();
            if ($cached !== null) {
                $deferred->resolve(new WeatherResultMsg($cached));
            } else {
                $deferred->resolve(new WeatherFailureMsg(
                    new \DateTimeImmutable(),
                    $e->getMessage(),
                ));
            }
        }

        return $deferred->promise();
    }

    private function makeTickCmd(): ?\Closure
    {
        return $this->selfTickCmd();
    }

    protected function tickIntervalSeconds(): ?float
    {
        return $this->tickSeconds;
    }

    protected function tickProducer(): ?\Closure
    {
        static $produce = null;
        return $produce ??= static fn(): Msg => new TickMsg();
    }

    private function loadCache(): ?WeatherSnapshot
    {
        $file = AtomicJsonFile::new($this->cachePath())->withPermissions(0600);
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
        // abort a successful fetch, so failures are swallowed. The payload
        // carries location data, so it publishes 0600 (created dirs derive
        // 0700) rather than inheriting the process umask's guess.
        try {
            AtomicJsonFile::new($this->cachePath())
                ->withPermissions(0600)
                ->write($snapshot->toArray());
        } catch (\Throwable) {
            // ignore — stale/missing cache is acceptable
        }
    }

    /**
     * Cache file location. Protected as a TEST SEAM only — the suite
     * subclasses this class to redirect the cache into a per-test temp home
     * (see tests/Modules/Weather/WeatherModuleTest.php); it is not an
     * extension point for consumers.
     */
    protected function cachePath(): string
    {
        // getenv('HOME') is more portable than $_SERVER['HOME'] across CGI/FastCGI
        $home = getenv('HOME') ?: $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? sys_get_temp_dir();
        return $home . '/.cache/sugar-dashboard/weather.json';
    }
}
