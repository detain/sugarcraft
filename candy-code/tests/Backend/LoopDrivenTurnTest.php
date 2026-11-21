<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Backend\Support\SettledPromise;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Backend\ChildRequestCancelled;
use SugarCraft\Code\Backend\LoopDrivenTurn;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tests\Backend\Support\ReadStreamCountingLoop;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

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
            static fn () => SettledPromise::askAnswer($channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk())),
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
            static fn () => SettledPromise::callAnswer($channel->callClient('tc_4', 'client__probe', ['q' => 'x'], 30), 'tc_4'),
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
            static fn () => SettledPromise::askAnswer($channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk())),
            $channel,
            $loop,
        );

        self::assertTrue($resolution->cancelled, 'a parent that hangs up is never a grant');
        self::assertFalse($resolution->permits());
    }

    /**
     * AgentWorkerPool::idle() suspends with no value and expects its driver to
     * resume it later. A driver that resumes at once never returns to the loop,
     * so the loop's probes and reads never run and the turn spins. This turn
     * suspends bare until a loop timer has fired three times. A driver that
     * spins never lets that timer run, so the resume cap turns the spin into a
     * failure instead of a hang.
     */
    public function testABareSuspensionYieldsALoopTickBeforeTheTurnResumes(): void
    {
        [, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $ticks = 0;
        $loop->addPeriodicTimer(0.001, function () use (&$ticks): void {
            $ticks++;
        });

        $resumes = 0;
        $value = LoopDrivenTurn::run(
            static function () use (&$ticks, &$resumes): string {
                while ($ticks < 3) {
                    if (++$resumes > 1000) {
                        throw new \RuntimeException('busy spin: the turn resumed 1000 times without the loop turning');
                    }
                    \Fiber::suspend();
                }

                return 'yielded';
            },
            $this->channel($child),
            $loop,
        );

        self::assertSame('yielded', $value);
        self::assertGreaterThanOrEqual(3, $ticks, 'the loop must turn between the bare suspensions');
        self::assertLessThanOrEqual(1000, $resumes);
    }

    /**
     * P4-12 CC-1 e0 review: a parent that hangs up leaves the turn socket
     * readable (EOF) for good. The ask settles unanswered, but the socket stayed
     * on the loop, so while the turn went on to its bare suspensions the loop
     * read the EOF again on every turn and never slept. The socket must leave
     * the loop on the first EOF, so the EOF is read exactly once.
     */
    public function testAParentThatHangsUpTakesTheSocketOffTheLoopAtTheFirstEof(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new ReadStreamCountingLoop();
        $channel = $this->channel($child);
        $call = new ToolCall('call_5', 'Edit', []);

        $loop->addTimer(0.01, function () use ($parent): void {
            self::readFrame($parent);
            \fclose($parent);
        });

        $ticks = 0;
        $loop->addPeriodicTimer(0.001, function () use (&$ticks): void {
            $ticks++;
        });

        $value = LoopDrivenTurn::run(
            static function () use ($channel, $call, &$ticks): string {
                $resolution = SettledPromise::askAnswer($channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk()));

                // The turn keeps running after the EOF, with no question
                // pending: bare suspensions until the loop has turned enough.
                $resumes = 0;
                while ($ticks < 20) {
                    if (++$resumes > 1000) {
                        throw new \RuntimeException('busy spin: the turn resumed 1000 times without the loop turning');
                    }
                    \Fiber::suspend();
                }

                return $resolution->cancelled ? 'unanswered' : 'answered';
            },
            $channel,
            $loop,
        );

        self::assertSame('unanswered', $value, 'a parent that hangs up is never a grant');
        self::assertSame(
            1,
            $loop->readDispatches,
            'the socket stayed on the loop after the EOF, so the loop woke on it on every turn',
        );
    }

    /**
     * On the turn's own fiber an ask returns a PENDING promise, and the loop
     * resolves it when the `ask_reply` arrives: nothing blocks on the socket.
     */
    public function testAnAskOnTheTurnsOwnFiberReturnsAPendingPromiseTheLoopResolves(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);
        $call = new ToolCall('call_20', 'Edit', ['path' => 'p.txt']);
        $askId = PendingAsk::askId('call_20', 'Edit', ['path' => 'p.txt']);

        $loop->addTimer(0.01, function () use ($parent, $askId): void {
            self::readFrame($parent);
            self::send($parent, ['kind' => ChildChannel::ASK_REPLY, 'askId' => $askId, 'reply' => 'once']);
        });

        $pendingAtCall = null;
        $value = LoopDrivenTurn::run(
            static function () use ($channel, $call, &$pendingAtCall): string {
                $promise = $channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk());
                $settled = false;
                $promise->then(
                    static function () use (&$settled): void {
                        $settled = true;
                    },
                    static function () use (&$settled): void {
                        $settled = true;
                    },
                );
                $pendingAtCall = !$settled;

                $resolution = ChildChannel::awaitTurn(
                    $promise,
                    static fn (ChildRequestCancelled $cancelled): PermissionResolved => PermissionResolved::cancelled($cancelled->requestId, $cancelled->getMessage()),
                );

                return $resolution->permits() ? 'answered' : 'unanswered';
            },
            $channel,
            $loop,
        );

        self::assertTrue($pendingAtCall, 'the ask must return before the answer arrives, not block for it');
        self::assertSame('answered', $value, 'the resolved answer reaches the caller');
    }

    /**
     * The client-call twin of the ask test above: on the turn's own fiber a
     * client call returns a PENDING promise, and the loop resolves it when the
     * `client_result` frame arrives. A blocking callClient would never let the
     * parent timer run, and the pending check would read the call as settled.
     */
    public function testAClientCallOnTheTurnsOwnFiberReturnsAPendingPromiseTheLoopResolves(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);

        $loop->addTimer(0.01, function () use ($parent): void {
            $request = self::readFrame($parent);
            self::send($parent, [
                'kind' => ChildChannel::CLIENT_RESULT,
                'callId' => $request['callId'] ?? '',
                'content' => 'the client answered',
                'isError' => false,
            ]);
        });

        $pendingAtCall = null;
        $result = LoopDrivenTurn::run(
            static function () use ($channel, &$pendingAtCall): ToolResult {
                $promise = $channel->callClient('tc_6', 'client__probe', ['q' => 'x'], 30);
                $settled = false;
                $promise->then(
                    static function () use (&$settled): void {
                        $settled = true;
                    },
                    static function () use (&$settled): void {
                        $settled = true;
                    },
                );
                $pendingAtCall = !$settled;

                return SettledPromise::callAnswer($promise, 'tc_6');
            },
            $channel,
            $loop,
        );

        self::assertTrue($pendingAtCall, 'the client call must return before the answer arrives, not block for it');
        self::assertSame('the client answered', $result->content());
        self::assertFalse($result->isError());
    }

    /**
     * A question the parent never answers (it hangs up while the turn waits on
     * it) reaches the turn through the production approver's cancellation
     * mapping, and settles unanswered, never granted. The rejection's request id
     * is asserted where the promise itself is tested (ChildChannelTest).
     */
    public function testACancelledAskSettlesUnansweredThroughTheApproverWhenTheParentHangsUp(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);
        $call = new ToolCall('call_21', 'Edit', []);

        $loop->addTimer(0.01, function () use ($parent): void {
            self::readFrame($parent);
            \fclose($parent);
        });

        $verdict = LoopDrivenTurn::run(
            static fn (): ApprovalVerdict => $channel->approver()($call, ForkChannelEofIsUnansweredTest::gateAsk()),
            $channel,
            $loop,
        );

        self::assertNull($verdict->reply, 'a hang-up is never a grant');
        self::assertFalse($verdict->permits());
        self::assertSame(ChildChannel::PARENT_GONE, $verdict->feedback);
    }

    /**
     * The client-call twin of the cancelled ask above: a parent that hangs up
     * while the turn waits on a client call reaches the turn through the
     * production client caller's mapping, as the turn's error result.
     */
    public function testACancelledClientCallSettlesAsAnErrorResultThroughTheClientCallerWhenTheParentHangsUp(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);

        $loop->addTimer(0.01, function () use ($parent): void {
            self::readFrame($parent);
            \fclose($parent);
        });

        $result = LoopDrivenTurn::run(
            static fn (): ToolResult => $channel->clientCaller()('tc_7', 'client__probe', ['q' => 'x'], 30),
            $channel,
            $loop,
        );

        self::assertTrue($result->isError(), 'a hang-up is never an answer');
        self::assertSame('the turn ended before the client answered', $result->content());
    }

    /**
     * An ask raised inside a NESTED fiber of the turn keeps the blocking socket
     * wait (the named exception in the ChildChannel docblock): its promise comes
     * back already settled, and the answer still reaches the nested caller.
     */
    public function testANestedFiberAskKeepsTheBlockingWaitAndStillReturnsItsAnswer(): void
    {
        [$parent, $child] = $this->pair();
        $loop = new StreamSelectLoop();
        $channel = $this->channel($child);
        $call = new ToolCall('call_22', 'Edit', []);
        $askId = PendingAsk::askId('call_22', 'Edit', []);

        // The answer is already in the socket: the blocking wait reads it.
        self::send($parent, ['kind' => ChildChannel::ASK_REPLY, 'askId' => $askId, 'reply' => 'once']);

        $nestedOutcome = LoopDrivenTurn::run(
            static function () use ($channel, $call): array {
                $nested = new \Fiber(static function () use ($channel, $call): array {
                    $promise = $channel->ask($call, ForkChannelEofIsUnansweredTest::gateAsk());
                    $settled = false;
                    $promise->then(
                        static function () use (&$settled): void {
                            $settled = true;
                        },
                        static function () use (&$settled): void {
                            $settled = true;
                        },
                    );

                    return [$settled, SettledPromise::askAnswer($promise)];
                });
                $nested->start();

                return $nested->getReturn();
            },
            $channel,
            $loop,
        );

        [$settledAtReturn, $resolution] = $nestedOutcome;
        self::assertTrue($settledAtReturn, 'a nested-fiber ask settles before it returns (the blocking wait)');
        self::assertTrue($resolution->permits());
        self::assertSame(PermissionReply::Once, $resolution->reply);
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
