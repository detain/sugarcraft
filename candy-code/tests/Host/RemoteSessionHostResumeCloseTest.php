<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use SugarCraft\Async\Backoff;
use SugarCraft\Code\Host\RemoteSessionHost;
use function React\Promise\resolve;

/**
 * CC-1 (P4-12-cc1-g-fix): every socket a resume opens is closed by the time the
 * attempt that opened it is over. A refused server.hello used to leave its
 * socket attached while the ladder redialed, and gone() closed only the newest
 * socket, so a server that refused every attempt kept them all.
 */
final class RemoteSessionHostResumeCloseTest extends TestCase
{
    /**
     * A reopen seam whose server answers each `server.hello` on the socket it
     * dials: the first $refuseCount hellos get a JSON-RPC error, later ones a
     * successful resume. Every dialed socket is appended to $opened. Each dial
     * first records into $dials how many earlier sockets were still readable or
     * writable, and the host's close reason at that moment.
     *
     * @param list<CompositeStream> $opened
     * @param list<array{attached: int, reason: ?string}> $dials
     */
    private function fakeServer(array &$opened, int $refuseCount, array &$dials, ?RemoteSessionHost &$host): \Closure
    {
        return static function () use (&$opened, $refuseCount, &$dials, &$host): PromiseInterface {
            $attached = 0;
            foreach ($opened as $earlier) {
                $attached += $earlier->isReadable() || $earlier->isWritable() ? 1 : 0;
            }
            $dials[] = ['attached' => $attached, 'reason' => $host?->closeReason()];

            $in = new ThroughStream();
            $out = new ThroughStream();
            $stream = new CompositeStream($in, $out);
            $opened[] = $stream;
            $answered = \count($opened);
            $out->on('data', static function (string $bytes) use ($in, $answered, $refuseCount): void {
                $frame = new Frame();
                $frame->addBuffer($bytes);
                $request = \json_decode($frame->getPayload(), true);
                $reply = $answered <= $refuseCount
                    ? ['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32000, 'message' => 'server refused']]
                    : ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['resumed' => ['session-1' => ['fromSeq' => 0, 'throughSeq' => 0]]]];
                Loop::futureTick(static function () use ($in, $reply, $answered, $refuseCount): void {
                    $in->emit('data', [(new Frame((string) \json_encode($reply), true, Frame::OP_TEXT))->getContents()]);
                    if ($answered > $refuseCount) {
                        Loop::stop();
                    }
                });
            });

            return resolve([$stream, '']);
        };
    }

    /**
     * Every redial found the earlier sockets already closed.
     *
     * @param list<array{attached: int, reason: ?string}> $dials
     */
    private function assertNothingAttachedAtDial(array $dials): void
    {
        foreach ($dials as $attempt => $dial) {
            self::assertSame(0, $dial['attached'], 'attempt ' . ($attempt + 1) . ' dialed while an earlier socket was still attached');
        }
    }

    /**
     * Attach a host to a live session, then drop its link and run the loop
     * until the ladder settles or the guard fires. A $malformed drop sends a
     * frame the host cannot read while the link is still open, so the host
     * drops it from inside a live socket rather than after a close.
     *
     * @param list<CompositeStream> $opened
     * @param list<array{attached: int, reason: ?string}> $dials
     * @param ?CompositeStream $initial receives the initial link, so a test can inspect the socket the drop left behind
     * @return array{0: RemoteSessionHost, 1: list<string>} the host and the close verdicts it delivered
     */
    private function dropAndRedial(array &$opened, int $refuseCount, array &$dials, bool $malformed = false, ?CompositeStream &$initial = null): array
    {
        $link = new ThroughStream();
        $initial = new CompositeStream($link, new ThroughStream());
        $host = null;
        $host = RemoteSessionHost::overStream(
            $initial,
            reopen: $this->fakeServer($opened, $refuseCount, $dials, $host),
            after: static function (float $seconds, \Closure $run): void {
                Loop::futureTick($run);
            },
            redialBackoff: Backoff::new(1.0, 2.0, 2)->withAttempts(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS),
        );
        foreach (['sessionId' => 'session-1', 'hello' => ['protocol' => 1]] as $property => $value) {
            (new \ReflectionProperty($host, $property))->setValue($host, $value);
        }
        $verdicts = [];
        $host->onClose(static function (string $reason) use (&$verdicts): void {
            $verdicts[] = $reason;
            Loop::stop();
        });

        $guard = Loop::addTimer(10.0, static fn () => Loop::stop());
        if ($malformed) {
            // An unmasked frame with a reserved opcode: the host drops the link while it is still open.
            $link->emit('data', [(new Frame('', true, 0x3))->getContents()]);
        } else {
            $link->close();
        }
        Loop::run();
        Loop::cancelTimer($guard);

        return [$host, $verdicts];
    }

    public function testARefusedServerHelloClosesEverySocketItOpened(): void
    {
        $opened = [];
        $dials = [];
        [, $verdicts] = $this->dropAndRedial($opened, PHP_INT_MAX, $dials);

        self::assertCount(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS, $opened, 'one socket per attempt');
        $this->assertNothingAttachedAtDial($dials);
        foreach (\array_slice($dials, 1) as $attempt => $dial) {
            self::assertSame('server refused', $dial['reason'], 'attempt ' . ($attempt + 2) . ' redialed into a pause with no reason');
        }
        foreach ($opened as $attempt => $stream) {
            self::assertFalse($stream->isReadable(), 'attempt ' . ($attempt + 1) . ' left its socket readable');
            self::assertFalse($stream->isWritable(), 'attempt ' . ($attempt + 1) . ' left its socket writable');
        }
        self::assertCount(1, $verdicts, 'one verdict once the budget is spent');
        self::assertStringContainsString('gave up', $verdicts[0]);
    }

    public function testASuccessfulReconnectLeavesOnlyTheResumedSocketAttached(): void
    {
        $opened = [];
        $dials = [];
        [$host, $verdicts] = $this->dropAndRedial($opened, 2, $dials);

        self::assertSame([], $verdicts, 'the third attempt resumes, so no verdict is delivered');
        self::assertTrue($host->isOpen(), 'the host is attached to the resumed socket');
        self::assertCount(3, $opened);
        $this->assertNothingAttachedAtDial($dials);
        self::assertFalse($opened[0]->isReadable(), 'the first refused socket is closed');
        self::assertFalse($opened[1]->isReadable(), 'the second refused socket is closed');
        self::assertTrue($opened[2]->isReadable(), 'the resumed socket stays attached');
    }

    /**
     * The link the host was dropped from is still open when the ladder starts,
     * so the resume that follows must close it, not just the sockets it dialed.
     */
    public function testAResumeClosesTheOpenLinkADroppedHostLeftBehind(): void
    {
        $opened = [];
        $dials = [];
        $initial = null;
        [$host, $verdicts] = $this->dropAndRedial($opened, 1, $dials, true, $initial);

        self::assertSame([], $verdicts, 'the second attempt resumes, so no verdict is delivered');
        self::assertTrue($host->isOpen(), 'the host is attached to the resumed socket');
        self::assertCount(2, $opened);
        $this->assertNothingAttachedAtDial($dials);
        self::assertFalse($initial->isReadable(), 'the dropped link is still readable: the resume did not close it');
        self::assertFalse($initial->isWritable(), 'the dropped link is still writable: the resume did not close it');
        self::assertFalse($opened[0]->isReadable(), 'the refused socket is closed');
        self::assertTrue($opened[1]->isReadable(), 'the resumed socket stays attached');
    }
}
