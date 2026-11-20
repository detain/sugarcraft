<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Support\TurnInterrupts;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;

/**
 * ShellSend::relayReady() is the one readiness rule for an interactive shell's
 * relay. The pid record it waits on must name a pid, not merely exist: an
 * empty pid file is a write in progress, and a ShellStop that reads it finds no
 * process group to signal.
 */
final class ShellSendRelayReadyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function pidFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'relay-pid-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    public function testAPositivePidRecordIsReady(): void
    {
        $this->assertTrue(ShellSend::relayReady(['ptyPidPath' => $this->pidFile("4242\n")]));
    }

    public function testAnEmptyPidRecordIsNotReadyUntilTheBudgetPasses(): void
    {
        // A zero-byte file is the truncated window of a non-atomic write: the
        // relay has not recorded its pid yet, so the gate must not open on it.
        $this->assertFalse(ShellSend::relayReady(['ptyPidPath' => $this->pidFile('')]));
    }

    public function testARowWithNoPidPathIsReadyControl(): void
    {
        // Control: a pre-relay merged row names no pid path at all, and the
        // gate has always let it through, so the tightened check must not
        // reach that branch.
        $this->assertTrue(ShellSend::relayReady([]));
    }

    public function testTheAttachedProbeIsTheSameRuleAsTheWaitingOne(): void
    {
        $this->assertTrue(ShellSend::relayAttached(['ptyPidPath' => $this->pidFile("4242\n")]));
        $this->assertFalse(ShellSend::relayAttached(['ptyPidPath' => $this->pidFile('')]));
        $this->assertTrue(ShellSend::relayAttached([]), 'a pre-relay row is attached by the same control as relayReady');
    }

    public function testAsyncReadinessResolvesAtOnceWhenTheRecordIsAlreadyThere(): void
    {
        $answer = null;
        ShellSend::relayReadyAsync(['ptyPidPath' => $this->pidFile("4242\n")], new StreamSelectLoop())
            ->then(static function (bool $ready) use (&$answer): void {
                $answer = $ready;
            });

        $this->assertTrue($answer, 'an attached relay answers without touching the loop');
    }

    public function testAsyncReadinessWaitsOnTheLoopAndResolvesTrueWhenTheRecordLands(): void
    {
        $loop = new StreamSelectLoop();
        $path = $this->pidFile('');
        // The relay attaches a little after the send is queued: the record is
        // written from a loop timer, so the wait must be the loop's, not a sleep.
        $loop->addTimer(0.1, static function () use ($path): void {
            file_put_contents($path, "4242\n");
        });
        $answer = null;
        $loop->addTimer(10.0, static fn () => $loop->stop());
        ShellSend::relayReadyAsync(['ptyPidPath' => $path], $loop)
            ->then(static function (bool $ready) use (&$answer, $loop): void {
                $answer = $ready;
                $loop->stop();
            });
        $loop->run();

        $this->assertTrue($answer, 'the record that landed mid-wait opens the gate');
    }

    public function testAsyncReadinessResolvesFalseOnlyAfterTheBudgetPasses(): void
    {
        $loop = new StreamSelectLoop();
        $answer = null;
        $started = microtime(true);
        $loop->addTimer(ShellSend::RELAY_ATTACH_BUDGET_MS / 1000 + 5.0, static fn () => $loop->stop());
        ShellSend::relayReadyAsync(['ptyPidPath' => $this->pidFile('')], $loop)
            ->then(static function (bool $ready) use (&$answer, $loop): void {
                $answer = $ready;
                $loop->stop();
            });
        $loop->run();
        $elapsed = microtime(true) - $started;

        $this->assertFalse($answer, 'no record inside the budget is a refusal, never a lost write');
        $this->assertGreaterThanOrEqual(ShellSend::RELAY_ATTACH_BUDGET_MS / 1000 * 0.9, $elapsed, 'the wait spans the declared budget');
        $this->assertLessThan(ShellSend::RELAY_ATTACH_BUDGET_MS / 1000 + 2.0, $elapsed, 'and stops at it');
    }

    /** A stop waiting on the channel ends the screen hold at once, not after its budget. */
    public function testAStopEndsTheScreenHoldAtOnce(): void
    {
        TurnInterrupts::listen(static fn (): bool => true);
        try {
            $started = microtime(true);
            ShellSend::holdScreen(10_000, 'call_hold');
            $elapsed = microtime(true) - $started;
        } finally {
            TurnInterrupts::forget();
        }

        self::assertLessThan(1.0, $elapsed, 'a pending stop must not wait out the 10 s hold');
    }

    /** With nothing to stop it, the hold runs its whole budget. */
    public function testAnUninterruptedScreenHoldRunsItsBudget(): void
    {
        $started = microtime(true);
        ShellSend::holdScreen(200, 'call_hold');

        self::assertGreaterThanOrEqual(0.19, microtime(true) - $started);
    }
}
