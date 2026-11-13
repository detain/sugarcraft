<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\Log;
use SugarCraft\Code\Support\Version;
use SugarCraft\Releases\UpdateCheck as LibUpdateCheck;

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
 * S-L01: the check itself — release selection, the rel=next page walk
 * (same-origin `api.github.com` links only), the cache read/validate/write
 * and the memo — lives in sugar-releases ({@see \SugarCraft\Releases\UpdateCheck}),
 * which shares its selector with the `self-update` engine, so the notice can
 * never advertise a swap the command would refuse. This class is the static
 * app-side adapter: it fixes candy-code's release source, the per-user cache
 * directory (`~/.candy-code/cache`, home-ownership decided by
 * {@see HomeDirectory}), the opt-out env, the `startup.update.available`
 * wording and the once-per-process degradation line through the {@see Log} facade.
 */
final class UpdateCheck
{
    /** Flag-style opt-out: unset, empty or `0` reads as not set. */
    public const UPDATE_CHECK_OPT_OUT_ENV = 'CANDY_CODE_NO_UPDATE_CHECK';

    public const CACHE_FILE = LibUpdateCheck::CACHE_FILE;

    /** Between two successful-or-failed refreshes, no request leaves the process. */
    public const CHECK_INTERVAL_SECONDS = LibUpdateCheck::CHECK_INTERVAL_SECONDS;

    private static bool $loggedOnce = false;

    /** The cache file path, or null when there is no safely owned home to keep it in. */
    public static function cachePath(): ?string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return null;
        }

        return \rtrim($home, '/') . '/.candy-code/cache/' . self::CACHE_FILE;
    }

    /**
     * Fire the launch-time check (never blocks, never throws). The parameters
     * are the suite's seams; production passes none.
     *
     * @param (callable(string, array<string, string>): \React\Promise\PromiseInterface)|null $fetcher
     */
    public static function refreshIfStale(
        ?callable $fetcher = null,
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?int $now = null,
        ?string $cachePath = null,
    ): void {
        $check = self::engine($pharPath, $currentVersion, $cachePath, $now, $fetcher);
        $check?->refreshIfStale();
    }

    /**
     * The notice text, or null for "no row": the PREVIOUS check's answer read
     * from the cache, re-validated against the running version, never the network.
     */
    public static function cachedNotice(
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?string $cachePath = null,
    ): ?string {
        return self::engine($pharPath, $currentVersion, $cachePath)?->cachedNotice();
    }

    /** Drop the per-process memo and the log-once latch (tests only). */
    public static function resetForTesting(): void
    {
        LibUpdateCheck::resetForTesting();
        self::$loggedOnce = false;
    }

    private static function engine(
        ?string $pharPath,
        ?string $currentVersion,
        ?string $cachePath,
        ?int $now = null,
        ?callable $fetcher = null,
    ): ?LibUpdateCheck {
        // The flag is read here, by name, so the env census sees the read; the
        // lib derives the same CANDY_CODE_NO_UPDATE_CHECK from the app slug.
        $optOut = \getenv(self::UPDATE_CHECK_OPT_OUT_ENV);
        if ($optOut !== false && $optOut !== '' && $optOut !== '0') {
            return null;
        }

        try {
            $path = $cachePath ?? self::cachePath();
            if ($path === null) {
                return null;
            }

            return LibUpdateCheck::new(
                SelfUpdate::source(),
                \dirname($path),
                \trim($currentVersion ?? Help::versionString()),
            )
                ->withPharPath($pharPath)
                ->withNow($now)
                ->withFetcher($fetcher)
                ->withTranslator(static fn(string $key, array $params = []): string => match ($key) {
                    'update.available' => Lang::t('startup.update.available', $params),
                    default => $key,
                })
                ->withLogger(static function (string $message): void {
                    if (self::$loggedOnce) {
                        return;
                    }
                    self::$loggedOnce = true;
                    Log::sub('UpdateCheck', $message);
                });
        } catch (\Throwable) {
            return null;
        }
    }
}
