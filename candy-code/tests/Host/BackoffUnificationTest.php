<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Stream\ThroughStream;
use SugarCraft\Async\Backoff;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Providers\TransientFailure;
use SugarCraft\Code\Tests\Host\Support\RecordedTimerLoop;
use function React\Promise\reject;

/**
 * S-P06 (G-11): one backoff law, sugar-async Backoff, for the provider ladder
 * and the redial ladder. These pins hold the curves the app shipped before the
 * unification (disclosed move: the provider jitter is now millisecond-granular).
 */
final class BackoffUnificationTest extends TestCase
{
    public function testProviderLadderIsTheBackoffCeilingScheduleInMicroseconds(): void
    {
        $config = ['providerRetryAttempts' => 5, 'providerRetryBaseBackoffMs' => 500];
        $ladder = Backoff::new(500.0, 2.0);
        $seen = [];
        for ($n = 1; $n <= 4; $n++) {
            $seen[] = TransientFailure::backoffMicroseconds($n, $config);
            self::assertSame($ladder->ceiling($n) * 1000, $seen[$n - 1]);
        }
        self::assertSame([500_000, 1_000_000, 2_000_000, 4_000_000], $seen);
        self::assertSame(0, TransientFailure::backoffMicroseconds(5, $config), 'the last attempt owes nothing');
    }

    public function testTheProductionLadderSchedulesTheCurveItShipped(): void
    {
        // No seam: the production default backoff runs, and every timer the
        // host asks the loop for is the delay the ladder actually schedules.
        $inner = Loop::get();
        $recorder = new RecordedTimerLoop($inner);
        Loop::set($recorder);
        try {
            $dials = 0;
            $closes = [];
            $host = RemoteSessionHost::overStream(
                new ThroughStream(),
                reopen: static function () use (&$dials): PromiseInterface {
                    $dials++;

                    return reject(new \RuntimeException('refused'));
                },
            );
            $host->onClose(static function (string $reason) use (&$closes, $inner): void {
                $closes[] = $reason;
                $inner->stop();
            });

            // A drop leaves the host reconnecting; the first wait and the ladder run from here.
            (new \ReflectionProperty($host, 'reconnecting'))->setValue($host, true);
            (new \ReflectionMethod($host, 'scheduleLadder'))->invoke($host);
            $guard = $inner->addTimer(5.0, static fn () => $inner->stop());
            $inner->run();
            $inner->cancelTimer($guard);
        } finally {
            Loop::set($inner);
        }

        // The curve the app shipped: a 0.25 s first wait, then one rung per
        // failed attempt, 0.5, 1, 2 and 4, with 4 held to the end of the budget.
        $expected = [RemoteSessionHost::RECONNECT_BASE_DELAY_SECONDS, 0.5, 1.0, 2.0];
        while (\count($expected) < RemoteSessionHost::RECONNECT_MAX_ATTEMPTS) {
            $expected[] = RemoteSessionHost::RECONNECT_MAX_DELAY_SECONDS;
        }
        self::assertSame($expected, $recorder->requested, 'the timers the ladder scheduled, in order');
        self::assertSame(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS, $dials, 'the default ladder dialed its whole budget');
        self::assertCount(1, $closes, 'one verdict once the budget is spent');
        self::assertStringContainsString('gave up', $closes[0]);
    }

    public function testTheRedialLadderSpendsTheReconnectAttemptBudget(): void
    {
        $ladder = (new \ReflectionMethod(RemoteSessionHost::class, 'redialLadder'))->invoke(null);
        self::assertTrue($ladder->allows(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS - 1), 'a retry may follow the last allowed failure');
        self::assertFalse($ladder->allows(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS), 'the budget-th failure is final');
    }
}
