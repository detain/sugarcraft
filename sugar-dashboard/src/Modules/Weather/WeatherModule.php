<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\ForkChannel;
use SugarCraft\Async\ForkedTask;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Dashboard\Msg\TickMsg;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Core\Util\Sanitize;

/**
 * Weather module that fetches live data from wttr.in and falls back to cache.
 *
 * Ticks every $tickSeconds (half an hour by default); falls back to the
 * stale disk cache when a lookup fails, and records the failure reason
 * when there is nothing stale to show. A cold start fetches immediately
 * instead of waiting a full interval to consult the cache.
 *
 * Deliberately NOT final (finding sugar-dashboard-210, adjudicated
 * in-step 2026-11-16): cachePath() stays the documented test seam and the
 * suite subclasses the module to redirect it — finalizing would trade a
 * live seam for an unproven ctor-injection retrofit. A future cache-path
 * ctor arg may retire the seam; until then this class remains
 * subclassable-for-tests only, not an extension point for consumers.
 */
class WeatherModule extends BaseModule
{
    private const TICK_INTERVAL = 1800.0;
    private const TTL_SECONDS = 1800;

    /** Parent-side budget for one fetch child: the client's own timeouts, plus slack for a slow reply. */
    private const FETCH_DEADLINE_SECONDS = 15.0;

    private ?WeatherSnapshot $current;
    private \DateTimeImmutable $lastFetch;

    /**
     * The wall reading view() is allowed to age the snapshot against.
     *
     * Sampled at the update door ONLY (tick / result / failure messages) —
     * a raw wall read inside render would make every repaint a new byte
     * (the D-80 idempotence law this fold ports to the weather panel).
     */
    private int $renderClock = 0;

    /** Test seam (ClockModule::timeBuildCountForTesting pattern): counts sampleWall() bodies so the render-side zero-wall-read law is pinned directly. */
    private static int $wallReadCount = 0;

    /** Reason of the most recent failed lookup, or null if none failed. */
    private ?string $lastFailure = null;

    /** When the most recent lookup was attempted (success or failure). */
    private ?\DateTimeImmutable $lastAttempt = null;

    /**
     * @param float $tickSeconds refresh cadence; @param int $ttlSeconds
     *     cache-freshness window. Both were frozen class constants before.
     * @param ?LoopInterface $loop  The loop the host runs (see SystemModule): the
     *     fetch's child and its deadline are registered on it, so a host with its
     *     own loop must pass it here or the fetch never settles.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $location = 'auto',
        private readonly float $tickSeconds = self::TICK_INTERVAL,
        private readonly int $ttlSeconds = self::TTL_SECONDS,
        private readonly ?string $name = null,
        private readonly ?LoopInterface $loop = null,
    ) {
        $this->current = null;
        $this->lastFetch = new \DateTimeImmutable('@0');
    }

    public function name(): string
    {
        return $this->name ?? 'weather';
    }

    public function init(): ?\Closure
    {
        // Cold boot consults cache/HTTP immediately (the send) and only
        // then arms the periodic tick. Arming the tick alone meant a
        // freshly launched dashboard showed no weather for a full
        // interval even when a perfectly good cache file was on disk.
        return Cmd::batch(
            Cmd::send(TickMsg::for($this->name())),
            $this->makeTickCmd(),
        );
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg && $msg->isFor($this->name())) {
            // Fast path: fresh cache on disk — apply immediately, no I/O
            // beyond the cache read. One wall sample for the whole door.
            $now = $this->sampleWall();
            $cached = $this->loadCache();
            if ($cached !== null) {
                $age = $now - $cached->fetchedAt->getTimestamp();
                if ($age < $this->ttlSeconds) {
                    $next = $this->withSnapshot($cached, $now);
                    return [$next, $this->makeTickCmd()];
                }
            }

            // Cache miss or stale — run the HTTP fetch in a forked child so the
            // loop keeps painting while the network answers (see fetchWeather).
            // Stale-cache fallback and the WeatherFailureMsg happen in the parent.
            return [
                $this,
                Cmd::batch(
                    Cmd::promise(function (): PromiseInterface {
                        return $this->fetchWeather();
                    }),
                    $this->makeTickCmd(),
                ),
            ];
        }

        if ($msg instanceof WeatherResultMsg) {
            $next = $this->withSnapshot($msg->snapshot, $this->sampleWall());
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

        $temp = $this->current->tempC === null
            ? '—°C'
            : sprintf('%.0f°C', $this->current->tempC);
        $condition = $this->current->condition;
        $location = $this->current->location;

        return sprintf(
            "%s %s\n%s (%s)",
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
        // No wall read here by design (D-109): the age is computed against
        // the renderClock sampled at the last update door, so repainting
        // the same state is byte-idempotent.
        $age = max(0, $this->renderClock - $this->lastFetch->getTimestamp());

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

    /**
     * The module's ONLY wall read (D-109, ClockModule::createTime seam
     * pattern): counted so the "view() never reads the clock" law is
     * pinned at the counter, not inferred from bytes.
     */
    private function sampleWall(): int
    {
        ++self::$wallReadCount;
        return time();
    }

    public static function wallReadCountForTesting(): int
    {
        return self::$wallReadCount;
    }

    public static function resetWallReadCountForTesting(): void
    {
        self::$wallReadCount = 0;
    }

    public function minSize(): array
    {
        return [20, 4];
    }

    private function withSnapshot(WeatherSnapshot $snapshot, int $now): static
    {
        $clone = clone $this;
        $clone->current = $snapshot;
        $clone->renderClock = $now;
        // The snapshot carries when IT was fetched — trusting that (rather
        // than stamping "now") is what keeps the displayed age honest when
        // a stale cache or a network fallback path supplies it.
        $clone->lastFetch = $snapshot->fetchedAt;
        $clone->lastFailure = null;
        $clone->lastAttempt = $snapshot->fetchedAt;
        return $clone;
    }

    /**
     * Fetch weather without holding the event loop (P1-5).
     *
     * The injected client runs in a forked child, which sends one frame back:
     * the snapshot, or the failure text. The parent caches a success and
     * applies the same stale-cache fallback as {@see fetchWeatherHttp()}. The
     * child's deadline bounds a black-holed or trickling endpoint, so a dead
     * network reports a failure instead of freezing the panel. A host without
     * pcntl keeps the in-process fetch.
     *
     * @return PromiseInterface<Msg>
     */
    private function fetchWeather(): PromiseInterface
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            return $this->fetchWeatherHttp();
        }

        $deferred = new Deferred();
        $frame = null;
        $settle = function (?string $failure) use (&$frame, $deferred): void {
            $deferred->resolve($this->settleFetch($frame, $failure));
        };

        try {
            ForkedTask::run(
                function (ForkChannel $channel): void {
                    $channel->send($this->fetchFrame());
                },
                static function (string $reply) use (&$frame): void {
                    $frame = $reply;
                },
                $this->loop ?? Loop::get(),
                null,
                self::FETCH_DEADLINE_SECONDS,
            )->then(
                static fn (): null => $settle(null),
                static fn (\Throwable $e): null => $settle($e->getMessage()),
            );
        } catch (\Throwable $e) {
            $settle($e->getMessage());
        }

        return $deferred->promise();
    }

    /** Child side of {@see fetchWeather()}: the one JSON frame it reports. */
    private function fetchFrame(): string
    {
        try {
            $snapshot = $this->httpClient->fetch($this->location);

            return json_encode(
                ['ok' => true, 'snapshot' => $snapshot->toArray()],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (\Throwable $e) {
            return json_encode(['ok' => false, 'reason' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE)
                ?: '{"ok":false,"reason":"the weather reply could not be encoded"}';
        }
    }

    /**
     * Parent side of {@see fetchWeather()}: a success is cached and shown; anything
     * else falls back to the stale cache, or to a WeatherFailureMsg naming why.
     *
     * @param ?string $frame The child's frame, when one arrived
     * @param ?string $failure Why the child produced no usable frame, when known
     */
    private function settleFetch(?string $frame, ?string $failure): Msg
    {
        $reply = $frame === null ? null : json_decode($frame, true);
        if (is_array($reply) && ($reply['ok'] ?? null) === true) {
            try {
                $snapshot = WeatherSnapshot::fromArray((array) ($reply['snapshot'] ?? []));
                $this->saveCache($snapshot);

                return new WeatherResultMsg($snapshot);
            } catch (\Throwable $e) {
                $failure = 'wttr.in reply could not be read: ' . $e->getMessage();
            }
        } elseif (is_array($reply) && is_string($reply['reason'] ?? null)) {
            $failure = $reply['reason'];
        }

        $cached = $this->loadCache();

        return $cached !== null
            ? new WeatherResultMsg($cached)
            : new WeatherFailureMsg(new \DateTimeImmutable(), $failure ?? 'the weather fetch ended without a reply');
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
