<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use SugarCraft\Core\Util\AtomicTextFile;

use function React\Promise\resolve;

/**
 * The launch-time "update available" notice for phar installs.
 *
 * WHAT IT DOES. Once per 24 h, {@see refreshIfStale()} fetches the release list
 * over the running event loop (`React\Http\Browser` — never a fork, never a
 * child) and records the outcome in `<stateDir>/update-check.json` at mode
 * 0600. The notice itself ({@see cachedNotice()}) is read from the PREVIOUS
 * check's cache file, so a render never waits on the network: the first launch
 * after the cache goes stale only refreshes the cache, and the notice appears on
 * the NEXT launch. That two-launch shape is the design, pinned by tests — a
 * "fix" that makes the notice live would reintroduce a startup stall.
 *
 * WHO IT RUNS FOR. Only phar installs (`\Phar::running(false)` non-empty); a
 * Composer checkout is updated by `composer update`, not by us. An opt-out env
 * var (flag-style: unset, empty or `0` reads as not set; default
 * `<APP>_NO_UPDATE_CHECK`) refuses the check outright and refuses painting a
 * notice from an already-written cache.
 *
 * UNTRUSTED CONTENT. The release list is server-supplied bytes. Nothing from it
 * is executed, and the only string that can reach the notice is a version triple
 * that survives {@see Version::isComparable()} or a dev commit that survives
 * {@see Version::HEX_SHA_PATTERN} — both re-applied when the CACHE is read, not
 * only when it is written, because the cache file on disk is as untrusted as the
 * wire that filled it.
 *
 * NEVER BLOCKS, NEVER THROWS, NEVER SPAMS. Every failure — transport, refused
 * status, junk body, unwritable cache, corrupt cache file — degrades to "no
 * notice", logged at most once per process through the optional logger. The one
 * total request timeout on this route is deliberate: it is a one-request GitHub
 * read, and a bound on it protects the loop instead of truncating work.
 *
 * Selection is NOT re-implemented: the list is judged by {@see ReleaseSelector}
 * and {@see Version::compare()} — the exact law {@see SelfUpdate} applies when
 * asked, so the notice can never advertise a swap the command would refuse. The
 * rel=next walk mirrors SelfUpdate's sync walk (disclosed duplication: the two
 * `Link` readers differ in regex and are each pinned) and follows only
 * same-origin `api.github.com` release-list links, never a server-named host.
 *
 * Extracted from candy-code's `Cli/UpdateCheck`.
 */
final class UpdateCheck
{
    /** File name under the state dir, written by {@see AtomicTextFile} at 0600. */
    public const CACHE_FILE = 'update-check.json';

    /** Between two successful-or-failed refreshes, no request leaves the process. */
    public const CHECK_INTERVAL_SECONDS = 86_400;

    private const CONNECT_TIMEOUT_SECONDS = 5.0;
    private const REQUEST_TIMEOUT_SECONDS = 60.0;

    /** Same page cap as the sync `self-update` walk. */
    private const PAGE_LIMIT = 5;

    /**
     * Memoised painted notice per (cache path, current version): a repaint costs
     * no second file read, and the first answer per key wins for the process —
     * which also freezes the notice at "the PREVIOUS check's answer" even after
     * this launch's own refresh landed.
     *
     * @var array<string, ?string>
     */
    private static array $memo = [];

    private static bool $loggedOnce = false;

    private ?string $pharPath = null;

    private ?int $now = null;

    private ?string $optOutEnv = null;

    /** @var (callable(string, array<string, string>): PromiseInterface)|null */
    private $fetcher = null;

    /** @var (\Closure(string, array<string, string>): string)|null */
    private ?\Closure $translator = null;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $logger = null;

    private readonly ReleaseSelector $selector;

    private function __construct(
        private readonly ReleaseSource $source,
        private readonly string $stateDir,
        private readonly string $currentVersion,
    ) {
        $this->selector = ReleaseSelector::for($source);
    }

    /**
     * @param string $stateDir       writable per-user state directory (the host owns the "safe home" decision)
     * @param string $currentVersion what the running install reports
     */
    public static function new(ReleaseSource $source, string $stateDir, string $currentVersion): self
    {
        return new self($source, $stateDir, \trim($currentVersion));
    }

    /** Test seam: `callable(string $url, array $headers): PromiseInterface<PSR-7>`. */
    public function withFetcher(?callable $fetcher): self
    {
        $c = clone $this;
        $c->fetcher = $fetcher;

        return $c;
    }

    /** The phar the install runs from; null asks `\Phar::running(false)`. */
    public function withPharPath(?string $pharPath): self
    {
        $c = clone $this;
        $c->pharPath = $pharPath;

        return $c;
    }

    public function withNow(?int $now): self
    {
        $c = clone $this;
        $c->now = $now;

        return $c;
    }

    /** The flag-style opt-out env var name; default `<APP>_NO_UPDATE_CHECK`. */
    public function withOptOutEnv(?string $name): self
    {
        $c = clone $this;
        $c->optOutEnv = $name;

        return $c;
    }

    /** @param (\Closure(string, array<string, string>): string)|null $translator receives `update.available` with `{release}`/`{app}` */
    public function withTranslator(?\Closure $translator): self
    {
        $c = clone $this;
        $c->translator = $translator;

        return $c;
    }

    /** @param (\Closure(string): void)|null $logger one degradation line per process */
    public function withLogger(?\Closure $logger): self
    {
        $c = clone $this;
        $c->logger = $logger;

        return $c;
    }

    public function cachePath(): string
    {
        return \rtrim($this->stateDir, '/') . '/' . self::CACHE_FILE;
    }

    /** The opt-out env var in force: `<APP>_NO_UPDATE_CHECK` unless overridden. */
    public function optOutEnv(): string
    {
        return $this->optOutEnv
            ?? \strtoupper((string) \preg_replace('/[^A-Za-z0-9]+/', '_', $this->source->app)) . '_NO_UPDATE_CHECK';
    }

    /**
     * Fire the launch-time check. Returns as soon as the request walk is handed
     * to the event loop; the cache write happens in its completion handler. Never
     * throws, never blocks: every refusal path (composer install, opt-out, fresh
     * cache, unbuildable transport) simply returns, and anything thrown by the
     * wiring is swallowed into the once-per-process log line.
     */
    public function refreshIfStale(): void
    {
        try {
            if (($this->pharPath ?? \Phar::running(false)) === '') {
                return;
            }
            if ($this->optedOut()) {
                return;
            }
            $path = $this->cachePath();
            $now = $this->now ?? \time();
            if (self::fresh($path, $now)) {
                return;
            }

            $current = $this->currentVersion;
            $fetcher = $this->fetcher ?? self::browserFetcher();
            if ($fetcher === null) {
                return;
            }

            $this->fetchPages($fetcher, $this->source->releasesUrl(), 1, [])
                ->then(function (mixed $releases) use ($path, $current, $now): void {
                    $this->settle(\is_array($releases) ? $releases : [], $path, $current, $now);
                });
        } catch (\Throwable) {
            $this->logOnce('the update check could not be started; nothing is advertised this launch');
        }
    }

    /**
     * The notice text, or null for "no row". Reads ONLY the previous check's
     * cache file — never the network — and memoises per process. The cached
     * answer is re-validated against the running version before it is returned,
     * so a manual upgrade (or a downgrade, or a dev rebuild onto the published
     * commit) silences a stale notice without waiting for the next check.
     */
    public function cachedNotice(): ?string
    {
        try {
            $path = $this->cachePath();
            if (($this->pharPath ?? \Phar::running(false)) === '') {
                return null;
            }
            if ($this->optedOut()) {
                return null;
            }
            $current = $this->currentVersion;
            $memoKey = $path . "\0" . $current;
            if (\array_key_exists($memoKey, self::$memo)) {
                return self::$memo[$memoKey];
            }

            return self::$memo[$memoKey] = $this->noticeFromCache($path, $current);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Drop the per-process memo and the log-once latch (tests only). */
    public static function resetForTesting(): void
    {
        self::$memo = [];
        self::$loggedOnce = false;
    }

    /** The flag-style opt-out reading: unset, empty or `0` all mean "not set". */
    private function optedOut(): bool
    {
        $value = \getenv($this->optOutEnv());

        return $value !== false && $value !== '' && $value !== '0';
    }

    /** The cache file exists and was written (or touched) within the interval. */
    private static function fresh(string $path, int $now): bool
    {
        \clearstatcache(true, $path);
        $mtime = @\filemtime($path);

        return $mtime !== false && $mtime >= $now - self::CHECK_INTERVAL_SECONDS;
    }

    /**
     * The default transport, constructed lazily so a test that injects a fetcher
     * never touches the loop. Null when even construction fails — the degraded
     * "no check this launch" answer.
     *
     * @return (callable(string, array<string, string>): PromiseInterface)|null
     */
    private static function browserFetcher(): ?callable
    {
        try {
            $browser = (new Browser(new Connector(['timeout' => self::CONNECT_TIMEOUT_SECONDS], Loop::get())))
                ->withTimeout(self::REQUEST_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return static fn (string $url, array $headers): PromiseInterface => $browser->get($url, $headers);
    }

    /**
     * The async rel=next walk. Resolves with the accumulated release rows
     * (empty on a failed or refused first page — never a partial catalog
     * presented as a complete one is handled by the caller: an empty list
     * is the failure shape {@see settle()} recognizes). A refused
     * status, a non-JSON body, a rejection or a page whose `Link` header
     * names a foreign host all stop the walk while keeping the pages
     * already read.
     *
     * @param callable(string, array<string, string>): PromiseInterface $fetcher
     * @param list<array<array-key, mixed>> $acc
     */
    private function fetchPages(callable $fetcher, string $url, int $page, array $acc): PromiseInterface
    {
        $promise = $fetcher($url, ['Accept' => 'application/json']);
        if (!$promise instanceof PromiseInterface) {
            return resolve($acc);
        }

        return $promise->then(
            function (mixed $response) use ($fetcher, $page, $acc) {
                try {
                    $releases = $response instanceof \Psr\Http\Message\ResponseInterface
                        && $response->getStatusCode() >= 200
                        && $response->getStatusCode() < 300
                        ? self::decodePage((string) $response->getBody())
                        : null;
                    if ($releases === null) {
                        return $acc;
                    }
                    $acc = [...$acc, ...$releases];
                    if ($page >= self::PAGE_LIMIT) {
                        return $acc;
                    }
                    $next = self::nextPageUrl($response->getHeaderLine('Link'));
                    if ($next === null || !$this->sameOriginReleaseRoute($next)) {
                        return $acc;
                    }

                    return $this->fetchPages($fetcher, $next, $page + 1, $acc);
                } catch (\Throwable) {
                    return $acc;
                }
            },
            static fn (mixed $reason): array => $acc,
        );
    }

    /** `[{...release...}, ...]` or null — a JSON object, a scalar and garbage all answer null. */
    private static function decodePage(string $body): ?array
    {
        $decoded = \json_decode($body, true);

        return \is_array($decoded) && \array_is_list($decoded) ? $decoded : null;
    }

    /** RFC 8288 `Link:` rel-token reader: the `<uri>; rel="next"` pair, other rels ignored. */
    private static function nextPageUrl(string $link): ?string
    {
        if ($link === '') {
            return null;
        }
        foreach (\explode(',', $link) as $chunk) {
            if (\preg_match('#<([^>]+)>\s*;(.*)#', $chunk, $m) !== 1) {
                continue;
            }
            if (\preg_match('/(?:^|\s)rel\s*=\s*("([^"]*)"|[^;,\s]+)/i', $m[2], $r) !== 1) {
                continue;
            }
            $rel = \strtolower(\trim($r[2] ?? $r[1], " \t\""));
            if ($rel === 'next') {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Containment for the walked URI: only the exact same-origin release
     * list route on `api.github.com` may be followed, never a host or path
     * the response invents (the header is server-supplied bytes, and a
     * `Link:` to an attacker endpoint is a data path this walk refuses).
     */
    private function sameOriginReleaseRoute(string $url): bool
    {
        $parts = \parse_url($url);

        return \is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'api.github.com'
            && ($parts['path'] ?? '') === $this->source->releasesPath();
    }

    /**
     * Judge the accumulated list with `self-update`'s own selection law and
     * record the outcome. An empty list is "the check failed": a cache that
     * exists is left byte-intact with its clock restarted (never erase a
     * real notice with a network blip, never retry per launch while a cache
     * stands), and no cache is NOT written at all, so the next launch
     * retries. A successful list always writes: the record says what the
     * most recent check knew, notice or no notice.
     *
     * @param list<array<array-key, mixed>> $releases
     */
    private function settle(array $releases, string $path, string $current, int $now): void
    {
        if ($releases === []) {
            $this->logOnce('the update check reached no releases; the notice stays as the last check left it');
            if (@\file_exists($path)) {
                @\touch($path);
            }

            return;
        }

        if (Version::isDev($current)) {
            $dev = $this->selector->selectLatestRelease($releases);
            $available = null;
            $channel = UpdateOptions::CHANNEL_LATEST;
            if ($dev !== null) {
                $target = $dev['commitish'];
                $embedded = Version::devShaOf($current);
                if ($embedded !== null && $target !== ''
                    && \preg_match(Version::HEX_SHA_PATTERN, $target) === 1
                    && !\str_starts_with($target, $embedded)) {
                    $available = $target;
                }
            }
        } else {
            $stable = $this->selector->selectRelease($releases);
            $available = null;
            $channel = UpdateOptions::CHANNEL_STABLE;
            if ($stable !== null && (Version::compare($current, $stable['version']) ?? 0) < 0) {
                $available = $stable['version'];
            }
        }

        $json = \json_encode(['checked_at' => $now, 'channel' => $channel, 'available' => $available]);
        if (!\is_string($json)) {
            return;
        }
        try {
            AtomicTextFile::at($path)->withPermissions(0600)->write($json);
        } catch (\Throwable) {
            $this->logOnce('the update check result could not be written to the cache');
        }
    }

    /** Read, re-validate and word the notice from the cache file. Null means "no row". */
    private function noticeFromCache(string $path, string $current): ?string
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = \json_decode($raw, true);
        if (!\is_array($data) || !\array_key_exists('available', $data) || !\array_key_exists('channel', $data)) {
            $this->logOnce('the update-check cache is unreadable; no notice is advertised until the next successful check');

            return null;
        }
        $available = $data['available'];
        if (!\is_string($available) || $available === '') {
            return null;
        }

        if ($data['channel'] === UpdateOptions::CHANNEL_STABLE) {
            if (!Version::isComparable($available) || (Version::compare($current, $available) ?? 0) >= 0) {
                return null;
            }

            return $this->t('update.available', ['release' => 'v' . \ltrim($available, 'v')]);
        }
        if ($data['channel'] === UpdateOptions::CHANNEL_LATEST) {
            $embedded = Version::devShaOf($current);
            if (\preg_match(Version::HEX_SHA_PATTERN, $available) !== 1
                || $embedded === null
                || \str_starts_with($available, $embedded)) {
                return null;
            }

            return $this->t('update.available', ['release' => 'latest@' . \substr($available, 0, 10)]);
        }

        return null;
    }

    /** @param array<string, string> $params */
    private function t(string $key, array $params): string
    {
        $params += ['app' => $this->source->app, 'package' => $this->source->composerPackage];

        return $this->translator !== null ? ($this->translator)($key, $params) : Lang::t($key, $params);
    }

    /** The once-per-process degradation log line, through the host's one sink. */
    private function logOnce(string $message): void
    {
        if (self::$loggedOnce) {
            return;
        }
        self::$loggedOnce = true;
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
