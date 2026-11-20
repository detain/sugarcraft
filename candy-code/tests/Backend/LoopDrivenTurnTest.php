<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Backend\LoopDrivenTurn;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tools\ToolCall;

/**
 * P4-12 CC-1 step e0: the forked turn child runs on the loop. An approver ask or
 * a client call suspends the turn's fiber, the loop watches the turn socket,
 * and the parent's `ask_reply` / `client_result` frame resumes it.
 *
 * The "parent" is a timer on the loop that reads what the child wrote and
 * answers it. A turn that blocked on the socket instead of suspending would
 * never let that timer run, so every test here proves the loop drove the turn.
 */
final class LoopDrivenTurnTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        foreach ($this->open as $socket) {
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        }
        parent::tearDown();
    }

    public function testAnApproverAskSuspendsTheTurnAndTheLoopResumesItWithTheAnswer(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);
        $call = new ToolCall('call_9', 'Edit', ['path' => 'a.txt']);
        $askId = PendingAsk::askId('call_9', 'Edit', ['path' => 'a.txt']);

        $parentTicks = 0;
        $asked = [];
        $loop->addTimer(0.01, function () use ($parent, $askId, &$parentTicks, &$asked): void {
            $parentTicks++;
            $asked = self::readFrame($parent);
            self::send($parent, ['kind' => ChildChannel::ASK_REPLY, 'askId' => $askId, 'reply' => 'once']);
        });

        $resolution = LoopDrivenTurn::run(
            static fn () => $channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk()),
            $channel,
            $loop,
        );

        self::assertSame(1, $parentTicks, 'the loop must drive the suspended turn, so the parent timer runs');
        self::assertSame(ChildChannel::ASK, $asked['kind'] ?? null);
        self::assertSame($askId, $asked['askId'] ?? null);
        self::assertSame(PermissionReply::Once, $resolution->reply);
        self::assertTrue($resolution->permits());
    }

    public function testAClientCallSuspendsTheTurnAndTheLoopResumesItWithTheResult(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);

        $request = [];
        $loop->addTimer(0.01, function () use ($parent, &$request): void {
            $request = self::readFrame($parent);
            self::send($parent, [
                'kind' => ChildChannel::CLIENT_RESULT,
                'callId' => $request['callId'] ?? '',
                'content' => 'the client answered',
                'isError' => false,
            ]);
        });

        $result = LoopDrivenTurn::run(
            static fn () => $channel->callClient('tc_4', 'client__probe', ['q' => 'x'], 30),
            $channel,
            $loop,
        );

        self::assertSame(ChildChannel::CLIENT_CALL, $request['kind'] ?? null);
        self::assertSame('client__probe', $request['name'] ?? null);
        self::assertSame('the client answered', $result->content());
        self::assertFalse($result->isError());
    }

    public function testATurnThatNeverAsksReturnsItsValueWithoutRunningTheLoop(): void
    {
        [, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $ran = false;
        // A timer that would fire, and stop the loop, only if the loop ran. The
        // turn never suspends, so it must never run.
        $loop->addTimer(0.0, function () use (&$ran, $loop): void {
            $ran = true;
            $loop->stop();
        });

        $value = LoopDrivenTurn::run(static fn (): int => 42, $this->channel($child), $loop);

        self::assertSame(42, $value);
        self::assertFalse($ran, 'a turn that never asks must not run the loop');
    }

    public function testTheParentHangingUpWhileTheTurnIsSuspendedSettlesTheAskUnanswered(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);
        $call = new ToolCall('call_3', 'Edit', []);

        $loop->addTimer(0.01, function () use ($parent): void {
            self::readFrame($parent);
            \fclose($parent);
        });

        $resolution = LoopDrivenTurn::run(
            static fn () => $channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk()),
            $channel,
            $loop,
        );

        self::assertTrue($resolution->cancelled, 'a parent that hangs up is never a grant');
        self::assertFalse($resolution->permits());
    }

    private function channel($child): ChildChannel
    {
        return ChildChannel::new(
            $child,
            static function (array $frame) use ($child): void {
                self::send($child, $frame);
            },
            ForkChannelEofIsUnansweredTest::drain(),
            'default',
            null,
        );
    }

    /**
     * Read one whole frame the child wrote. Blocking is safe: the child writes
     * a frame before it suspends, so the bytes are already in the socket.
     *
     * @param resource $socket
     *
     * @return array<string, mixed>
     */
    private static function readFrame($socket): array
    {
        $header = \fread($socket, 4);
        self::assertIsString($header, 'fixture: no frame header from the child');
        $length = \unpack('N', $header)[1];
        $body = \fread($socket, $length);
        $frame = \unserialize((string) $body, ['allowed_classes' => false]);
        self::assertIsArray($frame, 'fixture: the child wrote a frame that does not decode');

        return $frame;
    }

    /**
     * @param resource             $socket
     * @param array<string, mixed> $frame
     */
    private static function send($socket, array $frame): void
    {
        $body = \serialize($frame);
        \fwrite($socket, \pack('N', \strlen($body)) . $body);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'fixture: no socketpair on this host');
        \array_push($this->open, ...$pair);

        return $pair;
    }
}
