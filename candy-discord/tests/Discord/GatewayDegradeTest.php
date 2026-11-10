<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Discord\Gateway;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeGateway;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

/**
 * Both roads to mention-only degraded mode (§7.1): declaring the
 * privileged intent off, and having the gateway refuse it with a
 * 4013/4014 disallowed-intents close — which must retry exactly once
 * without the bit and announce the degrade.
 */
final class GatewayDegradeTest extends TestCase
{
    private const CONTENT = 1 << 11;

    public function testADeclaredFalseIntentNeverAsksThePrivilegedBit(): void
    {
        $loop = new StreamSelectLoop();
        $fake = new FakeGateway($loop);
        $port = $fake->listen();
        $gateway = new Gateway('tok', Log::silent(), $loop, 'ws://127.0.0.1:' . $port, false);
        $gateway->start();
        $this->runFor($loop, 700);
        $intents = (int) ($fake->lastIdentify['intents'] ?? 0);
        self::assertSame(0, $intents & self::CONTENT);
        self::assertFalse($gateway->messageContentEnabled());
        $gateway->stop();
        $fake->stop();
    }

    public function testADisallowedCloseRetriesOnceWithoutTheBitAndAnnounces(): void
    {
        $loop = new StreamSelectLoop();
        $lines = [];
        $fake = new FakeGateway($loop);
        $fake->closeAfterIdentify = 4013;
        $port = $fake->listen();
        $gateway = new Gateway('tok', new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }), $loop, 'ws://127.0.0.1:' . $port, true);
        $gateway->start();
        $this->runFor($loop, 1500);
        self::assertSame(2, $fake->identifyCount);
        self::assertNotSame(0, ((int) $fake->identifyIntents[0]) & self::CONTENT);
        self::assertSame(0, ((int) ($fake->identifyIntents[1] ?? self::CONTENT)) & self::CONTENT);
        self::assertFalse($gateway->messageContentEnabled());
        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'discord_intents_degraded')));
        $gateway->stop();
        $fake->stop();
    }

    private function runFor(StreamSelectLoop $loop, int $ms): void
    {
        $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
    }
}
