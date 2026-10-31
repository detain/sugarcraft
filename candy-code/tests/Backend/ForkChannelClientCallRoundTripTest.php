<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\PendingClientCall;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Host\ClientTools\ClientToolProxy;
use SugarCraft\Code\Host\ClientTools\ClientToolSpec;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Code\Tests\Backend\Support\ScaledClockLoop;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-TOOL-6.h: a call to a client-hosted tool raised inside a FORKED turn
 * reaches the serve parent through the same turn socket the permission asks
 * ride (roadmap 1.C's channel), and the client's answer comes back as the
 * tool result. The pins below walk the whole round trip in BOTH directions
 * plus the parent-side policies that hang off it: the per-call deadline, the
 * idle-ceiling pause, the buffered steer, EOF, and the no-sink answer.
 *
 * Unlike an ask there is no grant memo here: every `client__*` call
 * round-trips, so this file never reuses the ask family's
 * same-call-answered-second-time shape.
 */
final class ForkChannelClientCallRoundTripTest extends TestCase
{
    /** The client answers only this late (VIRTUAL seconds) in the scaled pins. */
    private const C6H_ANSWER_AFTER = 200.0;

    /** @var list<resource> */
    private array $c6hOpen = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the channel lives on completeAsync()' . "'s fork, which needs ext-pcntl.");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->c6hOpen as $socket) {
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
        }
        $this->c6hOpen = [];
        parent::tearDown();
    }

    public function testTheCallReachesTheSinkAndTheAnswerComesBackAsContent(): void
    {
        $seen = null;
        $backend = $this->c6hClientBackend(static function (PendingClientCall $pending, \Closure $answer) use (&$seen): void {
            $seen = $pending;
            $answer('page text', false);
        });

        $events = [];
        $state = InteractiveTurnHarness::settle(
            $backend->completeInteractive([Message::user('go')], null, null, static function (object $event) use (&$events): void {
                $events[] = $event;
            }),
            Loop::get(),
        );

        self::assertTrue($state['settled'], 'the turn hung: ' . (string) $state['error']);
        self::assertNull($state['error']);
        self::assertSame('done', $state['value']->content);

        self::assertInstanceOf(PendingClientCall::class, $seen);
        self::assertSame('client__browse', $seen->name);
        self::assertSame('call_1', $seen->toolCallId);
        self::assertSame(['url' => 'page'], $seen->arguments);
        self::assertSame(60, $seen->deadlineSeconds);

        $finished = \array_values(\array_filter($events, static fn (object $e): bool => $e instanceof ToolFinished));
        self::assertCount(1, $finished);
        self::assertSame('page text', $finished[0]->result->content());
        self::assertFalse($finished[0]->result->isError());

        // A client call is NOT a permission question: no ask ever surfaces.
        self::assertSame([], \array_filter($events, static fn (object $e): bool => $e instanceof PermissionAsked));
    }

    public function testTheDeadlineAnswersAnUnansweredCall(): void
    {
        $backend = $this->c6hClientBackend(
            static function (PendingClientCall $pending, \Closure $answer): void {
                // The client says nothing. The parent's deadline must.
            },
            1,
        );

        $finished = [];
        $state = InteractiveTurnHarness::settle(
            $backend->completeInteractive([Message::user('go')], null, null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event;
                }
            }),
            Loop::get(),
            15.0,
        );

        self::assertTrue($state['settled'], 'the turn hung: ' . (string) $state['error']);
        self::assertCount(1, $finished);
        self::assertSame('the client did not answer within 1 s', $finished[0]->result->content());
        self::assertTrue($finished[0]->result->isError(), 'a deadline answer is an error, never content');
    }

    public function testASteerThatOvertakesTheReplyIsBuffered(): void
    {
        $token = new CancellationToken();
        $steerId = null;
        $backend = $this->c6hClientBackend(static function (PendingClientCall $pending, \Closure $answer) use ($token, &$steerId): void {
            // A steer that arrives while the child is blocked on the client
            // must ride the channel, not be swallowed by the waiting read.
            $steerId = $token->steer('use the fixtures under tests/');
            Loop::get()->addTimer(0.4, static fn () => $answer('page text', false));
        });

        $finished = [];
        $state = InteractiveTurnHarness::settle(
            $backend->completeInteractive([Message::user('go')], null, $token, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event;
                }
            }),
            Loop::get(),
        );

        self::assertTrue($state['settled'], 'the turn hung: ' . (string) $state['error']);
        self::assertIsString($steerId, 'the fixture must actually have steered');
        self::assertSame([$steerId => 2], $token->acknowledgedSteers(), 'the steer survived the blocked wait and landed at the next step');
        self::assertCount(1, $finished);
        self::assertSame('page text', $finished[0]->result->content());
    }

    public function testTheParentHangingUpMidCallIsAnErrorResult(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'fixture: no socketpair on this host');
        [$parent, $child] = $pair;
        \array_push($this->c6hOpen, $parent, $child);

        $writes = 0;
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use ($parent, &$writes): void {
                $writes++;
                // The parent hangs up exactly where a real teardown would:
                // the call has gone out and no answer can ever come back.
                \fclose($parent);
            },
            ForkChannelEofIsUnansweredTest::drain(),
            'default',
        );

        $result = $channel->callClient('call_1', 'client__browse', ['url' => 'page'], 60);

        // EOF settles as an error, never as content.
        self::assertTrue($result->isError());
        self::assertSame('the turn ended before the client answered', $result->content());
        self::assertTrue($channel->isClosed());
        self::assertSame(1, $writes, 'a closed channel writes nothing more');
    }

    public function testTheIdleCeilingIsPausedWhileAClientCallIsOpen(): void
    {
        $state = $this->c6hRunScaledClient(
            static function (PendingClientCall $pending, \Closure $answer, ScaledClockLoop $loop): void {
                $loop->release();
                $loop->addTimer(ForkChannelClientCallRoundTripTest::C6H_ANSWER_AFTER, static fn () => $answer('late page', false));
            },
            600,
        );

        self::assertGreaterThan(120.0, $state['virtualSeconds'], 'the ceiling must actually have passed');
        self::assertFalse($state['realCeiling'], 'the turn hung instead of settling');
        self::assertTrue($state['settled'], 'the turn died anyway: ' . (string) $state['error']);
        self::assertNull($state['error']);
        self::assertSame(['late page'], $state['finished']);
    }

    public function testTheCeilingIsReArmedOnceTheCallSettles(): void
    {
        // After the answer the tool runs and the next provider call goes
        // silent for ~250 virtual seconds (0.5 s of wall time).
        $state = $this->c6hRunScaledClient(
            static function (PendingClientCall $pending, \Closure $answer, ScaledClockLoop $loop): void {
                $loop->release();
                $answer('page text', false);
            },
            600,
            new ScriptedProvider([
                new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'client__browse', ['url' => 'page'])]),
                static function (): CompleteResponse {
                    \usleep(500_000);

                    return new CompleteResponse(content: 'too late');
                },
            ]),
        );

        self::assertFalse($state['realCeiling'], 'the turn hung instead of settling');
        self::assertTrue($state['settled']);
        self::assertInstanceOf(\RuntimeException::class, $state['error'], 'a silent provider after the answer must still hit the ceiling');
        self::assertStringContainsString('without progress', $state['error']->getMessage());
    }

    public function testWithNoSinkTheCallIsAnsweredAtOnce(): void
    {
        $backend = EngineBackend::new($this->c6hClientProvider(), 'm')
            ->withTools([new ClientToolProxy(self::c6hClientSpec())])
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions));
        // No withClientCallSink: nothing in this process can reach a client.

        $finished = [];
        $startedAt = \microtime(true);
        $state = InteractiveTurnHarness::settle(
            $backend->completeInteractive([Message::user('go')], null, null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event;
                }
            }),
            Loop::get(),
        );
        $elapsed = \microtime(true) - $startedAt;

        self::assertTrue($state['settled'], 'the turn hung: ' . (string) $state['error']);
        self::assertCount(1, $finished);
        self::assertSame('no client is attached to serve client__browse', $finished[0]->result->content());
        self::assertTrue($finished[0]->result->isError());
        self::assertLessThan(5.0, $elapsed, 'the missing sink answers immediately, no waiting');
    }

    /**
     * The scaled-clock driver: the virtual clock starts when the call
     * reaches the sink (everything before it — fork, construction, the
     * provider call, the proxy execution — is host cost, not silence),
     * exactly as {@see ForkChannelIdleTimerSuspendedTest} starts its clock
     * when the question arrives.
     *
     * @param \Closure(PendingClientCall, \Closure(string, bool): bool, ScaledClockLoop): void $sink
     * @return array{settled: bool, value: mixed, error: ?\Throwable, virtualSeconds: float, realCeiling: bool, finished: list<string>}
     */
    private function c6hRunScaledClient(\Closure $sink, int $timeoutSeconds, ?ScriptedProvider $provider = null): array
    {
        $loop = ScaledClockLoop::heldUntilReleased();
        $previous = Loop::get();
        Loop::set($loop);
        $finished = [];

        try {
            $backend = EngineBackend::new($provider ?? $this->c6hClientProvider(), 'm')
                ->withTools([new ClientToolProxy(self::c6hClientSpec($timeoutSeconds))])
                ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
                ->withClientCallSink(static function (PendingClientCall $pending, \Closure $answer) use ($sink, $loop): void {
                    $sink($pending, $answer, $loop);
                });

            $promise = $backend->completeInteractive([Message::user('go')], null, null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event->result->content();
                }
            });
            // The guard is in VIRTUAL seconds here; the loop's own real
            // ceiling is the backstop.
            $state = InteractiveTurnHarness::settle($promise, $loop, 1.0e9);
        } finally {
            // The SUITE's pinned loop, not a fresh one (tests/bootstrap.php).
            Loop::set($previous);
        }

        return $state + [
            'virtualSeconds' => $loop->highWaterVirtualSeconds(),
            'realCeiling' => $loop->hitRealCeiling(),
            'finished' => $finished,
        ];
    }

    private static function c6hClientSpec(int $timeoutSeconds = 60): ClientToolSpec
    {
        return new ClientToolSpec(
            name: 'browse',
            description: 'opens a page in the user browser',
            inputSchema: ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]],
            timeoutSeconds: $timeoutSeconds,
        );
    }

    private function c6hClientProvider(): ScriptedProvider
    {
        return new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'client__browse', ['url' => 'page'])]),
            new CompleteResponse(content: 'done'),
        ]);
    }

    /** @param \Closure(PendingClientCall, \Closure(string, bool): bool): void $sink */
    private function c6hClientBackend(\Closure $sink, int $timeoutSeconds = 60): EngineBackend
    {
        return EngineBackend::new($this->c6hClientProvider(), 'm')
            ->withTools([new ClientToolProxy(self::c6hClientSpec($timeoutSeconds))])
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
            ->withClientCallSink($sink);
    }
}
