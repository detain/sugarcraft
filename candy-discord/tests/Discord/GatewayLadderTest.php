<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Discord\Gateway;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeGateway;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/**
 * The identify → READY → resume session-seq ladder (§7.1) against a
 * loopback fake that speaks real gateway op codes. Every test owns its
 * loop; delays are the transport's real backoff (E646-clean — these are
 * not model-serving paths) kept under one second by small hello timers.
 */
final class GatewayLadderTest extends TestCase
{
    private const CONTENT = 1 << 11;
    private const GUILD_MESSAGES = 1 << 9;

    private LoopInterface $loop;
    private FakeGateway $fake;
    private Gateway $gateway;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->fake = new FakeGateway($this->loop);
    }

    protected function tearDown(): void
    {
        $this->gateway?->stop();
        $this->fake->stop();
    }

    public function testIdentifyCarriesThePrivilegedContentIntentByDefault(): void
    {
        $this->connect(true);
        self::assertSame(1, $this->fake->identifyCount);
        self::assertSame('tok', $this->fake->lastIdentify['token'] ?? null);
        $intents = (int) ($this->fake->lastIdentify['intents'] ?? 0);
        self::assertNotSame(0, $intents & self::CONTENT);
        self::assertNotSame(0, $intents & self::GUILD_MESSAGES);
        self::assertSame('app-9', $this->gateway->applicationId);
        self::assertTrue($this->gateway->messageContentEnabled());
        self::assertTrue($this->gateway->connected());
    }

    public function testADroppedConnectionResumesWithTheSessionSeqLadder(): void
    {
        $this->connect(true);
        $this->fake->dropConnection();
        $this->settle(2200);
        self::assertSame(1, $this->fake->resumeCount);
        self::assertSame('gsess', $this->fake->lastResume['session_id'] ?? null);
        self::assertSame(1, $this->fake->lastResume['seq'] ?? null);
        self::assertTrue($this->gateway->connected());
    }

    public function testAnUnresumableInvalidSessionIdentifiesFresh(): void
    {
        $this->connect(true);
        $this->fake->sendInvalidSession(false);
        $this->settle(2900);
        self::assertSame(2, $this->fake->identifyCount);
        self::assertSame(0, $this->fake->resumeCount);
    }

    public function testHeartbeatsBeatAtTheIntervalTheHelloAnnounces(): void
    {
        // The fake speaks the Discord v10 spelling (`heartbeat_interval`);
        // a gateway that only read the legacy `_ms` key would fall back to
        // 41250 ms and never beat inside the settle window.
        $this->fake->hbMs = 120;
        $this->connect(true);
        $this->settle(1500);
        self::assertGreaterThanOrEqual(2, $this->fake->beatCount);
    }

    public function testTheLegacyMsHelloSpellingStillSetsTheInterval(): void
    {
        // Fallback arm: an edge that mimics the old draft spelling must not
        // silently disable server-assigned heartbeats.
        $this->fake->hbMs = 120;
        $this->fake->legacyHelloKey = true;
        $this->connect(true);
        $this->settle(1500);
        self::assertGreaterThanOrEqual(2, $this->fake->beatCount);
    }

    public function testAFatalCloseStopsTheLadderInsteadOfReconnecting(): void
    {
        $this->connect(true);
        $this->fake->sendClose(4004);
        $this->settle(800);
        self::assertTrue($this->gateway->stopped());
        self::assertFalse($this->gateway->connected());
    }

    private function connect(bool $wantContent): void
    {
        $port = $this->fake->listen();
        $this->gateway = new Gateway('tok', Log::silent(), $this->loop, $this->fake->wsUrl(), $wantContent);
        $this->gateway->start();
        $this->settle(700);
    }

    private function settle(int $ms): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }
}
