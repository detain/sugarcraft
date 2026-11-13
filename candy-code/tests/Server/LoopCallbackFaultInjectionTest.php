<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * S-O04: a Loop-registered callback that throws must not unwind `Loop::run()`
 * (that would end the daemon for every client). The cause is logged by name
 * and the timer stays armed.
 */
final class LoopCallbackFaultInjectionTest extends TestCase
{
    public function testAThrowingPumpTickIsLoggedAndTheLoopKeepsServing(): void
    {
        $lines = [];
        $throw = false;
        $fixture = ProtocolFixture::new(
            log: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            clock: static function () use (&$throw): float {
                if ($throw) {
                    throw new \RuntimeException('clock exploded');
                }

                return \microtime(true);
            },
        );

        try {
            $fixture->dispatcher->start();
            $throw = true;
            $fixture->run(0.2);

            $pumpLines = \array_values(\array_filter($lines, static fn (string $l): bool => \str_starts_with($l, 'pump tick failed: RuntimeException: clock exploded')));
            self::assertGreaterThanOrEqual(2, \count($pumpLines), 'the timer survived the first throw and fired again');

            $throw = false;
            $before = \count($lines);
            $fixture->run(0.12);
            self::assertSame($before, \count($lines), 'once the fault clears the pump runs quietly');
        } finally {
            $fixture->tearDown();
        }
    }
}
