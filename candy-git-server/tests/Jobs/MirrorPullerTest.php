<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Jobs\MirrorPuller;
use SugarCraft\GitServer\Repo;

/**
 * Mirror-pull background job.
 *
 * Process execution is stubbed through the constructor's argv seam, so
 * no real `git fetch` runs.
 *
 * @covers \SugarCraft\GitServer\Jobs\MirrorPuller
 */
final class MirrorPullerTest extends TestCase
{
    private string $tmpDir;

    /** @var list<string> commands the exec stub captured */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/mirror-puller-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
        $this->commands = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->tmpDir . '/config.yaml');
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function config(string $schedule = '@every 10m', bool $allowGit = false): Config
    {
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: MirrorTest\njobs:\n mirror_pull: \"{$schedule}\"\n"
            . ($allowGit ? "git:\n mirror_allow_git_protocol: true\n" : '')
        );

        return Config::load($this->tmpDir . '/config.yaml');
    }

    private function puller(string $schedule = '@every 10m', int $exitCode = 0, ?\Closure $stub = null, bool $allowGit = false): MirrorPuller
    {
        return new MirrorPuller($this->config($schedule, $allowGit), $stub ?? function (array $argv) use ($exitCode): array {
            $this->commands[] = $argv;

            return ['code' => $exitCode, 'output' => $exitCode === 0 ? [] : ['fatal: mirror unreachable']];
        });
    }

    // -------------------------------------------------------------------------
    // Construction + selection
    // -------------------------------------------------------------------------

    public function testConstructorParsesConfiguredSchedule(): void
    {
        $puller = $this->puller('@every 8h');

        $this->assertSame(8 * 3600, $puller->schedule()->intervalSeconds);
    }

    // -------------------------------------------------------------------------
    // S05 (finding 005): tick budget + overlap guard
    // -------------------------------------------------------------------------

    public function testTickBudgetIsCappedAndStaysBelowTheScheduleInterval(): void
    {
        // 10 min cadence: the 60 s cap rules.
        $this->assertSame(60.0, $this->puller('@every 10m')->tickBudgetSeconds());
        // 90 s cadence: half the interval rules — a tick yields before
        // the next one is due, whatever the cap would allow.
        $this->assertSame(45.0, $this->puller('@every 90s')->tickBudgetSeconds());
        // The floor keeps a pathological 1 s schedule at a usable budget.
        $this->assertGreaterThanOrEqual(1.0, $this->puller('@every 2s')->tickBudgetSeconds());
    }

    public function testExhaustedBudgetDefersEveryMirrorWithoutStamping(): void
    {
        $puller = $this->puller();
        $puller->registerRepos([
            Repo::new('mirror-a', '/tmp/mirror-a')->withMirrorFrom('https://example.com/a.git'),
            Repo::new('mirror-b', '/tmp/mirror-b')->withMirrorFrom('https://example.com/b.git'),
        ]);

        // Zero budget: the admission check at the head of each iteration
        // refuses ALL fetches — and the deferred mirrors were never
        // stamped, so they are due again next tick (deferral is not
        // failure).
        $this->assertSame(0, $puller->runOnce(null, 0.0));
        $this->assertSame([], $this->commands, 'no fetch may start past the budget');
        $this->assertNull($puller->lastPullAt('mirror-a'));
        $this->assertNull($puller->lastPullAt('mirror-b'));
    }

    public function testEachFetchGetsTheBudgetRemainingAtItsAdmission(): void
    {
        $timeouts = [];
        $puller = new MirrorPuller($this->config('@every 10m'), function (array $argv, float $timeout) use (&$timeouts): array {
            $timeouts[] = $timeout;
            \usleep(150_000); // burn real wall time so the budget visibly shrinks

            return ['code' => 0, 'output' => []];
        });
        $puller->registerRepos([
            Repo::new('mirror-a', '/tmp/mirror-a')->withMirrorFrom('https://example.com/a.git'),
            Repo::new('mirror-b', '/tmp/mirror-b')->withMirrorFrom('https://example.com/b.git'),
        ]);

        $pulled = $puller->runOnce(null, 0.5);

        $this->assertSame(2, $pulled, 'a 0.5 s budget over ~0.15 s fetches admits both, not none');
        $this->assertCount(2, $timeouts);
        $this->assertLessThanOrEqual(0.5, $timeouts[0]);
        $this->assertGreaterThan($timeouts[1], $timeouts[0], 'the second fetch gets strictly less wall left');
        // The 0.1 s floor: an admission at the last instants still gets a
        // usable timeout rather than a meaningless 2 ms one.
        $this->assertGreaterThanOrEqual(0.1, $timeouts[1]);
    }

    public function testAReenteredTickSkipsInsteadOfInterleaving(): void
    {
        $puller = null;
        $calls = 0;
        $puller = new MirrorPuller($this->config('@every 10m'), function (array $argv) use (&$calls, &$puller): array {
            $calls++;
            if ($calls === 1) {
                // The pathological host: housekeeping re-enters the very
                // tick the seam is still executing.
                $inner = $puller->runOnce();
                $this->assertSame(0, $inner, 'the overlapped tick must log-skip and return zero');
            }

            return ['code' => 0, 'output' => []];
        });
        $puller->registerRepo(Repo::new('mirror-a', '/tmp/mirror-a')->withMirrorFrom('https://example.com/a.git'));

        $this->assertSame(1, $puller->runOnce(), 'the outer batch still completes normally');
        $this->assertSame(1, $calls, 'the inner tick must not run a second batch');
    }

    public function testTheGuardFlagClearsAfterAFailingBatch(): void
    {
        $puller = $this->puller('@every 10m', exitCode: 128); // every fetch "fails"
        $puller->registerRepo(Repo::new('mirror-a', '/tmp/mirror-a')->withMirrorFrom('https://example.com/a.git'));

        $this->assertSame(0, $puller->runOnce(1000, 5.0), 'failures count as zero pulled');
        // The finally-cleared flag: the NEXT tick (past the schedule gap —
        // failed attempts stamp, so the repo is due again after 600 s)
        // must run normally, not see a stale guard and skip forever.
        $this->assertSame(0, $puller->runOnce(1700, 5.0));
        $this->assertCount(2, $this->commands, 'both ticks really executed');
    }

    public function testConstructorThrowsOnInvalidSchedule(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->puller('*/5 * * * *');
    }

    public function testMirrorsSelectsOnlyReposWithMirrorFrom(): void
    {
        $puller = $this->puller();
        $puller->registerRepos([
            Repo::new('plain', '/tmp/plain'),
            Repo::new('mirror-a', '/tmp/mirror-a')->withMirrorFrom('https://example.com/a.git'),
            Repo::new('mirror-b', '/tmp/mirror-b')->withMirrorFrom('https://example.com/b.git'),
        ]);

        $names = \array_map(static fn (Repo $r) => $r->name, $puller->mirrors());

        $this->assertSame(['mirror-a', 'mirror-b'], $names);
    }

    public function testDueMirrorsRespectsScheduleInterval(): void
    {
        $puller = $this->puller('@every 10m');
        $mirror = Repo::new('m', '/tmp/m')->withMirrorFrom('https://example.com/m.git');
        $puller->registerRepo($mirror);

        // Never pulled: due.
        $this->assertCount(1, $puller->dueMirrors(1000));

        $puller->pull($mirror, 1000);

        // Just pulled: not due until the interval elapses.
        $this->assertCount(0, $puller->dueMirrors(1000 + 599));
        $this->assertCount(1, $puller->dueMirrors(1000 + 600));
    }

    // -------------------------------------------------------------------------
    // runOnce / pull
    // -------------------------------------------------------------------------

    public function testRunOnceExecutesFetchPruneForDueMirrors(): void
    {
        $puller = $this->puller();
        $puller->registerRepos([
            Repo::new('plain', '/tmp/plain'),
            Repo::new('m', '/tmp/repos/m')->withMirrorFrom('https://example.com/m.git'),
        ]);

        $pulled = $puller->runOnce(5000);

        $this->assertSame(1, $pulled);
        $this->assertCount(1, $this->commands);
        $this->assertSame(
            ['git', '-C', '/tmp/repos/m', 'fetch', '--prune', 'https://example.com/m.git', '+refs/*:refs/*'],
            $this->commands[0],
            'the fetch must reach the seam as a complete argv vector — no shell string anywhere'
        );
        $this->assertSame(5000, $puller->lastPullAt('m'));
    }

    public function testRunOnceSkipsMirrorsNotYetDue(): void
    {
        $puller = $this->puller('@every 10m');
        $puller->registerRepo(Repo::new('m', '/tmp/m')->withMirrorFrom('https://example.com/m.git'));

        $this->assertSame(1, $puller->runOnce(1000));
        $this->assertSame(0, $puller->runOnce(1300), 'second run inside the interval must be a no-op');
        $this->assertCount(1, $this->commands);
        $this->assertSame(1, $puller->runOnce(1600));
        $this->assertCount(2, $this->commands);
    }

    public function testFailedPullReturnsFalseButRecordsAttempt(): void
    {
        $puller = $this->puller('@every 10m', exitCode: 128);
        $mirror = Repo::new('broken', '/tmp/broken')->withMirrorFrom('https://example.com/broken.git');
        $puller->registerRepo($mirror);

        $this->assertSame(0, $puller->runOnce(2000));
        $this->assertCount(1, $this->commands);
        // The attempt is recorded even on failure so a broken upstream
        // cannot hot-loop the job.
        $this->assertSame(2000, $puller->lastPullAt('broken'));
        $this->assertCount(0, $puller->dueMirrors(2100));
    }

    public function testPullOnNonMirrorIsRefusedWithoutExec(): void
    {
        $puller = $this->puller();

        $this->assertFalse($puller->pull(Repo::new('plain', '/tmp/plain')));
        $this->assertSame([], $this->commands);
    }

    public function testMetacharactersReachGitAsOneArgumentNotShellSyntax(): void
    {
        $puller = $this->puller();
        $puller->registerRepo(
            Repo::new('evil', '/tmp/repos/evil$(rm -rf x)')
                ->withMirrorFrom('https://example.com/a.git;echo pwned')
        );

        $puller->runOnce(1000);

        $this->assertCount(1, $this->commands);
        // The classic escapeshellarg pin becomes structural truth instead:
        // with an argv vector there is no shell to escape, so the
        // metacharacters are simply characters of one argument each.
        $this->assertSame(
            ['git', '-C', '/tmp/repos/evil$(rm -rf x)', 'fetch', '--prune',
                'https://example.com/a.git;echo pwned', '+refs/*:refs/*',],
            $this->commands[0]
        );
    }

    public function testUpstreamUrlsOutsideTheSafetyEnvelopeAreRefusedBeforeAnySpawn(): void
    {
        $hostile = [
            'http://example.com/plain.git' => 'plaintext transport would ship credentials',
            'file:///etc/git/bare.git' => 'local-file transport reads the server disk',
            'ext::sh -c touch /tmp/pwned' => 'ext:: is remote code execution as a URL',
            '-u--upload-pack=touch /tmp/pwned' => 'leading dash turns the URL into a git option',
            '' => 'an empty URL cannot be fetched',
            'example.com/path/repo.git' => 'scp-style syntax carries no explicit trustworthy scheme',
        ];

        foreach ($hostile as $url => $why) {
            $this->commands = [];
            $puller = $this->puller();
            $puller->registerRepo(Repo::new('h' . \substr(\md5((string) $url), 0, 8), '/tmp/h')->withMirrorFrom((string) $url));

            $this->assertFalse($puller->pull($this->onlyMirror($puller)), (string) $why);
            $this->assertSame([], $this->commands, "refusal must not reach the fetch seam: {$why}");
        }
    }

    /**
     * S06 (finding 070): git:// authenticates NEITHER host NOR content —
     * a mirror pulled over it is trusting the wire. The default roster is
     * the safe set; git:// joins only under a named opt-in, and even then
     * every pull says so out loud.
     */
    public function testGitSchemeIsRefusedByDefaultAndAdmittedOnlyByNamedOptIn(): void
    {
        $logPath = $this->tmpDir . '/git-scheme.log';
        \ini_set('error_log', $logPath);

        try {
            $puller = $this->puller();
            $mirror = Repo::new('g', '/tmp/g')->withMirrorFrom('git://example.com/pub.git');
            $puller->registerRepo($mirror);

            $this->assertFalse($puller->pull($mirror), 'git:// must die by default');
            $this->assertCount(0, $this->commands, 'a refused scheme never reaches the runner');
            $log = (string) \file_get_contents($logPath);
            $this->assertStringContainsString('mirror pull refused', $log, 'the refusal is still logged');
            $this->assertStringContainsString('mirror_allow_git_protocol', $log, 'the refusal names the key to flip');

            $optedIn = $this->puller(allowGit: true);
            $this->assertTrue($optedIn->pull($mirror), 'the opt-in admits git://');
            $this->assertCount(1, $this->commands);
            $log = (string) \file_get_contents($logPath);
            $this->assertStringContainsString('under git.mirror_allow_git_protocol', $log, 'an opted-in git:// pull is announced every time');
        } finally {
            \ini_set('error_log', '');
            @\unlink($logPath);
        }
    }

    public function testSchemeAllowlistIsTheNamedRosterAndExtDiesByName(): void
    {
        // S06 (finding 070): the roster is the safe-by-default decision —
        // git:// left it (unauthenticated transport) and lives behind
        // git.mirror_allow_git_protocol instead. Adding or removing any
        // transport must be a deliberate edit that reddens here, never a
        // silent side effect of touching the refusal ladder.
        $this->assertSame(['https', 'ssh'], MirrorPuller::ALLOWED_SCHEMES);

        $refuse = new \ReflectionMethod(MirrorPuller::class, 'upstreamRefusal');
        $refuse->setAccessible(true);
        /** @param callable(string, bool): ?string */
        $refusal = static fn (string $url, bool $allowGit = false): ?string => $refuse->invoke(null, $url, $allowGit);

        $gitDeny = $refusal('git://example.com/pub.git');
        $this->assertNotNull($gitDeny, 'git:// is refused by default now (finding 070)');
        $this->assertStringContainsString('mirror_allow_git_protocol', $gitDeny, 'the denial names the opt-in key');
        $this->assertNull($refusal('git://example.com/pub.git', true), 'the opt-in admits it');
        $this->assertNull($refusal('SSH://example.com/p.git'), 'scheme match is case-insensitive');

        // "ext:: refused by name": the named leg must carry its own message,
        // not merely fall through to the generic roster refusal. Delete the
        // stripos guard and these two assertions go red even though the URL
        // still refuses (via scheme) — the leg stops being unpinned defense
        // in depth and becomes the named, load-bearing gate it claims to be.
        $byName = $refusal('ext::sh -c touch /tmp/pwned');
        $this->assertNotNull($byName);
        $this->assertStringContainsString('ext::', $byName);
        $this->assertStringContainsString('executes', $byName);
        $this->assertStringNotContainsString('must be one of', $byName, 'the named leg must fire before the roster leg');
        $this->assertStringContainsString('ext::', (string) $refusal('EXT::sh'), 'the named match is case-insensitive too');

        // Everything else outside the roster dies on the roster message.
        $offRoster = $refusal('ftp://example.com/x.git');
        $this->assertNotNull($offRoster);
        $this->assertStringContainsString('must be one of https, ssh', $offRoster);
    }

    public function testCredentialsAreRedactedInFailureLogs(): void
    {
        $logPath = $this->tmpDir . '/error.log';
        \ini_set('error_log', $logPath);

        try {
            $url = 'https://user:hunter2@example.com/m.git';
            $puller = $this->puller('@every 10m', stub: static function (array $argv) use ($url): array {
                // git echoes the credentialed remote in its own failure text
                return ['code' => 128, 'output' => ["fatal: Authentication failed for '{$url}/'"]];
            });
            $mirror = Repo::new('cred', '/tmp/cred')->withMirrorFrom($url);
            $puller->registerRepo($mirror);

            $this->assertFalse($puller->pull($mirror, 1000));

            $log = (string) \file_get_contents($logPath);
            $this->assertStringNotContainsString('hunter2', $log, 'a password must never reach the log');
            $this->assertStringContainsString('://user:***@example.com', $log);
        } finally {
            \ini_restore('error_log');
        }
    }

    private function onlyMirror(MirrorPuller $puller): Repo
    {
        $mirrors = $puller->mirrors();
        $this->assertCount(1, $mirrors);

        return $mirrors[0];
    }

    // -------------------------------------------------------------------------
    // Event-loop integration
    // -------------------------------------------------------------------------

    public function testAttachRunsPullsOnPeriodicTimer(): void
    {
        $puller = $this->puller('@every 1s');
        $puller->registerRepo(Repo::new('m', '/tmp/m')->withMirrorFrom('https://example.com/m.git'));

        $loop = new StreamSelectLoop();
        $puller->attach($loop);
        $this->assertTrue($puller->isAttached());

        $loop->addTimer(1.2, static fn () => $loop->stop());
        $loop->run();

        $puller->detach();
        $this->assertFalse($puller->isAttached());
        $this->assertNotEmpty($this->commands, 'periodic timer must trigger a pull');
    }

    public function testAttachTwiceThrows(): void
    {
        $puller = $this->puller();
        $loop = new StreamSelectLoop();
        $puller->attach($loop);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already attached');
            $puller->attach($loop);
        } finally {
            $puller->detach();
        }
    }

    public function testDetachIsIdempotent(): void
    {
        $puller = $this->puller();
        $puller->detach();

        $this->assertFalse($puller->isAttached());
    }
}
