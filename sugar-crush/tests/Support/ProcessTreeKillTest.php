<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Audit B2/F-E2: {@see ProcessContainment::killTree()} reaches the setsid'd
 * command a forked child was running, and forks below that child, while never
 * signalling the caller's own process group.
 *
 * Before the fix both live kill sites sent `posix_kill($pid, 9)` to the forked
 * PHP child only; the Bash command it ran leads its own session (setsid -w) and
 * was reparented to init, still running — a cancelled command finished anyway.
 */
final class ProcessTreeKillTest extends TestCase
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
            self::markTestSkipped('killTree()\'s tree walk is Linux /proc only; elsewhere it is the direct kill.');
        }
        if (ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('no usable setsid(1): commands are not detached, so there is no group to orphan.');
        }

        $this->dir = \sys_get_temp_dir() . '/killtree_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
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

    public function testParseStatReadsFieldsAfterTheLastParenthesis(): void
    {
        $stat = ProcessTree::parseStat('4242 (evil) (S 1 2 3) R 77 88 99 0 -1');

        self::assertSame(['pid' => 4242, 'state' => 'R', 'ppid' => 77, 'pgid' => 88, 'sid' => 99], $stat);
        self::assertNull(ProcessTree::parseStat('garbage'));
    }

    public function testDescendantsFollowsTheParentRelationOnly(): void
    {
        $row = static fn(int $pid, int $ppid): array => ['pid' => $pid, 'state' => 'S', 'ppid' => $ppid, 'pgid' => $pid, 'sid' => $pid];
        $table = [10 => $row(10, 1), 11 => $row(11, 10), 12 => $row(12, 11), 13 => $row(13, 1), 14 => $row(14, 10)];

        $found = ProcessTree::descendants(10, $table);
        \sort($found);

        self::assertSame([11, 12, 14], $found);
        self::assertSame([], ProcessTree::descendants(13, $table));
    }

    public function testTheSetsidCommandAForkedChildIsRunningDiesWithIt(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $child = $this->forkTracked();
        if ($child === 0) {
            (new Bash($this->dir))->execute(['command' => $marker]);
            ForkedChild::exitNow(0);
        }

        $command = $this->awaitMarker($marker);
        self::assertNotSame([], $command, 'the command never started, so the test proves nothing');
        $commandPgid = ProcessTree::stat($command[0])['pgid'] ?? 0;
        self::assertNotSame(\posix_getpgrp(), $commandPgid, 'the command must run in its own setsid group');

        ProcessContainment::killTree($child);
        \pcntl_waitpid($child, $status);
        $this->forgetForkedChild($child);

        self::assertSame([], $this->awaitGone($marker, 2.0), 'the setsid command outlived the killed child');
    }

    public function testACommandRunByAForkBelowTheKilledChildNeverFinishes(): void
    {
        $file = $this->dir . '/survived';
        $child = $this->forkTracked();
        if ($child === 0) {
            // The Task-cascade shape: the killed process is not the one
            // running the command — a fork of it is.
            $grandchild = $this->forkTracked();
            if ($grandchild === 0) {
                (new Bash($this->dir))->execute(['command' => 'sleep 1.5; echo survived > ' . \escapeshellarg($file)]);
                ForkedChild::exitNow(0);
            }
            \usleep(20_000_000);
            ForkedChild::exitNow(0);
        }

        \usleep(400_000);
        ProcessContainment::killTree($child);
        \pcntl_waitpid($child, $status);
        $this->forgetForkedChild($child);

        \usleep(2_500_000);
        self::assertFileDoesNotExist($file, 'a command under a fork of the killed child ran to completion');
    }

    public function testTheCallersOwnGroupAndNonDescendantsAreNeverSignalled(): void
    {
        $sibling = $this->forkTracked();
        if ($sibling === 0) {
            \usleep(5_000_000);
            ForkedChild::exitNow(0);
        }

        $target = $this->forkTracked();
        if ($target === 0) {
            \usleep(5_000_000);
            ForkedChild::exitNow(0);
        }

        // Both forks share this process's group — the shape of every fork the
        // TUI makes. A group kill of that pgrp would take the test runner out.
        self::assertSame(\posix_getpgrp(), \posix_getpgid($target));

        ProcessContainment::killTree($target);
        \pcntl_waitpid($target, $status);
        $this->forgetForkedChild($target);

        self::assertTrue(\pcntl_wifsignaled($status), 'the target itself was killed');
        self::assertSame(0, \pcntl_waitpid($sibling, $siblingStatus, \WNOHANG), 'a non-descendant in the same group was signalled');
        self::assertSame('S', ProcessTree::stat($sibling)['state'] ?? null, 'the sibling was left stopped or killed');
    }

    public function testRefusesZeroAndTheCallersOwnPid(): void
    {
        $sentinel = $this->dir . '/alive';
        $child = $this->forkTracked();
        if ($child === 0) {
            // Own group first, so a regression that DID signal 0 or self stops
            // or kills only this child, never the runner.
            \posix_setpgid(0, 0);
            ProcessContainment::killTree(0);
            ProcessContainment::killTree(\posix_getpid());
            \file_put_contents($sentinel, 'ok');
            ForkedChild::exitNow(0);
        }

        $deadline = \microtime(true) + 3.0;
        while (\pcntl_waitpid($child, $status, \WNOHANG) === 0 && \microtime(true) < $deadline) {
            \usleep(10_000);
        }
        $this->forgetForkedChild($child);
        @\posix_kill($child, 9);

        self::assertFileExists($sentinel, 'killTree() signalled pid 0 or the caller itself');
    }

    /**
     * @return list<int>
     */
    private function awaitMarker(string $marker): array
    {
        $deadline = \microtime(true) + 5.0;
        do {
            $pids = self::pidsRunning($marker);
            if ($pids !== []) {
                // The `sleep` process itself, plus every shell that names it.
                \array_push($this->strays, ...$pids);

                return $pids;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        return [];
    }

    /**
     * @return list<int> survivors after the budget
     */
    private function awaitGone(string $marker, float $budget): array
    {
        $deadline = \microtime(true) + $budget;
        do {
            $pids = self::pidsRunning($marker);
            if ($pids === []) {
                return [];
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        return $pids;
    }

    /**
     * Live (non-zombie) processes whose argv contains $marker.
     *
     * @return list<int>
     */
    public static function pidsRunning(string $marker): array
    {
        $found = [];
        foreach (\glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $cmdline = @\file_get_contents($path);
            if (!\is_string($cmdline) || !\str_contains(\str_replace("\0", ' ', $cmdline), $marker)) {
                continue;
            }
            $pid = (int) \basename(\dirname($path));
            $state = ProcessTree::stat($pid)['state'] ?? 'X';
            if ($state !== 'Z' && $state !== 'X') {
                $found[] = $pid;
            }
        }

        return $found;
    }
}
