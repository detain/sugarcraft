<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * Fails the run, naming the test, when a test leaves timers/streams/signals/
 * future ticks armed on the shared `Loop::get()`.
 *
 * WHY NOT A tearDown ASSERT: candy-code has hundreds of test classes; a
 * PHPUnit event subscriber installed once from `tests/bootstrap.php` reaches
 * all of them without touching any. The cost is that a `Finished` subscriber
 * cannot turn the test itself red, so offenders are collected, reported on
 * stderr when execution ends, and the process exit code is forced non-zero
 * from a shutdown function.
 *
 * After recording an offender the shared loop is replaced with a fresh
 * StreamSelectLoop so one leak is attributed to ONE test instead of every
 * test that follows it (the bootstrap already pins StreamSelectLoop).
 *
 * Opt-out for local bisecting only: CANDY_CODE_ALLOW_LOOP_RESIDUE=1 reports
 * but does not change the exit code. There is no per-test exemption roster
 * yet; add one here (class::method => reason) if a justified leak appears.
 *
 * Mirrors sugar-pty/tests/Support/SharedLoopResidue.php.
 */
final class SharedLoopResidueGuard
{
    /** @var array<string, string> test id => census description */
    private static array $offenders = [];

    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;

        EventFacade::instance()->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                SharedLoopResidueGuard::check($event->test()->id());
            }
        });
        EventFacade::instance()->registerSubscriber(new class implements ExecutionFinishedSubscriber {
            public function notify(ExecutionFinished $event): void
            {
                SharedLoopResidueGuard::report();
            }
        });

        \register_shutdown_function(static function (): void {
            if (self::$offenders !== [] && \getenv('CANDY_CODE_ALLOW_LOOP_RESIDUE') !== '1') {
                exit(1);
            }
        });
    }

    /** @internal subscriber entry point */
    public static function check(string $testId): void
    {
        $census = SharedLoopResidue::census();
        if (\array_sum($census) === 0) {
            return;
        }
        self::$offenders[$testId] = SharedLoopResidue::describe();
        Loop::set(new StreamSelectLoop());
    }

    /** @internal subscriber entry point */
    public static function report(): void
    {
        if (self::$offenders === []) {
            return;
        }
        $lines = ["\nSharedLoopResidue: " . \count(self::$offenders) . " test(s) left the shared loop armed:\n"];
        foreach (self::$offenders as $id => $what) {
            $lines[] = "  - {$id}: {$what}\n";
        }
        \fwrite(\STDERR, \implode('', $lines));
    }

    /** @return array<string, string> */
    public static function offenders(): array
    {
        return self::$offenders;
    }
}
