<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixChild;
use SugarCraft\Pty\Tests\Support\RecordingLoop;

/**
 * P1-8b: Child::onExit() fires exactly once with the exit status, woken by
 * SIGCHLD through the loop's own signal API, with no periodic timer armed on
 * that path. The periodic probe survives only as the fallback for a loop or
 * host that cannot deliver SIGCHLD.
 *
 * Every loop here is the test's own (StreamSelectLoop or a RecordingLoop
 * around one), never the shared Loop::get(), and every run sits behind a
 * safety timer so a listener that is never woken fails instead of hanging.
 */
final class ChildOnExitTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private const SAFETY_SECONDS = 5.0;

    /** @var list<PosixChild> */
    private array $spawned = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $child) {
            if (!$child->exited()) {
                $child->kill(9);
            }
            $child->wait();
        }
        $this->spawned = [];
        if (\function_exists('pcntl_signal') && \defined('SIGCHLD')) {
            \pcntl_signal(\SIGCHLD, \SIG_DFL);
        }
    }

    public function testFiresOnceWithTheExitCodeOfAChildThatExitsOnItsOwn(): void
    {
        $loop = new StreamSelectLoop();
        $child = $this->spawnShell('sleep 0.1; exit 3');
        // A second child exits later, so a second SIGCHLD reaches the loop
        // after the first child's listener has fired.
        $later = $this->spawnShell('sleep 0.4; exit 0');

        $calls = [];
        $child->onExit($loop, static function (int $code) use (&$calls): void {
            $calls[] = $code;
        });
        $this->runUntilBothSettle($loop, $later);

        $this->assertSame([3], $calls, 'onExit must fire exactly once, with the exit code');
        $this->assertSame(3, $child->exitCode());
    }

    public function testFiresOnceWithTheSignalStatusOfAKilledChild(): void
    {
        // 128 + signo is read through the FFI waitpid probe; the
        // proc_get_status fallback reports -1 for a signal death.
        $this->requireFfiOnlyCapability();

        $loop = new StreamSelectLoop();
        $child = $this->spawnShell('exec sleep 30');

        $calls = [];
        $child->onExit($loop, static function (int $code) use (&$calls, $loop): void {
            $calls[] = $code;
            $loop->stop();
        });
        $loop->addTimer(0.05, static function () use ($child): void {
            Libc::kill($child->pid(), 9);
        });
        $safety = $loop->addTimer(self::SAFETY_SECONDS, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);

        $this->assertSame([128 + 9], $calls, 'a SIGKILLed child must report 128 + 9, once');
    }

    public function testArmsNoPeriodicTimerWhereSigchldIsDelivered(): void
    {
        $this->requireSigchld();

        $loop = new RecordingLoop();
        $child = $this->spawnShell('sleep 0.1; exit 2');

        $calls = [];
        $child->onExit($loop, static function (int $code) use (&$calls): void {
            $calls[] = $code;
        });

        $this->assertSame([], $loop->periodicTimers, 'the SIGCHLD path must arm no periodic timer');
        $this->assertSame([\SIGCHLD], $loop->signalsAdded, 'the listener must ride the loop\'s own SIGCHLD');

        $this->runBounded($loop, static function () use (&$calls): bool {
            return $calls !== [];
        });

        $this->assertSame([2], $calls);
        $this->assertSame([], $loop->periodicTimers);
        $this->assertSame([\SIGCHLD], $loop->signalsRemoved, 'the listener must be removed once it fired');
        $this->assertSame(\SIG_DFL, \pcntl_signal_get_handler(\SIGCHLD), 'the loop must hand SIGCHLD back once its last listener goes');
    }

    public function testAnAlreadyExitedChildFiresOnTheNextTickNotInsideTheCall(): void
    {
        $loop = new RecordingLoop();
        $child = $this->spawnShell('exit 7');
        $this->assertSame(7, $child->wait());

        $calls = [];
        $child->onExit($loop, static function (int $code) use (&$calls): void {
            $calls[] = $code;
        });

        $this->assertSame([], $calls, 'onExit must never call back synchronously');
        $this->assertSame([], $loop->signalsAdded, 'an exited child needs no listener');
        $this->assertSame([], $loop->periodicTimers, 'an exited child needs no poll');

        // Nothing but the tick is armed, so run() returns once it has run.
        $loop->run();

        $this->assertSame([7], $calls);
    }

    public function testDetachingBeforeTheExitMeansTheCallbackNeverRuns(): void
    {
        $this->requireSigchld();

        $loop = new RecordingLoop();
        $child = $this->spawnShell('sleep 0.1; exit 1');

        $calls = [];
        $detach = $child->onExit($loop, static function (int $code) use (&$calls): void {
            $calls[] = $code;
        });
        $detach();
        $detach();

        $this->assertSame([\SIGCHLD], $loop->signalsRemoved, 'detach must remove the listener, once');
        // The probe queued at arm time is all that is left; it must not fire.
        $loop->run();

        $this->assertSame(1, $child->wait());
        $this->assertSame([], $calls);
    }

    public function testFallsBackToAPollWhereTheLoopCannotDeliverSignals(): void
    {
        $loop = new RecordingLoop(refuseSignals: true);
        $child = $this->spawnShell('sleep 0.1; exit 4');

        $calls = [];
        $child->onExit($loop, static function (int $code) use (&$calls): void {
            $calls[] = $code;
        }, 0.01);

        $this->assertSame([0.01], $loop->periodicTimers, 'a loop without signals must get the fallback poll');

        $this->runBounded($loop, static function () use (&$calls): bool {
            return $calls !== [];
        });

        $this->assertSame([4], $calls);
    }

    public function testFallsBackToAPollWhenAForeignSigchldHandlerIsInstalled(): void
    {
        $this->requireSigchld();

        $foreign = static function (): void {
        };
        \pcntl_signal(\SIGCHLD, $foreign);

        $loop = new RecordingLoop();
        $child = $this->spawnShell('sleep 0.1; exit 6');

        $calls = [];
        $child->onExit($loop, static function (int $code) use (&$calls): void {
            $calls[] = $code;
        }, 0.01);

        $this->assertSame([], $loop->signalsAdded, 'a SIGCHLD handler the loop does not own must not be replaced');
        $this->assertSame([0.01], $loop->periodicTimers);

        $this->runBounded($loop, static function () use (&$calls): bool {
            return $calls !== [];
        });

        $this->assertSame([6], $calls);
        $this->assertSame($foreign, \pcntl_signal_get_handler(\SIGCHLD), 'the foreign handler must survive');
    }

    public function testASecondLoopTakesThePollSoBothLoopsHearTheirChildren(): void
    {
        $this->requireSigchld();

        // Two plain loops, as the review repro had them: a second loop that
        // armed its own SIGCHLD listener replaced the first's handler and put
        // SIG_DFL back on removal, so the first loop never fired.
        $first = new StreamSelectLoop();
        $second = new StreamSelectLoop();
        $slow = $this->spawnShell('sleep 0.5; exit 3');
        $fast = $this->spawnShell('sleep 0.1; exit 4');

        $slowCalls = [];
        $slow->onExit($first, static function (int $code) use (&$slowCalls): void {
            $slowCalls[] = $code;
        });
        $ownHandler = \pcntl_signal_get_handler(\SIGCHLD);

        $fastCalls = [];
        $fast->onExit($second, static function (int $code) use (&$fastCalls): void {
            $fastCalls[] = $code;
        }, 0.01);
        $this->assertSame($ownHandler, \pcntl_signal_get_handler(\SIGCHLD), 'a second loop must not replace the first loop\'s SIGCHLD handler');

        $this->runBounded($second, static function () use (&$fastCalls): bool {
            return $fastCalls !== [];
        });
        $this->assertSame([4], $fastCalls);
        $this->assertSame($ownHandler, \pcntl_signal_get_handler(\SIGCHLD), 'the second loop must leave the first loop\'s handler installed');

        $this->runBounded($first, static function () use (&$slowCalls): bool {
            return $slowCalls !== [];
        });
        $this->assertSame([3], $slowCalls, 'the first loop must still hear its child exit');
        $this->assertSame(\SIG_DFL, \pcntl_signal_get_handler(\SIGCHLD));
    }

    public function testASecondLoopTakesThePollWhileTheOwnerOfTheClaimHoldsListeners(): void
    {
        $this->requireSigchld();

        $first = new RecordingLoop();
        $second = new RecordingLoop();
        $slow = $this->spawnShell('sleep 0.4; exit 0');
        $fast = $this->spawnShell('sleep 0.1; exit 5');

        $detach = $slow->onExit($first, static fn (int $code) => null);
        $fastCalls = [];
        $fast->onExit($second, static function (int $code) use (&$fastCalls): void {
            $fastCalls[] = $code;
        }, 0.01);

        $this->assertSame([\SIGCHLD], $first->signalsAdded);
        $this->assertSame([], $second->signalsAdded, 'only the loop holding the claim may arm SIGCHLD');
        $this->assertSame([0.01], $second->periodicTimers);

        $this->runBounded($second, static function () use (&$fastCalls): bool {
            return $fastCalls !== [];
        });
        $this->assertSame([5], $fastCalls);

        // Once the first loop's last listener goes, the claim is free again.
        $detach();
        $later = $this->spawnShell('sleep 0.1; exit 6');
        $laterCalls = [];
        $later->onExit($second, static function (int $code) use (&$laterCalls): void {
            $laterCalls[] = $code;
        });
        $this->assertSame([\SIGCHLD], $second->signalsAdded, 'a released claim must let another loop arm SIGCHLD');
        $this->runBounded($second, static function () use (&$laterCalls): bool {
            return $laterCalls !== [];
        });
        $this->assertSame([6], $laterCalls);
    }

    public function testRejectsANonPositiveFallbackInterval(): void
    {
        $child = $this->spawnShell('exit 0');

        $this->expectException(\InvalidArgumentException::class);
        $child->onExit(new StreamSelectLoop(), static fn (int $code) => null, 0.0);
    }

    public function testWaitAsyncRidesOnExitWithoutAPeriodicTimer(): void
    {
        $this->requireSigchld();

        $loop = new RecordingLoop();
        $child = $this->spawnShell('sleep 0.1; exit 5');

        $exit = null;
        $child->waitAsync($loop)->then(static function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $this->assertSame([], $loop->periodicTimers, 'waitAsync must not arm the 15 ms poll where SIGCHLD is delivered');
        $this->runBounded($loop, static function () use (&$exit): bool {
            return $exit !== null;
        });

        $this->assertSame(5, $exit);
    }

    public function testCancellingWaitAsyncDetachesItsListener(): void
    {
        $this->requireSigchld();

        $loop = new RecordingLoop();
        $child = $this->spawnShell('sleep 0.1; exit 0');

        $promise = $child->waitAsync($loop);
        $rejected = null;
        $promise->then(null, static function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });
        $promise->cancel();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertSame([\SIGCHLD], $loop->signalsRemoved);
    }

    private function requirePosix(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is not executable on this host.');
        }
    }

    private function requireSigchld(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_signal_get_handler') || !\defined('SIGCHLD')) {
            $this->markTestSkipped('ext-pcntl is required for SIGCHLD delivery.');
        }
    }

    private function spawnShell(string $script): PosixChild
    {
        $this->requirePosix();
        $proc = \proc_open(
            ['/bin/sh', '-c', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $status = \proc_get_status($proc);
        $child = new PosixChild((int) $status['pid'], $proc);
        $this->spawned[] = $child;

        return $child;
    }

    /**
     * Run $loop until $done() holds, checked every 10 ms, behind the safety
     * cap; $done must read its state by reference. The check re-arms one-shot timers, so it never shows up among the
     * periodic timers a test asserts on.
     */
    private function runBounded(LoopInterface $loop, callable $done): void
    {
        $check = null;
        $arm = static function () use (&$arm, &$check, $loop, $done): void {
            $check = $loop->addTimer(0.01, static function () use ($arm, $loop, $done): void {
                if ($done()) {
                    $loop->stop();

                    return;
                }
                $arm();
            });
        };
        $arm();
        $safety = $loop->addTimer(self::SAFETY_SECONDS, static fn () => $loop->stop());
        $loop->run();
        if ($check !== null) {
            $loop->cancelTimer($check);
        }
        $loop->cancelTimer($safety);
    }

    private function runUntilBothSettle(LoopInterface $loop, PosixChild $later): void
    {
        $later->onExit($loop, static function () use ($loop): void {
            // Leave one more tick for a duplicate call to land, then stop.
            $loop->futureTick(static fn () => $loop->stop());
        });
        $safety = $loop->addTimer(self::SAFETY_SECONDS, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);
    }
}
