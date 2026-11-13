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
        $delays = [];
        $host = RemoteSessionHost::overStream(
            new ThroughStream(),
            reopen: static fn() => null,
            after: static function (float $seconds, \Closure $run) use (&$delays): void {
                $delays[] = $seconds;
            },
        );
        $method = new \ReflectionMethod($host, 'scheduleAttempt');
        $attempt = new \ReflectionProperty($host, 'attempt');
        foreach (range(0, 6) as $n) {
            $attempt->setValue($host, $n);
            $method->invoke($host);
        }

        self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0, 4.0, 4.0], $delays);
    }
}
