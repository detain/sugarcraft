<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Audit R3: {@see ProcessContainment::killTreeAsync()} and
 * {@see ProcessContainment::reapAsync()} do the work killTree() and the
 * bounded WNOHANG reaps did, without holding the event-loop thread for it.
 *
 * MEASURED before the fix: killTree() spent ~90-100 ms of one loop callback on
 * its /proc walk (three ~33 ms snapshots on a 1,155-process host) and the reap
 * after it ~5 ms more, so the Escape that cancelled a turn froze the frame. The
 * properties pinned here are the ones that make the async spelling a drop-in:
 * the root is held from the call on, the loop turns while the walk runs, the
 * whole tree still dies, and a walk the loop never finishes is finished at
 * shutdown instead of leaving a stopped tree behind.
 */
final class ProcessTreeKillAsyncTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    /** @var list<int> */
    private array $strays = [];

    private string $dir = '';

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill') || !\function_exists('posix_getpgrp')) {
            self::markTestSkipped('ext-pcntl and ext-posix are required to fork and signal a real tree.');
        }
        if (!ProcessTree::available()) {
            self::markTestSkipped('the tree walk is Linux /proc only; elsewhere killTreeAsync() is the direct kill.');
        }
        if (ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('no usable setsid(1): commands are not detached, so there is no group to orphan.');
        }

        $this->dir = \sys_get_temp_dir() . '/killtree_async_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->strays as $pid) {
            @\posix_kill($pid, 9);
        }
        if ($this->dir !== '' && \is_dir($this->dir)) {
            foreach (\glob($this->dir . '/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->dir);
        }

        parent::tearDown();
    }

    /**
     * The R3 property itself. Before the fix there was no spelling that let
     * the loop run between "Escape was seen" and "the tree is dead": the
     * whole walk happened inside the caller's callback.
     */
    public function testTheRootIsHeldAtOnceAndTheLoopTurnsWhileTheTreeIsWalked(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $child = $this->forkTracked();
        if ($child === 0) {
            (new Bash($this->dir))->execute(['command' => $marker]);
            ForkedChild::exitNow(0);
        }
        $command = ProcessTreeKillTest::awaitRunning($marker, 5.0);
        \array_push($this->strays, ...$command);
        self::assertNotSame([], $command, 'fixture: the command never started');

        $loop = Loop::get();
        $promise = ProcessContainment::killTreeAsync($child, $loop);

        $resolved = false;
        $promise->then(static function () use (&$resolved): void {
            $resolved = true;
        });
        self::assertFalse($resolved, 'the kill went out inside the call, so the walk still ran on the caller\'s thread');
        // SIGSTOP went out inside the call, but the kernel applies it when the
        // target next runs: a root busy on another CPU still reads 'R' for a
        // moment (measured on CI). Poll WITHOUT turning the loop, so nothing
        // the promise schedules can be what stopped it.
        $state = null;
        for ($i = 0; $i < 200 && $state !== 'T'; $i++) {
            $state = ProcessTree::stat($child)['state'] ?? null;
            if ($state !== 'T') {
                \usleep(5_000);
            }
        }
        self::assertSame('T', $state, 'the root must be stopped before the call returns');

        $ticksWhilePending = 0;
        $heartbeat = $loop->addPeriodicTimer(0.001, static function () use (&$ticksWhilePending, &$resolved): void {
            if (!$resolved) {
                $ticksWhilePending++;
            }
        });
        $this->settle($promise);
        $loop->cancelTimer($heartbeat);

        self::assertTrue($resolved, 'the async kill never resolved');
        self::assertGreaterThanOrEqual(2, $ticksWhilePending, 'the loop did not turn while the tree was being walked');

        \pcntl_waitpid($child, $status);
        $this->forgetForkedChild($child);
        self::assertTrue(\pcntl_wifsignaled($status) && \pcntl_wtermsig($status) === 9, 'the root was not SIGKILLed');
        self::assertSame([], ProcessTreeKillTest::survivingAfter($marker, 2.0), 'the setsid command outlived the async tree kill');
    }

    /**
     * A deferred kill can be stranded: Ctrl+C right after Escape stops the
     * loop with the walk half done, and the tree it already SIGSTOPped would
     * sit stopped for ever. The shutdown backstop must finish it.
     *
     * Driven in a separate PHP process whose loop is never run, so the only
     * thing that can finish the walk is that process's shutdown. Its output
     * goes to a file, never to a pipe this test reads, so a tree left stopped
     * cannot hold the exec() open; timeout(1) bounds it anyway.
     */
    public function testAWalkTheLoopNeverFinishesIsFinishedAtShutdown(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $script = $this->dir . '/stranded.php';
        $log = $this->dir . '/stranded.log';
        \file_put_contents($script, \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            require %s;
            $child = pcntl_fork();
            if ($child === 0) {
                (new \SugarCraft\Crush\Tools\BuiltIn\Bash(%s))->execute(['command' => %s]);
                \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
            }
            echo $child, "\n";
            $up = static function (): bool {
                foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
                    if (str_contains(str_replace("\0", ' ', (string) @file_get_contents($path)), %s)) {
                        return true;
                    }
                }

                return false;
            };
            $deadline = microtime(true) + 5.0;
            while (!$up() && microtime(true) < $deadline) {
                usleep(20000);
            }
            // A loop nobody will ever run: no tick can finish this walk.
            \SugarCraft\Crush\Support\ProcessContainment::killTreeAsync($child, new \React\EventLoop\StreamSelectLoop());
            echo "returned\n";
            PHP,
            \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            \var_export($this->dir, true),
            \var_export($marker, true),
            \var_export($marker, true),
        ));

        \exec(
            'timeout -s KILL 20 ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script)
                . ' > ' . \escapeshellarg($log) . ' 2>&1',
            $unused,
            $exit,
        );
        $lines = \file($log, \FILE_IGNORE_NEW_LINES) ?: [];
        $root = (int) ($lines[0] ?? 0);
        if ($root > 0) {
            $this->strays[] = $root;
        }
        \array_push($this->strays, ...ProcessTreeKillTest::pidsRunning($marker));

        self::assertSame(0, $exit, 'the driver script failed: ' . \implode("\n", $lines));
        self::assertContains('returned', $lines, 'killTreeAsync() did not return before the kill: ' . \implode("\n", $lines));
        self::assertSame([], ProcessTreeKillTest::survivingAfter($marker, 2.0), 'a walk stranded by a stopped loop left its tree alive (stopped) after the process exited');
    }

    public function testRefusesZeroAndTheCallersOwnPid(): void
    {
        $sentinel = $this->dir . '/alive';
        $child = $this->forkTracked();
        if ($child === 0) {
            // Own group first, so a regression that DID signal 0 or self stops
            // or kills only this child, never the runner.
            \posix_setpgid(0, 0);
            ProcessContainment::killTreeAsync(0);
            ProcessContainment::killTreeAsync(\posix_getpid());
            \file_put_contents($sentinel, 'ok');
            ForkedChild::exitNow(0);
        }

        $deadline = \microtime(true) + 3.0;
        while (\pcntl_waitpid($child, $status, \WNOHANG) === 0 && \microtime(true) < $deadline) {
            \usleep(10_000);
        }
        $this->forgetForkedChild($child);
        @\posix_kill($child, 9);

        self::assertFileExists($sentinel, 'killTreeAsync() signalled pid 0 or the caller itself');
    }

    public function testReapAsyncCollectsAnExitedChildWithoutArmingATimer(): void
    {
        $child = $this->forkTracked();
        if ($child === 0) {
            ForkedChild::exitNow(0);
        }
        $deadline = \microtime(true) + 2.0;
        while ((ProcessTree::stat($child)['state'] ?? 'X') !== 'Z' && \microtime(true) < $deadline) {
            \usleep(5_000);
        }

        $unreaped = null;
        ProcessContainment::reapAsync([$child], 0.1)->then(static function (array $left) use (&$unreaped): void {
            $unreaped = $left;
        });
        $this->forgetForkedChild($child);

        self::assertSame([], $unreaped, 'an already-exited child is collected on the first, synchronous poll');
        self::assertSame(-1, \pcntl_waitpid($child, $status, \WNOHANG), 'the reap left a zombie');
    }

    /**
     * The give-up half, which is where the old window cost the loop its whole
     * budget: a child nothing killed is polled on timers, handed back, and the
     * call itself returns at once.
     */
    public function testReapAsyncGivesUpOnALiveChildWithoutBlocking(): void
    {
        $child = $this->forkTracked();
        if ($child === 0) {
            \usleep(3_000_000);
            ForkedChild::exitNow(0);
        }

        $started = \microtime(true);
        $promise = ProcessContainment::reapAsync([$child], 0.1, 0.005, Loop::get());
        $returnedAfter = \microtime(true) - $started;

        $unreaped = null;
        $promise->then(static function (array $left) use (&$unreaped): void {
            $unreaped = $left;
        });
        self::assertNull($unreaped, 'a live child cannot have been settled synchronously');
        self::assertLessThan(0.05, $returnedAfter, 'reapAsync() spent its budget on the caller\'s thread');

        $this->settle($promise);
        self::assertSame([$child], $unreaped, 'the live child must be handed back for a later sweep');
        self::assertTrue(\posix_kill($child, 0), 'fixture: the child must still be alive');
    }

    private function settle(PromiseInterface $promise): void
    {
        $loop = Loop::get();
        $done = false;
        $promise->then(static function () use (&$done, $loop): void {
            $done = true;
            $loop->stop();
        });
        if (!$done) {
            $guard = $loop->addTimer(10.0, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }
    }
}
