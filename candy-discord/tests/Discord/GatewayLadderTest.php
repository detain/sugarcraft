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

    public function testAForeignResumeUrlIsRefusedAndTheLadderIdentifiesFresh(): void
    {
        // NET-CD-03 defense: the resume legs re-send the token to wherever
        // READY points. A foreign handback is refused LOUDLY and the resume
        // state is dropped — the next dial must be a fresh identify to the
        // configured endpoint, never a RESUME to a stranger.
        $this->fake->resumeGatewayUrl = 'wss://evil.example.com';
        $lines = [];
        $this->connect(true, new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        self::assertStringNotContainsString('evil.example.com', $this->gateway->resumeUrl);
        self::assertStringContainsString('discord_resume_url_refused', implode("\n", $lines));
        $this->fake->dropConnection();
        $this->settle(2200);
        self::assertSame(0, $this->fake->resumeCount, 'a refused handback must not mint a RESUME leg');
        self::assertSame(2, $this->fake->identifyCount, 'the ladder restarts with a fresh identify');
    }

    public function testAMirrorHandingBackItsOwnAddressIsTrusted(): void
    {
        // The loopback-proxy topology is supported: the mirror may resume
        // at itself. Identical host+port+scheme as the dialed URL is the
        // trusted shape, and the drop-resume leg proves it is walked.
        $this->connect(true);
        $this->fake->dropConnection();
        $this->settle(2200);
        self::assertSame(1, $this->fake->resumeCount);
        self::assertStringContainsString('127.0.0.1', $this->gateway->resumeUrl);
    }

    public function testUndecodableFramesAreTracedOncePerWindow(): void
    {
        // NET-CD-04/PHP-CD-04: a one-frame protocol desync used to vanish
        // without a line of evidence. The first drop per window traces with
        // its reason; the rest are counted, not echoed — and the connection
        // SURVIVES: junk is a protocol error, not a teardown.
        $lines = [];
        $this->connect(true, new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->fake->pushRaw('not-json{{');
        $this->fake->pushRaw('42');
        $this->fake->pushRaw('{"no_op":1}');
        $this->settle(400);
        $traced = \array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'discord_malformed_frame"') || str_contains($l, 'discord_malformed_frame\'')));
        self::assertCount(1, $traced, 'one bounded trace for three drops');
        self::assertStringContainsString('undecodable', $traced[0]);
        self::assertTrue($this->gateway->connected(), 'malformed traffic is dropped, not fatal');
        self::assertSame(1, $this->fake->connectionCount, 'no reconnect was armed by junk');
    }

    public function testAnOversizeFrameIsRefusedByTheLocalCeiling(): void
    {
        // SEC-CD-02 belt: ratchet's own buffer is the first wall, but the
        // insecureLoopbackProxy topology makes a misbehaving local edge a
        // supported shape — so the ceiling is stated and enforced here too.
        $lines = [];
        $this->connect(true, new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->fake->pushRaw(\str_repeat('x', Gateway::MAX_INBOUND_MESSAGE_BYTES + 1));
        $this->fake->pushRaw('not-json{{');
        $this->settle(600);
        $traced = \array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'discord_malformed_frame"') || str_contains($l, 'discord_malformed_frame\'')));
        self::assertCount(1, $traced, 'the ceiling trip opens the window; the junk drop rides the count');
        self::assertStringContainsString('oversize', $traced[0]);
        self::assertTrue($this->gateway->connected(), 'an oversized frame must not kill the session');
    }

    private function connect(bool $wantContent, ?Log $log = null): void
    {
        $port = $this->fake->listen();
        $this->gateway = new Gateway('tok', $log ?? Log::silent(), $this->loop, $this->fake->wsUrl(), $wantContent);
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
