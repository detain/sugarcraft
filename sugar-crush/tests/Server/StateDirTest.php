<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Crush\Server\DiscoveryFile;
use SugarCraft\Crush\Server\ParentPidWatchdog;
use SugarCraft\Crush\Server\ServerConfigException;
use SugarCraft\Crush\Server\StateDir;
use SugarCraft\Crush\Support\Daemonize;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * The server's state directory, discovery record and parent-pid watchdog
 * (Appendix O §4.6; roadmap O-4a): an owner-private directory, an OS-level
 * singleton lock, a record that is checked by pid AND start time before it is
 * believed, and a watchdog that sees a recycled pid as a dead parent.
 */
final class StateDirTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $path = '';

    /** @var list<StateDir> */
    private array $opened = [];

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/state-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->opened as $state) {
            $state->releaseLock();
        }
        foreach ((array) \glob($this->path . '/*') as $file) {
            @\unlink((string) $file);
        }
        @\chmod($this->path, 0o700);
        @\rmdir($this->path);
    }

    private function open(): StateDir
    {
        return $this->opened[] = StateDir::open($this->path);
    }

    public function testOpenCreatesAnOwnerPrivateDirectoryAndExistingCreatesNothing(): void
    {
        self::assertNull(StateDir::existing($this->path), 'nothing is there yet');
        self::assertFileDoesNotExist($this->path, 'asking must not create the directory');

        $state = $this->open();
        self::assertSame($this->path, $state->path);
        self::assertSame(0o700, \fileperms($this->path) & 0o777);
        self::assertSame($this->path . '/server.json', $state->discoveryPath());
        self::assertSame($this->path . '/server.lock', $state->lockPath());
        self::assertSame($this->path . '/server.log', $state->logPath());
        self::assertSame($this->path . '/control.sock', $state->controlSocketPath());
        self::assertNotNull(StateDir::existing($this->path . '/'));
    }

    public function testALooseOrLinkedDirectoryIsRefused(): void
    {
        $this->open();
        \chmod($this->path, 0o755);

        try {
            StateDir::existing($this->path);
            self::fail('a 0755 state directory was trusted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not 0700', $e->getMessage());
        }

        \chmod($this->path, 0o700);
        $link = $this->path . '-link';
        \symlink($this->path, $link);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symbolic link');
            StateDir::open($link);
        } finally {
            @\unlink($link);
        }
    }

    public function testTheLockIsASingletonUntilReleased(): void
    {
        $first = $this->open();
        $second = $this->open();
        $registered = ForkedChild::registeredServerStreams();

        self::assertFalse($second->lockHeldElsewhere());
        self::assertTrue($first->acquireLock());
        self::assertTrue($first->holdsLock());
        self::assertSame($registered + 1, ForkedChild::registeredServerStreams(), 'a forked turn child must close the lock');
        self::assertSame(0o600, \fileperms($first->lockPath()) & 0o777);

        self::assertTrue($second->lockHeldElsewhere());
        self::assertFalse($second->acquireLock(), 'a second server on the same directory');
        self::assertFalse($first->lockHeldElsewhere(), 'the holder does not report itself');

        $first->releaseLock();
        self::assertSame($registered, ForkedChild::registeredServerStreams());
        self::assertFalse($second->lockHeldElsewhere());
        self::assertTrue($second->acquireLock());
    }

    public function testForgetLockLetsGoOfTheHandleWithoutUnlockingAnInheritedCopy(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('needs pcntl');
        }

        $state = $this->open();
        self::assertTrue($state->acquireLock());

        // A child inherits the same open file description — as the daemon
        // does — and holds it while the parent forgets its own handle.
        [$ready, $readyChild] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $pid = $this->forkTracked();
        if ($pid === 0) {
            \fclose($ready);
            \fwrite($readyChild, "go\n");
            \usleep(1_500_000);
            ForkedChild::exitNow(0);
        }
        \fclose($readyChild);
        \fgets($ready);
        \fclose($ready);

        $state->forgetLock();
        self::assertFalse($state->holdsLock());
        self::assertTrue($this->open()->lockHeldElsewhere(), 'forgetLock() unlocked the copy the child still holds');

        $this->reapTrackedForkedChildren(5.0);
        self::assertFalse($this->open()->lockHeldElsewhere(), 'the kernel releases the lock with its last holder');
    }

    public function testTheLogRotatesThroughThreeGenerations(): void
    {
        $state = $this->open();
        $log = $state->logPath();

        self::assertFalse($state->rotateLog(10), 'no log, nothing to rotate');
        \file_put_contents($log, 'small');
        self::assertFalse($state->rotateLog(10));

        foreach (['first', 'second', 'third'] as $generation) {
            \file_put_contents($log, \str_repeat($generation, 10));
            self::assertTrue($state->rotateLog(10));
            self::assertFileDoesNotExist($log);
        }

        self::assertStringStartsWith('third', (string) \file_get_contents($log . '.1'));
        self::assertStringStartsWith('second', (string) \file_get_contents($log . '.2'));
        self::assertFileDoesNotExist($log . '.3', 'only ' . StateDir::LOG_FILES . ' files are kept');
    }

    public function testTheDiscoveryRecordRoundTripsPrivatelyAndNeverCarriesTheToken(): void
    {
        $state = $this->open();
        self::assertNull(DiscoveryFile::read($state));

        $record = DiscoveryFile::forThisProcess('v9', 'http://127.0.0.1:7420', '127.0.0.1', 7420, '/proj', true, $state->logPath(), 1_000);
        $record->write($state);

        self::assertSame(0o600, \fileperms($state->discoveryPath()) & 0o777);
        $read = DiscoveryFile::read($state);
        self::assertNotNull($read);
        self::assertSame($record->toArray(), $read->toArray());
        self::assertSame(\getmypid(), $read->pid);
        self::assertSame(['min' => 1, 'max' => 1], $read->protocol);
        self::assertSame(
            ['pid', 'procStartTime', 'version', 'protocol', 'url', 'host', 'port', 'root', 'startedAt', 'detached', 'log'],
            \array_keys($read->toArray()),
        );
        self::assertTrue($read->isLive(), 'this process is alive and is the process the record names');
        self::assertSame(5, $read->uptime(1_005));

        $read->removeFrom($state);
        self::assertFileDoesNotExist($state->discoveryPath());
    }

    public function testARecordIsAClaimNotAFact(): void
    {
        $state = $this->open();

        self::assertNull(DiscoveryFile::fromArray(['pid' => 'x', 'url' => 'u']));
        self::assertNull(DiscoveryFile::fromArray(['pid' => 1]));
        \file_put_contents($state->discoveryPath(), '{torn');
        self::assertNull(DiscoveryFile::read($state));

        $start = Daemonize::startTime((int) \getmypid());
        if ($start === null) {
            self::markTestSkipped('procfs cannot report a start time here');
        }

        // This pid, but a different start time: a recycled number.
        $recycled = DiscoveryFile::fromArray(['pid' => (int) \getmypid(), 'procStartTime' => $start + 1, 'url' => 'http://127.0.0.1:1']);
        self::assertNotNull($recycled);
        self::assertFalse($recycled->isLive());

        // A record that is not this process's is never removed by it.
        $ours = DiscoveryFile::forThisProcess('v', 'http://127.0.0.1:1', '127.0.0.1', 1, null, false, null);
        $ours->write($state);
        $recycled->removeFrom($state);
        self::assertFileExists($state->discoveryPath(), 'a server removed a record that was not its own');
    }

    public function testTheParentPidMustBeAPid(): void
    {
        self::assertNull(ParentPidWatchdog::parse(null));
        self::assertNull(ParentPidWatchdog::parse(' '));
        self::assertSame(42, ParentPidWatchdog::parse(' 42 '));

        foreach (['0', '-1', 'abc', '4.2', '99999999999'] as $bad) {
            try {
                ParentPidWatchdog::parse($bad);
                self::fail("parent pid {$bad} was accepted");
            } catch (ServerConfigException $e) {
                self::assertStringContainsString('is not a process id', $e->getMessage());
            }
        }
    }

    public function testAParentThatIsNotRunningIsRefused(): void
    {
        $this->expectException(ServerConfigException::class);
        $this->expectExceptionMessage('is not running');
        ParentPidWatchdog::of(0x7ffffffe);
    }

    public function testTheWatchdogFiresOnceWhenTheParentExits(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('needs pcntl');
        }

        $pid = $this->forkTracked();
        if ($pid === 0) {
            \usleep(300_000);
            ForkedChild::exitNow(0);
        }

        $watchdog = ParentPidWatchdog::of($pid);
        self::assertTrue($watchdog->parentAlive());

        $loop = new StreamSelectLoop();
        $gone = [];
        $watchdog->arm($loop, static function (int $parent) use (&$gone, $loop): void {
            $gone[] = $parent;
            $loop->stop();
        }, 0.05);
        $safety = $loop->addTimer(10.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);

        self::assertSame([$pid], $gone, 'the watchdog fires exactly once, with the parent pid');
        self::assertFalse($watchdog->parentAlive());
        $watchdog->disarm($loop);
    }
}
