<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise;
use SugarCraft\SshServer\CancellationException;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\DeadlineExceededException;
use SugarCraft\SshServer\Transport\PromiseAwait;

/**
 * Direct pins for the cooperative-cancellation entry guard added to
 * {@see PromiseAwait::settle()} by the single-settle rule: a context that is
 * already done when the await is entered must refuse the await and
 * raise its own error, never enter the loop.
 *
 * The never-settling promises below would otherwise block until the
 * (pre-existing) timeout ceiling, so each case passes a deliberately
 * tight 0.2 s cap: with the guard the context error lands instantly;
 * if the guard is removed the timeout error lands after 200 ms and the
 * type assertion goes red — discriminating and fast either way.
 */
final class PromiseAwaitTest extends TestCase
{
    /**
     * @return iterable<string, array{Context, class-string<\Throwable>}>
     */
    public static function doneContextPolarities(): iterable
    {
        $cancelled = Context::background()->withCancelable();
        $cancelled->cancel();
        yield 'cancelled context' => [$cancelled, CancellationException::class];
        yield 'expired deadline' => [
            Context::background()->withDeadline(new \DateTimeImmutable('-1 second')),
            DeadlineExceededException::class,
        ];
    }

    #[DataProvider('doneContextPolarities')]
    public function testADoneContextRefusesTheAwaitAndRaisesItsOwnError(Context $ctx, string $expected): void
    {
        $never = new Promise\Promise(static function (): void {
            // Deliberately never settles: only the entry guard can end this
            // await before the timeout ceiling fires.
        });

        $failure = null;
        try {
            PromiseAwait::settle($never, 0.2, $ctx);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'a done context must abort the await with an error');
        $this->assertInstanceOf($expected, $failure);
    }

    public function testTheCancellationReasonRidesThroughTheRefusal(): void
    {
        $ctx = Context::background()->withCancelable();
        $reason = new \RuntimeException('ssh client gone');
        $ctx->cancel($reason);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ssh client gone');

        PromiseAwait::settle(new Promise\Promise(static function (): void {}), 0.2, $ctx);
    }

    public function testACancelledAncestorRefusesTheAwaitThroughAValueHop(): void
    {
        // Unlock pin: the settle entry guard must see an
        // ancestor cancellation through the derived hop — otherwise a
        // context-DERIVING middleware makes the guard invisible
        // downstream and the await blocks on work whose caller is gone.
        $base = Context::background()->withCancelable();
        $hop = $base->withValue('auth.ki.responses', ['redacted']);
        $base->cancel();

        $never = new Promise\Promise(static function (): void {
        });

        $this->expectException(CancellationException::class);
        PromiseAwait::settle($never, 0.2, $hop);
    }

    public function testALiveContextStillAwaitsToCompletion(): void
    {
        $loop = \React\EventLoop\Loop::get();
        $value = null;
        $pending = new Promise\Promise(static function (callable $resolve) use ($loop): void {
            $loop->addTimer(0.001, static fn () => $resolve('settled-by-loop'));
        });

        // Cancelable but NOT cancelled — done() is false, the guard must
        // stand aside and the loop-driven work must complete as before.
        PromiseAwait::settle($pending, 5.0, Context::background()->withCancelable());

        $captured = null;
        $pending->then(static function (mixed $v) use (&$captured): void {
            $captured = $v;
        });
        $this->assertSame('settled-by-loop', $captured);
    }

    public function testSettleWithoutAContextKeepsTheLegacyShape(): void
    {
        $failure = null;
        try {
            PromiseAwait::settle(Promise\reject(new \RuntimeException('plain rejection')), 0.2);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure);
        $this->assertSame('plain rejection', $failure->getMessage());
    }

    public function testTheTimeoutCeilingRaisesTheLocalisedMessageWrappingTheTimerError(): void
    {
        // Adoption pin: the react/promise-timer breach surfaces as
        // the project's transport.async_timeout text with the original
        // TimeoutException preserved as previous.
        $never = new Promise\Promise(static function (): void {
        });

        $failure = null;
        try {
            PromiseAwait::settle($never, 0.05);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertStringContainsString('timed out after 0.05', $failure->getMessage());
        $this->assertInstanceOf(\React\Promise\Timer\TimeoutException::class, $failure->getPrevious());
    }

    /**
     * Start a timer chain that keeps the shared loop permanently busy, so it
     * can never go quiescent on its own — the shape a panel with a listening
     * socket or a repeating tick presents. The returned closure stops the
     * chain; callers MUST invoke it in a `finally`.
     *
     * @return callable(): void
     */
    private function keepLoopBusy(): callable
    {
        $loop = \React\EventLoop\Loop::get();
        $alive = true;
        $arm = static function () use ($loop, &$arm, &$alive): void {
            if (!$alive) {
                return;
            }
            $loop->addTimer(0.01, $arm);
        };
        $arm();

        return static function () use (&$alive): void {
            $alive = false;
        };
    }

    public function testAnAnsweredAwaitEndsItsSpinEvenWhenTheLoopNeverQuiesces(): void
    {
        // Audit K10. The awaited promise settles in a millisecond, but the
        // loop has a re-arming timer, so "run until quiescent" — what
        // settle() used to do — is "run forever": the answer arrives, and
        // unrelated work keeps executing inside somebody's auth hop.
        $stopPounding = $this->keepLoopBusy();
        $loop = \React\EventLoop\Loop::get();
        $pending = new Promise\Promise(static function (callable $resolve) use ($loop): void {
            $loop->addTimer(0.001, static fn () => $resolve(null));
        });

        $started = \microtime(true);
        try {
            PromiseAwait::settle($pending, 30.0);
        } finally {
            $stopPounding();
        }
        $elapsed = \microtime(true) - $started;

        $this->assertLessThan(
            2.0,
            $elapsed,
            'a settled await must hand control back instead of spinning on other registrations',
        );
    }

    public function testAnUnansweredAwaitHitsItsCeilingEvenWhenTheLoopNeverQuiesces(): void
    {
        // Audit K10, other polarity: the ceiling used to be a rejection with
        // no way out, because the spin it was supposed to end kept running
        // until the loop went quiet.
        $stopPounding = $this->keepLoopBusy();
        $never = new Promise\Promise(static function (): void {
        });

        $failure = null;
        $started = \microtime(true);
        try {
            PromiseAwait::settle($never, 0.2);
        } catch (\Throwable $e) {
            $failure = $e;
        } finally {
            $stopPounding();
        }
        $elapsed = \microtime(true) - $started;

        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertStringContainsString('timed out after 0.2', (string) $failure->getMessage());
        $this->assertLessThan(2.0, $elapsed, 'the documented ceiling must end the spin');
    }

    public function testTheCeilingGuardLeavesNothingArmedOnASharedLoop(): void
    {
        // The watchdog that guarantees the ceiling is this class' own timer
        // on a loop it does not own: if it survived the await, the next
        // owner to run the loop would inherit a 30-second wake-up.
        $loop = \React\EventLoop\Loop::get();
        $pending = new Promise\Promise(static function (callable $resolve) use ($loop): void {
            $loop->addTimer(0.001, static fn () => $resolve(null));
        });
        PromiseAwait::settle($pending, 30.0);

        $started = \microtime(true);
        $loop->run();
        $this->assertLessThan(
            1.0,
            \microtime(true) - $started,
            'a bare run() right after an await must find nothing left to wait for',
        );
    }

    public function testASpinEndedByAnotherOwnerIsRefusedInsteadOfFakingSuccess(): void
    {
        // The await ends because somebody else that shares the loop stopped
        // it. The promise has no answer, so control must come back with an
        // error: quietly returning would tell the caller its async auth
        // finished when it never did.
        $loop = \React\EventLoop\Loop::get();
        $never = new Promise\Promise(static function (): void {
        });
        $loop->addTimer(0.005, static function () use ($loop): void {
            $loop->stop();
        });

        $failure = null;
        $started = \microtime(true);
        try {
            PromiseAwait::settle($never, 5.0);
        } catch (\Throwable $e) {
            $failure = $e;
        }
        $elapsed = \microtime(true) - $started;

        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertStringContainsString('timed out after', (string) $failure->getMessage());
        $this->assertLessThan(
            1.0,
            $elapsed,
            'the five-second ceiling cannot have fired this fast: it is the foreign stop that ended the await',
        );
    }

    public function testTheLoopOwnershipLawsAreOnTheClass(): void
    {
        // Half-moved-truth guard (audit K10): the ceiling claim is only
        // honest once the spin ends on the decision, so the class doc must
        // carry both laws the fix introduced.
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Transport/PromiseAwait.php');

        $this->assertStringContainsString('Law 1: this call owns the loop', $source);
        $this->assertStringContainsString(
            'Law 2: therefore never await from inside a running loop',
            $source
        );
        $this->assertStringContainsString('$loop->cancelTimer($watchdog)', $source);
        $this->assertStringNotContainsString(
            'enforced by the loop instead of a busy spin',
            $source
        );

        // ... and the same truth on both doc surfaces, so the class cannot be honest
        // while the skim surface still advertises a drive-until-quiet wait.
        $readme = (string) \file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        $flattened = (string) \preg_replace('/\s+/', ' ', $readme);
        $this->assertStringContainsString(
            'Who owns the loop',
            $flattened,
            'The README must carry the loop-ownership warning next to the async example.'
        );
        $this->assertStringNotContainsString(
            'drives the promise with the pre-existing 30-second timeout ceiling',
            $flattened,
            'The README must not keep the claim that the await simply drives the promise.'
        );

        $admin = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/docs/admin/sugar-ssh-server.md'
        );
        $this->assertStringNotContainsString(
            'it is not a busy spin',
            (string) \preg_replace('/\s+/', ' ', $admin),
            'The admin guide must stop asserting that driving the loop until settlement is not a spin.'
        );
    }

    public function testASpinEndedByAnotherOwnerCancelsItsCeilingTimer(): void
    {
        // Audit 015 (PROC-05's residue class): an await abandoned by a
        // foreign stop() used to leave its ceiling timer armed on the
        // shared loop — the timer kept react/promise-timer's wrapper
        // alive, and when it finally fired it rejected that wrapper and
        // stopped WHATEVER spin happened to be running, mid-flight. The
        // ceiling is armed, decided, and cancelled here now, so the
        // no-decision finally can reach it too. This census only works
        // on the select loop (the uv loop has no such property); the
        // suite pins the shape on CI's default loop.
        $loop = \React\EventLoop\Loop::get();
        if (!$loop instanceof \React\EventLoop\StreamSelectLoop) {
            $this->markTestSkipped('timer census requires StreamSelectLoop.');
        }
        // event-loop 1.5+ keeps timers in a private Timer\Timers wrapper
        // (before that the property itself was the storage) — count the
        // wrapper's list, whatever its shape.
        $timers = new \ReflectionProperty(\React\EventLoop\StreamSelectLoop::class, 'timers');
        $list = new \ReflectionProperty(\React\EventLoop\Timer\Timers::class, 'timers');

        $armedCount = static function () use ($timers, $list, $loop): int {
            $storage = $timers->getValue($loop);
            if ($storage instanceof \Countable) {
                return \count($storage);
            }
            $inner = $list->getValue($storage);

            return \is_array($inner) ? \count($inner) : 0;
        };

        $before = $armedCount();
        // The synchronous decision path settles before any spin starts:
        // the frame must hand the ceiling back there too, not only in
        // the post-run finally.
        PromiseAwait::settle(Promise\resolve(null), 5.0);
        $this->assertSame(
            $before,
            $armedCount(),
            'a synchronously-decided await must leave nothing armed either',
        );

        $never = new Promise\Promise(static function (): void {
        });
        // A foreign owner stops the spin long before the five-second
        // ceiling: settle() must come back with an error (pinned by the
        // test above) AND with nothing of its own left armed.
        $loop->addTimer(0.005, static function () use ($loop): void {
            $loop->stop();
        });
        try {
            PromiseAwait::settle($never, 5.0);
            $this->fail('the abandoned await must refuse to fake success');
        } catch (\RuntimeException) {
            // Expected shape; the residue below is the point.
        }

        $this->assertSame(
            $before,
            $armedCount(),
            'an abandoned await must leave no armed ceiling (or watchdog) on the shared loop',
        );
    }
}
