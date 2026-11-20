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
 * attempt that opened it is over. A refused or timed-out server.hello used to
 * leave its socket attached while the ladder redialed, and gone() closed only
 * the newest socket, so a server that refused every attempt kept them all.
 */
final class RemoteSessionHostResumeCloseTest extends TestCase
{
    /**
     * A reopen seam whose server answers each `server.hello` on the socket it
     * dials: the first $refuseCount hellos get a JSON-RPC error, later ones a
     * successful resume. Every dialed socket is appended to $opened.
     *
     * @param list<CompositeStream> $opened
     */
    private function fakeServer(array &$opened, int $refuseCount): \Closure
    {
        return static function () use (&$opened, $refuseCount): PromiseInterface {
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
     * Attach a host to a live session, then drop its link and run the loop
     * until the ladder settles or the guard fires.
     *
     * @param list<CompositeStream> $opened
     * @return array{0: RemoteSessionHost, 1: list<string>} the host and the close verdicts it delivered
     */
    private function dropAndRedial(array &$opened, int $refuseCount): array
    {
        $link = new ThroughStream();
        $host = RemoteSessionHost::overStream(
            new CompositeStream($link, new ThroughStream()),
            reopen: $this->fakeServer($opened, $refuseCount),
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
        $link->close();
        Loop::run();
        Loop::cancelTimer($guard);

        return [$host, $verdicts];
    }

    public function testARefusedServerHelloClosesEverySocketItOpened(): void
    {
        $opened = [];
        [, $verdicts] = $this->dropAndRedial($opened, PHP_INT_MAX);

        self::assertCount(RemoteSessionHost::RECONNECT_MAX_ATTEMPTS, $opened, 'one socket per attempt');
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
        [$host, $verdicts] = $this->dropAndRedial($opened, 2);

        self::assertSame([], $verdicts, 'the third attempt resumes, so no verdict is delivered');
        self::assertTrue($host->isOpen(), 'the host is attached to the resumed socket');
        self::assertCount(3, $opened);
        self::assertFalse($opened[0]->isReadable(), 'the first refused socket is closed');
        self::assertFalse($opened[1]->isReadable(), 'the second refused socket is closed');
        self::assertTrue($opened[2]->isReadable(), 'the resumed socket stays attached');
    }
}
