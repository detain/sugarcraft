<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\Log;
use SugarCraft\Code\Support\Version;

use function React\Promise\resolve;

/**
 * C-SES-6.e: the launch-time "update available" notice for phar installs.
 *
 * WHAT IT DOES. Once per 24 h, {@see self::refreshIfStale()} fetches this
 * repository's GitHub release list over the running event loop (the same
 * `React\Http\Browser` route the async model-catalog read already uses —
 * never a fork, never a child, so this class carries no child-lifetime
 * roster row) and records the outcome in
 * `~/.candy-code/cache/update-check.json` at mode 0600. The notice itself
 * is painted by `StartupSummary` from the PREVIOUS check's cache file, so
 * `view()` never waits on the network: the first launch after the cache
 * goes stale only refreshes the cache, and the notice appears on the NEXT
 * launch. That two-launch shape is the design, pinned by tests — a future
 * "fix" that makes the notice live would reintroduce a startup stall.
 *
 * WHO IT RUNS FOR. Only phar installs: inside a phar `\Phar::running(false)`
 * answers the archive path, and `self-update` is the swap route that
 * exists for exactly that install shape. On a Composer install the answer
 * is the empty string and no request is ever made (the same composer-mode
 * test {@see SelfUpdate::run()} applies to the explicit command), because a
 * Composer checkout is updated by `composer update`, not by us.
 * `CANDY_CODE_NO_UPDATE_CHECK=1` (flag-style: unset, empty or `0` reads as
 * not set) refuses the check outright, and refuses painting a notice from
 * an already-written cache — the disclosure row lives in
 * `docs/ENVIRONMENT.md`.
 *
 * UNTRUSTED CONTENT. The release list is server-supplied bytes. Nothing
 * from it is executed, and the only string that can reach the frame is a
 * version triple that survives {@see Version::isComparable()} or a dev
 * commit that survives {@see Version::HEX_SHA_PATTERN} — both re-applied
 * when the CACHE is read, not only when it is written, because the cache
 * file on disk is as untrusted as the wire that filled it (a planted
 * `update-check.json` is local, but the painting law does not care where
 * the bytes came from). `StartupSummary` then sweeps the finished row
 * through its existing one-line choke like every other painted fact. No
 * cost figure of any kind rides this path.
 *
 * NEVER BLOCKS, NEVER THROWS, NEVER SPAMS. Every failure — transport,
 * refused status, junk body, unwritable cache, corrupt cache file —
 * degrades to "no notice", logged at most once per process through the
 * {@see Log} facade. The one total request timeout on this route is E646-
 * clean by the same argument {@see \SugarCraft\Code\Providers\OpenAiModelsEndpoint}
 * spells for metadata reads: this is a one-request GitHub read, not an LLM
 * call, and a bound on it protects the loop instead of truncating work.
 *
 * Selection and ordering are NOT re-implemented: the release list is
 * judged by {@see SelfUpdate::selectRelease()} /
 * {@see SelfUpdate::selectDevRelease()} and
 * {@see Version::compare()} — the exact law `candy-code self-update`
 * applies when asked, so the notice can never advertise a swap the command
 * would refuse. The rel=next page walk mirrors the sync walk inside
 * {@see SelfUpdate::run()} (its helpers are private; the duplication seam
 * is disclosed in the lane report) and follows only same-origin
 * `api.github.com` release-list links, never a server-named host.
 */
final class UpdateCheck
{
    /** Flag-style opt-out; read exactly once per check and once per paint. */
    public const UPDATE_CHECK_OPT_OUT_ENV = 'CANDY_CODE_NO_UPDATE_CHECK';

    /** File name under `~/.candy-code/cache/`, written by {@see AtomicFileWriter} at 0600. */
    public const CACHE_FILE = 'update-check.json';

    /** Between two successful-or-failed refreshes, no request leaves the process. */
    public const CHECK_INTERVAL_SECONDS = 86_400;

    private const CONNECT_TIMEOUT_SECONDS = 5.0;
    private const REQUEST_TIMEOUT_SECONDS = 60.0;

    /** Same page cap as the sync `self-update` walk (its constant is private). */
    private const PAGE_LIMIT = 5;

    /**
     * Memoised painted notice per (cache path, current version) — the whole
     * point is that a frame repaint costs no second file read. The first
     * answer per key wins for the process, which is also what freezes the
     * notice at "the PREVIOUS check's answer" even after this launch's own
     * refresh landed mid-frame.
     *
     * @var array<string, ?string>
     */
    private static array $memo = [];

    private static bool $loggedOnce = false;

    /**
     * The production cache path under the owned home, or null when there is
     * no safe home to write in (root-owned HOME, or no HOME at all) — the
     * same refusal {@see \SugarCraft\Code\Providers\ModelMetadata} spells:
     * no home, no cache, no request.
     */
    public static function cachePath(): ?string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return null;
        }

        return \rtrim($home, '/') . '/.candy-code/cache/' . self::CACHE_FILE;
    }

    /**
     * Fire the launch-time check. Returns as soon as the request walk is
     * handed to the event loop; the cache write happens in its completion
     * handler, possibly after the launch finished painting. Never throws,
     * never blocks: every refusal path (composer install, opt-out, missing
     * home, fresh cache, unbuildable transport) simply returns, and
     * anything thrown by the wiring is swallowed into the once-per-process
     * log line.
     *
     * `$fetcher` is the test seam in the shape
     * `callable(string $url, array<string, string> $headers): PromiseInterface<PSR-7>`
     * every double in this family already answers with (synchronous
     * resolves are fine — pins need no loop turn); `$pharPath`,
     * `$currentVersion`, `$now` and `$cachePath` pin the outside world for
     * hermetic tests and default to the live values.
     */
    public static function refreshIfStale(
        ?callable $fetcher = null,
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?int $now = null,
        ?string $cachePath = null,
    ): void {
        try {
            if (($pharPath ?? \Phar::running(false)) === '') {
                return;
            }
            if (self::optedOut()) {
                return;
            }
            $path = $cachePath ?? self::cachePath();
            if ($path === null) {
                return;
            }
            $now ??= \time();
            if (self::fresh($path, $now)) {
                return;
            }

            $current = \trim($currentVersion ?? Help::versionString());
            $fetcher ??= self::browserFetcher();
            if ($fetcher === null) {
                return;
            }

            self::fetchPages($fetcher, SelfUpdate::RELEASES_URL, 1, [])
                ->then(static function (mixed $releases) use ($path, $current, $now): void {
                    self::settle(\is_array($releases) ? $releases : [], $path, $current, $now);
                });
        } catch (\Throwable) {
            self::logOnce('the update check could not be started; nothing is advertised this launch');
        }
    }

    /**
     * The notice text painted by `StartupSummary`, or null for "no row".
     * Reads ONLY the previous check's cache file — never the network — and
     * memoises per process, so a per-frame call costs at most one read on
     * the very first frame. The cached answer is re-validated against the
     * running version before it is painted, so a manual upgrade (or a
     * downgrade, or a dev rebuild onto the published commit) silences a
     * stale notice without waiting for the next check.
     */
    public static function cachedNotice(
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?string $cachePath = null,
    ): ?string {
        try {
            $path = $cachePath ?? self::cachePath();
            if ($path === null) {
                return null;
            }
            if (($pharPath ?? \Phar::running(false)) === '') {
                return null;
            }
            if (self::optedOut()) {
                return null;
            }
            $current = \trim($currentVersion ?? Help::versionString());
            $memoKey = $path . "\0" . $current;
            if (\array_key_exists($memoKey, self::$memo)) {
                return self::$memo[$memoKey];
            }

            return self::$memo[$memoKey] = self::noticeFromCache($path, $current);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Drop the per-process memo and the log-once latch (tests only, mirroring {@see \SugarCraft\Code\Support\StartupSummary::clearCacheForTesting()}). */
    public static function resetForTesting(): void
    {
        self::$memo = [];
        self::$loggedOnce = false;
    }

    /** The flag-style opt-out reading: unset, empty or `0` all mean "not set". */
    private static function optedOut(): bool
    {
        $value = getenv(self::UPDATE_CHECK_OPT_OUT_ENV);

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
     * The default transport, constructed lazily so a test that injects
     * `$fetcher` never touches the loop. Null when even construction
     * fails — the degraded "no check this launch" answer.
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
     * is the failure shape {@see self::settle()} recognizes). A refused
     * status, a non-JSON body, a rejection or a page whose `Link` header
     * names a foreign host all stop the walk while keeping the pages
     * already read.
     *
     * @param callable(string, array<string, string>): PromiseInterface $fetcher
     * @param list<array<array-key, mixed>> $acc
     */
    private static function fetchPages(callable $fetcher, string $url, int $page, array $acc): PromiseInterface
    {
        $promise = $fetcher($url, ['Accept' => 'application/json']);
        if (!$promise instanceof PromiseInterface) {
            return resolve($acc);
        }

        return $promise->then(
            static function (mixed $response) use ($fetcher, $page, $acc) {
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
                    if ($next === null || !self::sameOriginReleaseRoute($next)) {
                        return $acc;
                    }

                    return self::fetchPages($fetcher, $next, $page + 1, $acc);
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
    private static function sameOriginReleaseRoute(string $url): bool
    {
        $parts = \parse_url($url);

        return \is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'api.github.com'
            && ($parts['path'] ?? '') === '/repos/detain/sugarcraft/releases';
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
    private static function settle(array $releases, string $path, string $current, int $now): void
    {
        if ($releases === []) {
            self::logOnce('the update check reached no releases; the notice stays as the last check left it');
            if (@\file_exists($path)) {
                @\touch($path);
            }

            return;
        }

        if (Version::isDev($current)) {
            $dev = SelfUpdate::selectDevRelease($releases);
            $available = null;
            $channel = SelfUpdate::CHANNEL_DEV;
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
            $stable = SelfUpdate::selectRelease($releases);
            $available = null;
            $channel = SelfUpdate::CHANNEL_STABLE;
            if ($stable !== null && (Version::compare($current, $stable['version']) ?? 0) < 0) {
                $available = $stable['version'];
            }
        }

        $json = \json_encode(['checked_at' => $now, 'channel' => $channel, 'available' => $available]);
        if (!\is_string($json)) {
            return;
        }
        try {
            AtomicFileWriter::write($path, $json, 0600);
        } catch (\Throwable) {
            self::logOnce('the update check result could not be written to the cache');
        }
    }

    /** Read, re-validate and word the notice from the cache file. Null means "no row". */
    private static function noticeFromCache(string $path, string $current): ?string
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = \json_decode($raw, true);
        if (!\is_array($data) || !\array_key_exists('available', $data) || !\array_key_exists('channel', $data)) {
            self::logOnce('the update-check cache is unreadable; no notice is advertised until the next successful check');

            return null;
        }
        $available = $data['available'];
        if (!\is_string($available) || $available === '') {
            return null;
        }

        if ($data['channel'] === SelfUpdate::CHANNEL_STABLE) {
            if (!Version::isComparable($available) || (Version::compare($current, $available) ?? 0) >= 0) {
                return null;
            }

            return Lang::t('startup.update.available', ['release' => 'v' . \ltrim($available, 'v')]);
        }
        if ($data['channel'] === SelfUpdate::CHANNEL_DEV) {
            $embedded = Version::devShaOf($current);
            if (\preg_match(Version::HEX_SHA_PATTERN, $available) !== 1
                || $embedded === null
                || \str_starts_with($available, $embedded)) {
                return null;
            }

            return Lang::t('startup.update.available', ['release' => 'dev@' . \substr($available, 0, 10)]);
        }

        return null;
    }

    /** The once-per-process degradation log line, through the ONE sink (no new emitter roster row). */
    private static function logOnce(string $message): void
    {
        if (self::$loggedOnce) {
            return;
        }
        self::$loggedOnce = true;
        Log::sub('UpdateCheck', $message);
    }
}
