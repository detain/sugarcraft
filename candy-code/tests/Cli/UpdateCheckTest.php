<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Cli\SelfUpdate;
use SugarCraft\Code\Cli\UpdateCheck;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\HomeDirectory;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * C-SES-6.e: the launch-time update notice for phar installs.
 *
 * Everything here is hermetic by construction: the release list is answered
 * by a scripted fetcher double (the `$fetcher` seam), never a dial, so no
 * test in this file can reach GitHub even if the network is up; the cache is
 * a path inside a fresh temp directory (the `$cachePath` seam), never the
 * real `~/.candy-code`. Synchronous resolves mean no test needs a loop turn.
 *
 * The pins mirror the design laws: composer installs are never checked, the
 * opt-out refuses both the request and the painting, a fresh cache costs no
 * request, a stale cache costs exactly one, the cache lands at 0600, the
 * notice paints only a genuinely newer release judged by `self-update`'s own
 * selection law, the two-launch staleness is deliberate, the page walk
 * follows only same-origin release links up to the cap, and every failure —
 * refused status, rejection, junk body, corrupt cache, planted version —
 * degrades to "no notice" without a throw and without erasing a real one.
 */
final class UpdateCheckTest extends TestCase
{
    /** A stable current version every fixture compares against. */
    private const CURRENT = '0.1.0';

    /** A fixed clock for checked_at pins (well inside any file's lifetime). */
    private const NOW = 1_700_000_000;

    /** Non-empty answer of `Phar::running(false)` — the phar-install gate. */
    private const PHAR = '/pinned/candy-code.phar';

    private string $dir = '';

    private string $cache = '';

    /** @var list<array{url: string, headers: array<string, string>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/cc-uc-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($this->dir, 0o700));
        $this->cache = $this->dir . '/update-check.json';
        $this->requests = [];

        UpdateCheck::resetForTesting();
        \putenv(UpdateCheck::UPDATE_CHECK_OPT_OUT_ENV);
    }

    protected function tearDown(): void
    {
        \putenv(UpdateCheck::UPDATE_CHECK_OPT_OUT_ENV);
        UpdateCheck::resetForTesting();

        foreach (\scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->dir . '/' . $entry);
            }
        }
        @\rmdir($this->dir);

        parent::tearDown();
    }

    public function testAStaleCacheFiresExactlyOneRequestAndWritesTheCacheAt0600(): void
    {
        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody([self::release('0.1.1')])]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );

        self::assertCount(1, $this->requests);
        self::assertSame(SelfUpdate::RELEASES_URL, $this->requests[0]['url']);
        self::assertSame(['Accept' => 'application/json'], $this->requests[0]['headers']);

        $written = \json_decode((string) @\file_get_contents($this->cache), true);
        self::assertIsArray($written);
        self::assertSame(self::NOW, $written['checked_at']);
        self::assertSame('stable', $written['channel']);
        self::assertSame('v0.1.1', $written['available']);
        self::assertSame(0o600, \fileperms($this->cache) & 0o777, 'the cache must be owner-only');
    }

    public function testAFreshCacheCostsNoRequest(): void
    {
        $fetcher = $this->scripted([self::pageBody([self::release('0.1.1')])]);

        UpdateCheck::refreshIfStale($fetcher, self::PHAR, self::CURRENT, self::NOW, $this->cache);
        self::assertCount(1, $this->requests);

        // Second launch within the interval: the file's own clock answers,
        // and no request leaves the process.
        UpdateCheck::refreshIfStale($fetcher, self::PHAR, self::CURRENT, null, $this->cache);
        self::assertCount(1, $this->requests);
    }

    public function testTheOptOutRefusesBothTheRequestAndAnAlreadyCachedNotice(): void
    {
        self::writeCache(['checked_at' => self::NOW, 'channel' => 'stable', 'available' => '9.9.9']);
        \putenv(UpdateCheck::UPDATE_CHECK_OPT_OUT_ENV . '=1');

        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody([self::release('0.1.1')])]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );
        self::assertSame([], $this->requests);
        self::assertNull(UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache));
    }

    public function testAComposerInstallIsNeverCheckedAndNeverPaintsANotice(): void
    {
        // The empty `Phar::running(false)` answer — the same composer-mode
        // test `candy-code self-update` applies. Proven on both doors: the
        // request side and the painting side.
        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody([self::release('0.1.1')])]),
            '',
            self::CURRENT,
            self::NOW,
            $this->cache,
        );
        self::assertSame([], $this->requests);
        self::assertFalse(\file_exists($this->cache));

        self::writeCache(['checked_at' => self::NOW, 'channel' => 'stable', 'available' => '9.9.9']);
        self::assertNull(UpdateCheck::cachedNotice('', self::CURRENT, $this->cache));
    }

    public function testTheNoticeAppearsOnlyForAGenuinelyNewerRelease(): void
    {
        $expected = Lang::t('startup.update.available', ['release' => 'v0.2.0']);
        self::assertStringContainsString('candy-code self-update', $expected);

        foreach ([['0.0.9', false], ['0.1.0', false], ['0.2.0', true]] as [$released, $advertises]) {
            UpdateCheck::resetForTesting();
            @\unlink($this->cache);
            $this->requests = [];

            UpdateCheck::refreshIfStale(
                $this->scripted([self::pageBody([self::release($released)])]),
                self::PHAR,
                self::CURRENT,
                self::NOW,
                $this->cache,
            );

            // The check still records its answer when nothing is newer —
            // the cache always says what the last check knew.
            $written = \json_decode((string) @\file_get_contents($this->cache), true);
            self::assertIsArray($written);
            self::assertSame($advertises ? 'v0.2.0' : null, $written['available']);
            self::assertSame(
                $advertises ? $expected : null,
                UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache),
            );
        }
    }

    /**
     * THE TWO-LAUNCH LAW (by design — pinned so nobody "fixes" it): the
     * notice is painted from the PREVIOUS check's cache, so `view()` never
     * waits on the network. The launch that refreshes never shows its own
     * answer; the answer appears on the next launch. A change that made
     * this live would reintroduce a startup stall.
     */
    public function testTheLaunchThatRefreshesNeverShowsItsOwnDiscovery(): void
    {
        // Launch N: no cache at all — the first paint has nothing to show.
        self::assertNull(UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache));

        // ... and this launch's refresh then discovers 0.5.0 — the memoised
        // "nothing" stands for the rest of this process's frames.
        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody([self::release('0.5.0')])]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );
        self::assertFileExists($this->cache);
        self::assertNull(
            UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache),
            'a completed check must not change this process\'s painted notice',
        );

        // Launch N+1: the cached answer paints now — still with no request.
        UpdateCheck::resetForTesting();
        $this->requests = [];
        self::assertSame(
            Lang::t('startup.update.available', ['release' => 'v0.5.0']),
            UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache),
        );
        self::assertSame([], $this->requests, 'painting must never touch the network');
    }

    public function testTheCacheIsReadOncePerProcessEvenAfterItDisappears(): void
    {
        self::writeCache(['checked_at' => self::NOW, 'channel' => 'stable', 'available' => '0.1.1']);

        $first = UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache);
        self::assertNotNull($first);

        @\unlink($this->cache);
        self::assertSame($first, UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache));

        // A different running version is a different memo key: it re-reads
        // (now against a vanished file) and answers "no notice" — the memo
        // never pins one version's answer onto another's frame.
        self::assertNull(UpdateCheck::cachedNotice(self::PHAR, '0.1.1', $this->cache));
    }

    public function testACorruptOrForeignCacheDegradesToNoNoticeWithoutAThrow(): void
    {
        $corrupt = [
            'not json at all',
            '{"checked_at": 1}',                       // no available, no channel
            '{"channel": "stable", "available": {"x": 1}}',  // shape forged
            '{"channel": "stable", "available": true}',
            '{"channel": "bogus-channel", "available": "9.9.9"}',
        ];
        foreach ($corrupt as $raw) {
            UpdateCheck::resetForTesting();
            \file_put_contents($this->cache, $raw);
            self::assertNull(UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache));
        }
    }

    public function testAPlantedVersionIsRevalidatedAtPaintTimeNotOnlyAtWriteTime(): void
    {
        // A cache a third party planted (the file on disk is as untrusted as
        // the wire that filled it): escape bytes, unparseable triples, and a
        // dev stamp that is not a hex object name all answer "no row".
        foreach ([
            ['channel' => 'stable', 'available' => "0.9.9\x1b[31m"],
            ['channel' => 'stable', 'available' => 'nightly 9.9'],
            ['channel' => 'stable', 'available' => ''],
            ['channel' => 'dev', 'available' => 'main'],
            ['channel' => 'dev', 'available' => ''],
        ] as $planted) {
            UpdateCheck::resetForTesting();
            self::writeCache(['checked_at' => self::NOW] + $planted);
            self::assertNull(
                UpdateCheck::cachedNotice(self::PHAR, $planted['channel'] === 'dev' ? 'dev-abc1234' : self::CURRENT, $this->cache),
            );
        }
    }

    public function testTheDevChannelAdvertisesOnlyANewerCommitAndSilencesItsOwn(): void
    {
        $target = \str_repeat('f', 40);
        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody([self::devRelease($target)])]),
            self::PHAR,
            'dev-abc1234',
            self::NOW,
            $this->cache,
        );

        $written = \json_decode((string) @\file_get_contents($this->cache), true);
        self::assertSame('dev', $written['channel']);
        self::assertSame($target, $written['available']);
        self::assertSame(
            Lang::t('startup.update.available', ['release' => 'dev@' . \substr($target, 0, 10)]),
            UpdateCheck::cachedNotice(self::PHAR, 'dev-abc1234', $this->cache),
        );

        // Rebuilt onto the very commit the dev release names: same cache,
        // the notice silences itself against the running version.
        UpdateCheck::resetForTesting();
        self::assertNull(UpdateCheck::cachedNotice(self::PHAR, 'dev-' . \substr($target, 0, 7), $this->cache));
    }

    public function testTheWalkFollowsASameOriginNextLinkAndMergesPages(): void
    {
        $next = '<' . SelfUpdate::RELEASES_URL . '&page=2>; rel="next"';
        UpdateCheck::refreshIfStale(
            $this->scripted([
                self::pageBody([self::release('0.1.0')], $next),
                self::pageBody([self::release('0.3.0')]),
            ]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );

        self::assertCount(2, $this->requests);
        self::assertSame(SelfUpdate::RELEASES_URL, $this->requests[0]['url']);
        self::assertSame(SelfUpdate::RELEASES_URL . '&page=2', $this->requests[1]['url']);
        self::assertSame('v0.3.0', \json_decode((string) \file_get_contents($this->cache), true)['available']);
    }

    public function testALinkToAForeignHostIsNeverDialed(): void
    {
        $hostile = '<https://evil.example/releases>; rel="next"';
        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody([self::release('0.1.0')], $hostile)]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );

        self::assertCount(1, $this->requests);
        foreach ($this->requests as $request) {
            self::assertStringStartsWith('https://api.github.com/', $request['url']);
        }
    }

    public function testARelOtherThanNextDoesNotExtendTheWalk(): void
    {
        UpdateCheck::refreshIfStale(
            $this->scripted([self::pageBody(
                [self::release('0.1.0')],
                '<' . SelfUpdate::RELEASES_URL . '>; rel="prev", <' . SelfUpdate::RELEASES_URL . '&page=2>; rel="last"',
            )]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );
        self::assertCount(1, $this->requests);
    }

    public function testThePageCapBoundsTheWalk(): void
    {
        $script = [];
        for ($page = 1; $page <= 6; $page++) {
            $script[] = self::pageBody(
                [self::release('0.1.0')],
                '<' . SelfUpdate::RELEASES_URL . '&page=' . ($page + 1) . '>; rel="next"',
            );
        }

        UpdateCheck::refreshIfStale($this->scripted($script), self::PHAR, self::CURRENT, self::NOW, $this->cache);

        self::assertCount(5, $this->requests, 'the walk must stop at the same page cap self-update applies');
    }

    public function testAFailedCheckKeepsTheExistingNoticeByteIntactAndRestartsTheClock(): void
    {
        self::writeCache(['checked_at' => self::NOW - 200, 'channel' => 'stable', 'available' => '8.8.8']);
        $before = (string) \file_get_contents($this->cache);
        \touch($this->cache, self::NOW - 2 * 86_400); // make it stale for the check

        UpdateCheck::refreshIfStale($this->scripted([['status' => 500]]), self::PHAR, self::CURRENT, self::NOW, $this->cache);

        self::assertSame($before, (string) \file_get_contents($this->cache), 'a blip must not erase a real notice');
        \clearstatcache(true, $this->cache); // settle's touch wrote the real clock; the stat cache may hold the stale one
        self::assertGreaterThanOrEqual(\time() - 60, (int) \filemtime($this->cache), 'the clock must restart so the next launch waits a day, not retries');

        // And the stale-cache gate now reads as fresh again: next launch, no retry storm.
        $this->requests = [];
        UpdateCheck::refreshIfStale($this->scripted([]), self::PHAR, self::CURRENT, self::NOW + 60, $this->cache);
        self::assertSame([], $this->requests);

        UpdateCheck::resetForTesting();
        self::assertSame(
            Lang::t('startup.update.available', ['release' => 'v8.8.8']),
            UpdateCheck::cachedNotice(self::PHAR, self::CURRENT, $this->cache),
        );
    }

    public function testAFailedCheckWithNoCacheWritesNothingSoTheNextLaunchRetries(): void
    {
        UpdateCheck::refreshIfStale($this->scripted([['status' => 403]]), self::PHAR, self::CURRENT, self::NOW, $this->cache);
        self::assertFalse(\file_exists($this->cache));

        UpdateCheck::resetForTesting();
        UpdateCheck::refreshIfStale(self::rejecting(), self::PHAR, self::CURRENT, self::NOW, $this->cache);
        self::assertFalse(\file_exists($this->cache));
    }

    public function testANonListBodyIsAFailureShapeAndCostsNoWrite(): void
    {
        foreach (['{"tag_name": "candy-code/9.9.9"}', '"scalar"'] as $body) {
            UpdateCheck::resetForTesting();
            @\unlink($this->cache);

            UpdateCheck::refreshIfStale(
                $this->scripted([['body' => $body]]),
                self::PHAR,
                self::CURRENT,
                self::NOW,
                $this->cache,
            );
            self::assertFalse(\file_exists($this->cache), 'junk answers the failure shape, never a forged success');
        }

        // A valid JSON LIST of rows that carry no usable tag is the opposite
        // shape: the walk succeeded and genuinely found nothing to advertise —
        // that records a decision (available null), it is not a failure.
        UpdateCheck::refreshIfStale(
            $this->scripted([['body' => '[{"tag_name": 5, "assets": 3}]']]),
            self::PHAR,
            self::CURRENT,
            self::NOW,
            $this->cache,
        );
        self::assertSame(['checked_at' => self::NOW, 'channel' => 'stable', 'available' => null], \json_decode((string) \file_get_contents($this->cache), true));
    }

    public function testWithoutAnOwnedHomeThereIsNoPathNoCacheAndNoRequest(): void
    {
        $sandbox = self::sandboxHome();
        try {
            self::assertSame(
                $sandbox . '/.candy-code/cache/' . UpdateCheck::CACHE_FILE,
                UpdateCheck::cachePath(),
            );

            // A home this user does not own (world-writable is refused by
            // HomeDirectory::owned()) answers the production seam with
            // "no cache, no request" — the same law ModelMetadata spells.
            self::unownHome($sandbox);
            self::assertNull(UpdateCheck::cachePath());

            UpdateCheck::refreshIfStale(
                $this->scripted([self::pageBody([self::release('0.1.1')])]),
                self::PHAR,
                self::CURRENT,
                self::NOW,
                null,
            );
            self::assertSame([], $this->requests);
        } finally {
            self::restoreHome();
            @\rmdir($sandbox . '/.candy-code/cache');
            @\rmdir($sandbox . '/.candy-code');
            @\rmdir($sandbox);
        }
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * A scripted transport double: each call pops one response spec and
     * resolves it synchronously (no loop turn needed), recording the URL and
     * headers for the hermeticity pins. An exhausted script answers a refused
     * status — a test that under-scripts learns "failure", never a hang.
     *
     * @param list<array{status?: int, body?: string, link?: string}> $script
     */
    private function scripted(array $script): callable
    {
        // Bind BY REFERENCE: a by-value `use` copy is re-initialized from the
        // snapshot on every call, so a queue popped with array_shift would
        // replay page 1 forever (and the walk would run to its page cap).
        $queue = $script;

        return function (string $url, array $headers) use (&$queue): PromiseInterface {
            $this->requests[] = ['url' => $url, 'headers' => $headers];
            $spec = \array_shift($queue) ?? ['status' => 503];

            return resolve(new Response(
                $spec['status'] ?? 200,
                isset($spec['link']) ? ['Link' => $spec['link']] : [],
                $spec['body'] ?? '[]',
            ));
        };
    }

    /** @return (callable(string, array<string, string>): PromiseInterface) */
    private static function rejecting(): callable
    {
        return static fn (): PromiseInterface => reject(new \RuntimeException('transport died'));
    }

    /** @param list<array<array-key, mixed>> $releases */
    private static function pageBody(array $releases, string $link = ''): array
    {
        $spec = ['body' => (string) \json_encode($releases)];
        if ($link !== '') {
            $spec['link'] = $link;
        }

        return $spec;
    }

    /** A stable-channel release row carrying the two required assets. */
    private static function release(string $version): array
    {
        return [
            'tag_name' => SelfUpdate::TAG_PREFIX . 'v' . $version,
            'draft' => false,
            'prerelease' => false,
            'assets' => self::assets(),
        ];
    }

    /** The rolling dev release, naming (or not) a target commit. */
    private static function devRelease(string $commitish): array
    {
        return [
            'tag_name' => SelfUpdate::DEV_TAG,
            'draft' => false,
            'prerelease' => true,
            'target_commitish' => $commitish,
            'assets' => self::assets(),
        ];
    }

    /** @return list<array{name: string, browser_download_url: string}> */
    private static function assets(): array
    {
        return [
            ['name' => SelfUpdate::PHAR_ASSET, 'browser_download_url' => 'https://example.invalid/a.phar'],
            ['name' => SelfUpdate::SIDECAR_ASSET, 'browser_download_url' => 'https://example.invalid/a.sha256'],
        ];
    }

    private function writeCache(array $payload): void
    {
        \file_put_contents($this->cache, (string) \json_encode($payload));
    }

    /** @return string */
    private static function sandboxHome(): string
    {
        $home = \sys_get_temp_dir() . '/cc-uc-home-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($home, 0o700));
        // Move BOTH spellings — $_SERVER does not follow putenv (the
        // OneSidedHomeSandboxTest law); resolved() prefers getenv().
        $live = \getenv('HOME');
        self::$savedHome = $live === false ? null : $live;
        self::$savedServerHome = $_SERVER['HOME'] ?? null;
        \putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home;
        \clearstatcache();

        return $home;
    }

    private static function unownHome(string $home): void
    {
        self::assertTrue(\chmod($home, 0o777));
        \clearstatcache(true, $home);
    }

    private static function restoreHome(): void
    {
        \putenv(self::$savedHome === null ? 'HOME' : 'HOME=' . self::$savedHome);
        if (self::$savedServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = self::$savedServerHome;
        }
        \clearstatcache();
    }

    private static ?string $savedHome = null;
    private static ?string $savedServerHome = null;
}
