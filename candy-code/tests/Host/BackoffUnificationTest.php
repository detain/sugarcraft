<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;
use SugarCraft\Async\Backoff;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Providers\TransientFailure;

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

    public function testRedialDelaysDoubleFromQuarterSecondAndCapAtFour(): void
    {
        // The curve the app shipped: a 0.25 s first wait, then the redial
        // ladder's rungs 0.5, 1, 2, 4 and 4 again, one rung per failed attempt.
        $delays = [];
        $host = RemoteSessionHost::overStream(
            new ThroughStream(),
            reopen: static fn() => null,
            after: static function (float $seconds, \Closure $run) use (&$delays): void {
                $delays[] = $seconds;
            },
        );
        (new \ReflectionMethod($host, 'scheduleLadder'))->invoke($host);

        $ladder = (new \ReflectionMethod(RemoteSessionHost::class, 'redialLadder'))->invoke(null);
        self::assertInstanceOf(Backoff::class, $ladder);
        foreach (range(1, 6) as $failed) {
            $delays[] = $ladder->secondsForAttempt($failed);
        }

        self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0, 4.0, 4.0], $delays);
    }

    public function testTheRedialLadderSpendsTheReconnectAttemptBudget(): void
    {
        $ladder = (new \ReflectionMethod(RemoteSessionHost::class, 'redialLadder'))->invoke(null);
        self::assertTrue($ladder->allows(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS - 1), 'a retry may follow the last allowed failure');
        self::assertFalse($ladder->allows(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS), 'the budget-th failure is final');
    }
}
