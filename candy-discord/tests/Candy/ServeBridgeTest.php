<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Candy;

use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Candy\ServeError;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeServe;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/**
 * Wire pins for the serve client the Discord channel dials, exercised against
 * the loopback FakeServe fixture: hello admission, both auth doors, the two
 * subscribe result shapes, media params, drop/reconnect re-follow, and the
 * call-before-connect refusal. No model path, no wall-clock budget beyond the
 * loop settle caps (E646 clean).
 */
final class ServeBridgeTest extends TestCase
{
    private LoopInterface $loop;

    private FakeServe $serve;

    private ServeBridge $bridge;

    private string $url = '';

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->serve = new FakeServe($this->loop, 'tok-123456');
        $port = $this->serve->listen();
        $this->url = 'ws://127.0.0.1:' . $port . '/';
        $this->bridge = new ServeBridge($this->url, 'tok-123456', 'subprotocol', Log::silent(), $this->loop);
    }

    protected function tearDown(): void
    {
        $this->bridge->stop();
        $this->serve->stop();
    }

    public function testTheHelloAdmitsTheBridge(): void
    {
        self::bridgeConnected($this->bridge);

        self::assertTrue($this->bridge->connected());
        self::assertSame(1, $this->serve->helloSeen);
        self::assertSame(1, $this->serve->upgradeSeen);
    }

    public function testTheSubprotocolDoorCarriesTheTokenAndEchoesTheProtocol(): void
    {
        self::bridgeConnected($this->bridge);

        self::assertTrue($this->serve->sawAuthSubprotocol, 'auth subprotocol door never rode the upgrade');
        self::assertSame('candy-code.v1', $this->serve->subprotocolEchoed);
    }

    public function testTheBearerDoorFallsBackToTheHeader(): void
    {
        $bearer = new ServeBridge($this->url, 'tok-123456', 'bearer', Log::silent(), $this->loop);
        self::bridgeConnected($bearer);

        self::assertTrue($this->serve->sawBearerHeader, 'bearer door never rode the header');
        $bearer->stop();
    }

    public function testSubscribeWithoutACursorResetsAndWithACursorReplays(): void
    {
        self::bridgeConnected($this->bridge);

        $reset = null;
        $this->bridge->follow('sess_fixture_1', null)->then(static function (array $result) use (&$reset): void {
            $reset = $result;
        });
        self::runFor($this->loop, 300);

        self::assertIsArray($reset, 'cold subscribe never resolved');
        self::assertTrue($reset['reset'] ?? false, 'cold subscribe must ride the RESET shape');
        self::assertSame(3, $reset['throughSeq'] ?? null);
        self::assertNull($this->serve->lastAfterSeq);

        $replay = null;
        $this->bridge->follow('sess_fixture_1', 6, static fn (): int => 6)->then(static function (array $result) use (&$replay): void {
            $replay = $result;
        });
        self::runFor($this->loop, 300);

        self::assertIsArray($replay, 'warm subscribe never resolved');
        self::assertArrayNotHasKey('reset', $replay);
        self::assertSame(7, $replay['fromSeq'] ?? null, 'REPLAY must start at afterSeq + 1');
        self::assertSame(6, $this->serve->lastAfterSeq);
    }

    public function testSendCapturesDeliveryIdempotencyAndMediaParams(): void
    {
        self::bridgeConnected($this->bridge);

        $this->bridge->send('sess_fixture_1', 'hello serve', 'steer');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->sendSeen);
        self::assertSame('hello serve', $this->serve->seenText);
        self::assertSame('steer', $this->serve->lastDelivery);
        self::assertIsString($this->serve->lastIdempotencyKey);
        self::assertNotSame('', $this->serve->lastIdempotencyKey, 'send must always carry a fresh idempotencyKey');
        self::assertFalse($this->serve->lastHasMedia);

        $this->bridge->send('sess_fixture_1', 'with file', 'queue', null, [['name' => 'a.png', 'mime' => 'image/png', 'data' => 'AA==']]);
        self::runFor($this->loop, 300);

        self::assertTrue($this->serve->lastHasMedia, 'images must ride the session.send params');
    }

    public function testAMediaRefusalRejectsWithItsKind(): void
    {
        self::bridgeConnected($this->bridge);
        $this->serve->refuseMediaOnce = true;

        $error = null;
        $this->bridge->send('sess_fixture_1', 'doomed file', 'queue', null, [['name' => 'a.png', 'mime' => 'image/png', 'data' => 'AA==']])
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
        self::runFor($this->loop, 300);

        self::assertInstanceOf(ServeError::class, $error);
        self::assertSame('image_mime_mismatch', $error->kind);
    }

    public function testCallsBeforeTheFirstHelloFailLoud(): void
    {
        $error = null;
        $this->bridge->call('session.create')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(\Throwable::class, $error);
        self::assertStringContainsString('discord-bundle:', $error->getMessage());
        self::assertStringContainsString('not connected', $error->getMessage());
    }

    public function testTheSocketDropReconnectsAndReFollowsFromTheCursor(): void
    {
        self::bridgeConnected($this->bridge);
        $this->bridge->follow('sess_fixture_1', 6, static fn (): int => 9);
        self::runFor($this->loop, 300);
        self::assertSame(6, $this->serve->lastAfterSeq);

        $this->serve->dropClients();
        for ($tick = 0; $tick < 30 && $this->serve->helloSeen < 2; $tick++) {
            self::runFor($this->loop, 150);
        }

        self::assertGreaterThanOrEqual(2, $this->serve->helloSeen, 'bridge never rode the reconnect ladder');
        self::runFor($this->loop, 300);
        self::assertSame(9, $this->serve->lastAfterSeq, 're-follow must re-read the live cursor after re-hello');
        self::assertTrue($this->bridge->connected());
    }

    public function testCreateCancelAndRespondPermissionRideTheirWires(): void
    {
        self::bridgeConnected($this->bridge);

        $created = null;
        $this->bridge->createSession('a channel session')->then(static function (array $r) use (&$created): void {
            $created = $r;
        });
        $this->bridge->cancel('sess_fixture_1');
        $this->bridge->respondPermission('sess_fixture_1', 'ask_9', 'once', 'boss said so');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->createSeen);
        self::assertSame('sess_fixture_1', $created['id'] ?? null);
        self::assertSame(1, $this->serve->cancelSeen);
        self::assertSame(1, $this->serve->respondSeen);
        self::assertSame('once', $this->serve->lastReply);
        self::assertSame('boss said so', $this->serve->lastNote);
    }

    /** Settle until the bridge reports its hello admitted (bounded, loopback only). */
    public function testAStrayCloseFrameFromTheServerSchedulesExactlyOneLadder(): void
    {
        // A protocol violation on the wire (here: an invalid opcode, which
        // the frame decoder converts into a close) must arm EXACTLY one
        // reconnect ladder and deliver EXACTLY one down notice. Before the
        // epoch-on-end fix the inline ending and the stream's own close
        // event each armed a ladder: attempts double-counted, the first
        // timer went untracked, and onDown fired twice.
        $lines = [];
        $downs = [];
        $bridge = new ServeBridge($this->url, 'tok-123456', 'subprotocol', new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }), $this->loop);
        $bridge->start(static function (string $reason) use (&$downs): void {
            $downs[] = $reason;
        })->then(null, static function (): void {
        });
        self::runFor($this->loop, 150);
        self::assertTrue($bridge->connected(), 'pre-flight: the bridge is up');
        $this->serve->sendGarbage();
        self::runFor($this->loop, 150);
        self::assertSame(1, \count(array_filter($lines, static fn (string $l): bool => str_contains($l, 'serve_redial'))), 'one ending, one ladder');
        self::assertSame(1, \count($downs), 'the operator hears about the drop exactly once');
        self::assertFalse($bridge->connected());
        $bridge->stop();
    }

    public function testARefusedHelloEndsTheBoundSocket(): void
    {
        // The rejection that follows a SUCCESSFUL dial carries a live bound
        // stream; the chain never routed it to the dial-rejection arm, so
        // the socket stranded open, no ladder armed, and the bridge fell
        // silent. The hello leg must reclaim the fd and re-dial.
        $this->serve->refuseHello = true;
        $lines = [];
        $bridge = new ServeBridge($this->url, 'tok-123456', 'subprotocol', new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }), $this->loop);
        $bridge->start()->then(null, static function (): void {
        });
        self::runFor($this->loop, 250);
        self::assertSame(1, $this->serve->helloSeen, 'the refusal was answered, so the socket WAS bound');
        self::assertSame(1, $this->serve->clientCloseSeen, 'the bridge must close a socket it will not keep');
        self::assertSame(1, \count(array_filter($lines, static fn (string $l): bool => str_contains($l, 'serve_redial'))), 'the refusal arms exactly one ladder');
        self::assertFalse($bridge->connected());
        $bridge->stop();
    }

    public function testStopDuringAnInFlightDialPreventsTheBind(): void
    {
        // The revival shape: dial accepted, handshake completing after the
        // operator stopped the bridge. Without the dial-arm guard the late
        // 101 would bind, hello and start the follow loop on a dead bridge.
        $this->serve->handshakeDelayMs = 200;
        $bridge = new ServeBridge($this->url, 'tok-123456', 'subprotocol', Log::silent(), $this->loop);
        $bridge->start()->then(null, static function (): void {
        });
        self::runFor($this->loop, 60);
        $bridge->stop();
        self::runFor($this->loop, 400);
        self::assertSame(0, $this->serve->helloSeen, 'a stopped bridge must never complete its handshake into a session');
        self::assertFalse($bridge->connected());
    }

    public function testUndecodableServePayloadsLeaveABoundedTrace(): void
    {
        // PHP-CD-04 serve leg: payloads that never decode (or decode to a
        // non-object) used to return silently — a one-frame desync against
        // our OWN daemon was invisible. First drop per window traces, the
        // rest count; the session survives either way.
        $lines = [];
        $bridge = new ServeBridge($this->url, 'tok-123456', 'subprotocol', new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }), $this->loop);
        self::bridgeConnected($bridge);

        $this->serve->pushRaw('not json {{');
        $this->serve->pushRaw('"a bare string"');
        $this->serve->pushRaw('42');
        self::runFor($this->loop, 150);
        $traced = \array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'serve_malformed_frame"') || str_contains($l, 'serve_malformed_frame\'')));
        self::assertCount(1, $traced, 'one bounded trace for three drops');
        self::assertStringContainsString('undecodable', $traced[0]);
        self::assertTrue($bridge->connected(), 'junk traffic is dropped, not fatal');
        $bridge->stop();
    }

    private static function bridgeConnected(ServeBridge $bridge): void
    {
        $GLOBALS['p2_loop'] = $bridge;
        // Helper lives beside runFor: connect through start() and pump.
        $started = false;
        $bridge->start()->then(static function () use (&$started): void {
            $started = true;
        });
        $loop = (new \ReflectionClass($bridge))->getProperty('loop');
        $loop->setAccessible(true);
        /** @var LoopInterface $loopInstance */
        $loopInstance = $loop->getValue($bridge);
        for ($tick = 0; $tick < 20 && !$started; $tick++) {
            self::runFor($loopInstance, 100);
        }
        unset($GLOBALS['p2_loop']);
        if (!$started) {
            self::fail('serve handshake never admitted');
        }
    }

    private static function runFor(LoopInterface $loop, int $ms): void
    {
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }
}
