<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Jobs\MirrorPuller;
use SugarCraft\Serve\Repo;

/**
 * Mirror-pull background job — plan item 7.5.
 *
 * Process execution is stubbed through the constructor's exec seam, so
 * no real `git fetch` runs.
 *
 * @covers \SugarCraft\Serve\Jobs\MirrorPuller
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

    private function config(string $schedule = '@every 10m'): Config
    {
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: MirrorTest\njobs:\n  mirror_pull: \"{$schedule}\"\n"
        );

        return Config::load($this->tmpDir . '/config.yaml');
    }

    private function puller(string $schedule = '@every 10m', int $exitCode = 0): MirrorPuller
    {
        return new MirrorPuller($this->config($schedule), function (string $cmd) use ($exitCode): array {
            $this->commands[] = $cmd;

            return [$exitCode, $exitCode === 0 ? [] : ['fatal: mirror unreachable']];
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
        $this->assertStringContainsString("git -C '/tmp/repos/m' fetch --prune", $this->commands[0]);
        $this->assertStringContainsString("'https://example.com/m.git'", $this->commands[0]);
        $this->assertStringContainsString("'+refs/*:refs/*'", $this->commands[0]);
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

    public function testEscapesShellMetacharactersInUrlAndPath(): void
    {
        $puller = $this->puller();
        $puller->registerRepo(
            Repo::new('evil', '/tmp/repos/evil$(rm -rf x)')
                ->withMirrorFrom('https://example.com/a.git;echo pwned')
        );

        $puller->runOnce(1000);

        $this->assertCount(1, $this->commands);
        $this->assertStringContainsString("'/tmp/repos/evil\$(rm -rf x)'", $this->commands[0]);
        $this->assertStringContainsString("'https://example.com/a.git;echo pwned'", $this->commands[0]);
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
