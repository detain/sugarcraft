<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Support\AgentCancelRequests;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tests\Backend\ForkChannelEofIsUnansweredTest;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\ActivitySink;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\StreamsActivity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-E1: the Agent View's hard stop of one delegated run —
 * `agent_cancel{agentId, callId}` — pinned at every hop: the token Chat
 * flips, the child's channel, the seam the turn child consults, and the
 * concurrent reap loop's SIGTERM → grace → SIGKILL escalation.
 */
final class AgentHardCancelTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        AgentCancelRequests::forget();
        ToolCancelRequests::forget();
        foreach ($this->open as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        parent::tearDown();
    }

    public function testTheTokenQueuesEachRunOnceAndHandsItOutOnce(): void
    {
        $token = new CancellationToken();
        $token->cancelAgent('run-a', 'call_a');
        $token->cancelAgent('run-a', 'call_a');
        $token->cancelAgent('', 'call_x');
        $token->cancelAgent('run-x', '');
        $token->cancelAgent('run-b', 'call_b');

        $this->assertSame(
            [['agentId' => 'run-a', 'callId' => 'call_a'], ['agentId' => 'run-b', 'callId' => 'call_b']],
            $token->takeAgentCancels(),
        );
        $this->assertSame([], $token->takeAgentCancels(), 'each run is handed out once');
        $this->assertTrue($token->isAgentCancelled('run-a'));
        $this->assertFalse($token->isAgentCancelled('run-c'));
        $this->assertSame([], $token->takeToolCancels(), 'a hard stop is not Esc\'s cancel_tool');
        $this->assertFalse($token->isSoftCancelled(), 'stopping one run is not stopping the turn');
    }

    public function testTheChannelHandsOutAgentCancelsAndLeavesEveryOtherControl(): void
    {
        [$parent, $child] = $this->pair();
        $channel = ChildChannel::new($child, static function (array $frame): void {
        }, ForkChannelEofIsUnansweredTest::drain());

        self::send($parent, ['kind' => ChildChannel::AGENT_CANCEL, 'agentId' => 'run-x', 'callId' => 'call_x']);
        self::send($parent, ['kind' => ChildChannel::CANCEL_TOOL, 'callId' => 'call_y']);
        self::send($parent, ['kind' => ChildChannel::AGENT_CANCEL, 'agentId' => '', 'callId' => 'call_z']);
        self::send($parent, ['kind' => ChildChannel::CANCEL_SOFT]);

        $this->assertSame([['agentId' => 'run-x', 'callId' => 'call_x']], $channel->takeAgentCancels());
        $this->assertSame([], $channel->takeAgentCancels());
        $this->assertSame(['call_y'], $channel->takeToolCancels(), 'the tool cancel was left for its own reader');
        $this->assertTrue($channel->softCancelRequested());
    }

    public function testTheSeamAnswersOnlyInTheListeningProcessAndHandsEachCallOnToTheToolCancels(): void
    {
        $pulls = 0;
        AgentCancelRequests::listen(static function () use (&$pulls): array {
            return ++$pulls === 1
                ? [['agentId' => 'run-a', 'callId' => 'call_a'], ['agentId' => 'run-a2', 'callId' => 'call_a'], ['junk']]
                : [];
        });

        $this->assertSame('run-a', AgentCancelRequests::forCall('call_a'), 'the first request for a call wins');
        $this->assertNull(AgentCancelRequests::forCall('call_b'));
        $this->assertNull(AgentCancelRequests::forCall(''));
        $this->assertSame(['call_a'], AgentCancelRequests::takeNewCallIds());
        $this->assertSame([], AgentCancelRequests::takeNewCallIds(), 'handed on once');
        $this->assertSame('run-a', AgentCancelRequests::forCall('call_a'), 'latched for the rest of the turn');

        if (!\function_exists('pcntl_fork')) {
            return;
        }
        $pid = $this->forkTracked();
        if ($pid === 0) {
            \SugarCraft\Crush\Support\ForkedChild::exitNow(AgentCancelRequests::forCall('call_a') === null ? 0 : 1);
        }
        $status = 0;
        \pcntl_waitpid($pid, $status);
        $this->assertSame(0, \pcntl_wexitstatus($status), 'a forked process never acts on the turn child\'s requests');
    }

    public function testAMemberThatHonoursTheSigtermStopsByItselfAndItsOwnResultIsReleased(): void
    {
        $this->requirePcntl();
        /** @var list<SubAgentActivity> $beats */
        $beats = [];
        $emitter = static function (SubAgentActivity $beat) use (&$beats): void {
            $beats[] = $beat;
        };
        AgentCancelRequests::listen(self::onceStarted($beats, 'graceful-run', 'call_graceful'));

        $started = microtime(true);
        $results = $this->runGroup(
            [self::graceful('graceful', 'call_graceful', $emitter), self::sleeper('quick', 100_000)],
            ['call_graceful', 'call_quick'],
        );

        $this->assertLessThan(AgentCancelRequests::GRACE_SECONDS + 1.5, microtime(true) - $started, 'it stopped on the signal, not at the kill');
        $this->assertSame('stopped at my next step; nested child ended by signal 15', $results[0]->content());
        $this->assertFalse($results[1]->isError(), $results[1]->content());
        $this->assertSame('quick done', $results[1]->content(), 'its sibling carried on');
        $last = end($beats);
        $this->assertSame(SubAgentActivity::OP_FINISHED, $last->op);
        $this->assertSame(SubAgentActivity::OUTCOME_CANCELLED, $last->outcome, 'its own finished beat went out');
    }

    public function testAMemberThatDoesNotStopIsKilledOnceTheGraceRunsOut(): void
    {
        $this->requirePcntl();
        /** @var list<SubAgentActivity> $beats */
        $beats = [];
        $emitter = static function (SubAgentActivity $beat) use (&$beats): void {
            $beats[] = $beat;
        };
        AgentCancelRequests::listen(self::onceStarted($beats, 'stubborn-run', 'call_stubborn'));

        $started = microtime(true);
        $results = $this->runGroup(
            [self::stubborn('stubborn', $emitter), self::sleeper('quick', 100_000)],
            ['call_stubborn', 'call_quick'],
        );
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThanOrEqual(AgentCancelRequests::GRACE_SECONDS, $elapsed, 'it had the grace');
        $this->assertLessThan(15.0, $elapsed, 'the 30 s member was not waited for');
        $this->assertTrue($results[0]->isError());
        $this->assertSame(AgentCancelRequests::KILLED, $results[0]->content());
        $this->assertSame('quick done', $results[1]->content());
        $last = end($beats);
        $this->assertSame(SubAgentActivity::OP_FINISHED, $last->op, 'its row is closed before its ToolFinished');
        $this->assertSame(SubAgentActivity::OUTCOME_CANCELLED, $last->outcome);
        $this->assertSame(AgentCancelRequests::KILLED, $last->error);
    }

    public function testADelegationStillQueuedForASlotIsNeverStarted(): void
    {
        $this->requirePcntl();
        $marker = sys_get_temp_dir() . '/sc_hard_cancel_queued_' . bin2hex(random_bytes(4));
        AgentCancelRequests::listen(static fn (): array => [['agentId' => 'second-run', 'callId' => 'call_second']]);

        $results = $this->runGroup(
            [self::delegator('first', null, 200_000), self::delegator('second', $marker, 0)],
            ['call_first', 'call_second'],
            maxConcurrentDelegations: 1,
        );

        $this->assertFalse($results[0]->isError(), $results[0]->content());
        $this->assertSame(AgentCancelRequests::NEVER_STARTED, $results[1]->content());
        $this->assertFileDoesNotExist($marker, 'the queued member never ran');
    }

    /**
     * End to end across completeAsync()'s fork: Chat's hard stop on the
     * token goes down as `agent_cancel`, the turn child SIGTERMs that member
     * (a plain one, with no graceful stop armed, so the signal ends it) and
     * the sibling's result and the turn's own reply survive.
     */
    public function testAcrossTheForkTheTokensHardStopEndsOnlyThatMember(): void
    {
        $this->requirePcntl();
        $token = new CancellationToken();
        $finished = [];
        $step = 0;
        $provider = new ScriptedProvider([
            static function (\SugarCraft\Crush\Providers\CompleteRequest $request) use (&$step): CompleteResponse {
                $step++;

                return new CompleteResponse(content: "step {$step}", toolCalls: [
                    new ToolCall('c_slow', 'slow', []),
                    new ToolCall('c_quick', 'quick', []),
                ]);
            },
        ]);
        $backend = EngineBackend::new($provider, 'm')
            ->withTools([self::sleeper('slow', 30_000_000), self::sleeper('quick', 100_000)])
            ->withMaxSteps(5);

        $started = microtime(true);
        $state = InteractiveTurnHarness::settle(
            $backend->completeAsync(
                [\SugarCraft\Crush\Message::user('go')],
                cancellation: $token,
                onEvent: static function (object $e) use ($token, &$finished): void {
                    if ($e instanceof \SugarCraft\Crush\Events\ToolStarted && $e->toolName === 'slow') {
                        // What the Agent View's second cancel press does,
                        // with the soft cancel that ends the turn after it.
                        $token->cancelAgent('slow-run', $e->toolCallId);
                        $token->cancelSoft();
                    }
                    if ($e instanceof \SugarCraft\Crush\Events\ToolFinished) {
                        $finished[$e->toolName] = $e->result->content();
                    }
                },
            ),
            Loop::get(),
        );

        $this->assertTrue($state['settled'], 'the turn never settled');
        $this->assertNull($state['error'], 'stopping one run does not fail the turn');
        $this->assertLessThan(15.0, microtime(true) - $started, 'the 30 s member was not waited for');
        $this->assertSame(AgentCancelRequests::STOPPED, $finished['slow'] ?? null);
        $this->assertSame('quick done', $finished['quick'] ?? null);
        $this->assertSame('step 1', $state['value']->content, 'and the turn ended at its boundary');
    }

    private function requirePcntl(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('The hard stop of a concurrent member requires ext-pcntl.');
        }
    }

    /**
     * The request arrives once the member is seen running, as it does from
     * the Agent View.
     *
     * @param list<SubAgentActivity> $beats
     *
     * @return \Closure(): list<array<string, string>>
     */
    private static function onceStarted(array &$beats, string $agentId, string $callId): \Closure
    {
        $sent = false;

        return static function () use (&$beats, &$sent, $agentId, $callId): array {
            if ($sent || $beats === []) {
                return [];
            }
            $sent = true;

            return [['agentId' => $agentId, 'callId' => $callId]];
        };
    }

    /**
     * @param list<Tool>   $tools
     * @param list<string> $ids
     *
     * @return list<ToolResultMessage>
     */
    private function runGroup(array $tools, array $ids, ?int $maxConcurrentDelegations = null): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true, maxConcurrentDelegations: $maxConcurrentDelegations);
        $app = App::new($provider, 'gpt-4')->withTools($tools);
        $calls = [];
        foreach ($tools as $i => $tool) {
            $calls[] = new ToolCall($ids[$i], $tool->name(), []);
        }

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');

        /** @var list<ToolResultMessage> */
        return array_values(iterator_to_array($method->invoke(
            $runtime,
            $calls,
            $app,
            static function (): void {},
            null,
            null,
        )));
    }

    private static function sleeper(string $name, int $micros): Tool
    {
        return new class ($name, $micros) implements Tool, ParallelSafe {
            public function __construct(private string $name, private int $micros)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'sleeps';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                usleep($this->micros);

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' done');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }

    private static function delegator(string $name, ?string $marker, int $micros): Tool
    {
        return new class ($name, $marker, $micros) implements Tool, ParallelSafe, ExemptFromParallelDeadline {
            public function __construct(private string $name, private ?string $marker, private int $micros)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'a delegated run';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                if ($this->marker !== null) {
                    touch($this->marker);
                }
                usleep($this->micros);

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' done');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }

    /**
     * A delegated run the way TaskTool is one: it starts a process of its
     * own (here a nested fork, as a parallel batch inside the run would be),
     * and asks {@see ToolCancelRequests} at each "step" whether to stop —
     * then reports how the nested process ended, and finishes its row.
     */
    private static function graceful(string $name, string $callId, \Closure $emitter): Tool
    {
        return new class ($name, $callId, $emitter) implements Tool, ParallelSafe, ExemptFromParallelDeadline, StreamsActivity {
            public function __construct(private string $name, private string $callId, private \Closure $emitter)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'a delegated run that stops at its next step';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $nested = \pcntl_fork();
                if ($nested === 0) {
                    usleep(30_000_000);
                    \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
                }
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_STARTED, $this->name . '-run', $this->name, 'task', 1, ''));

                $until = microtime(true) + 30.0;
                while (!ToolCancelRequests::isRequested($this->callId) && microtime(true) < $until) {
                    usleep(20_000);
                }
                $status = 0;
                $how = 'still running';
                for ($i = 0; $i < 200; $i++) {
                    if (\pcntl_waitpid($nested, $status, \WNOHANG) !== 0) {
                        $how = \pcntl_wifsignaled($status) ? 'ended by signal ' . \pcntl_wtermsig($status) : 'exited';

                        break;
                    }
                    usleep(10_000);
                }
                if ($how === 'still running') {
                    @\posix_kill($nested, 9);
                    \pcntl_waitpid($nested, $status);
                }
                ($this->emitter)(new SubAgentActivity(
                    SubAgentActivity::OP_FINISHED,
                    $this->name . '-run',
                    $this->name,
                    '',
                    2,
                    '',
                    outcome: SubAgentActivity::OUTCOME_CANCELLED,
                ));

                return new ToolResult(toolCallId: $this->callId, content: 'stopped at my next step; nested child ' . $how, isError: true);
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function subAgentEmitter(): ?\Closure
            {
                return $this->emitter;
            }

            public function queuedActivity(ToolCall $call, array $args): ?SubAgentActivity
            {
                return null;
            }

            public function withActivitySink(ActivitySink $sink): Tool
            {
                return new self($this->name, $this->callId, static function (SubAgentActivity $beat) use ($sink): void {
                    $sink->emit($beat);
                });
            }
        };
    }

    /** A delegated run that never looks up: only the kill stops it. */
    private static function stubborn(string $name, \Closure $emitter): Tool
    {
        return new class ($name, $emitter) implements Tool, ParallelSafe, ExemptFromParallelDeadline, StreamsActivity {
            public function __construct(private string $name, private \Closure $emitter)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'a long delegated run';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_STARTED, $this->name . '-run', $this->name, 'task', 1, ''));
                // A signal cuts one usleep short; the loop keeps it busy.
                $until = microtime(true) + 30.0;
                while (microtime(true) < $until) {
                    usleep(100_000);
                }

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'never');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function subAgentEmitter(): ?\Closure
            {
                return $this->emitter;
            }

            public function queuedActivity(ToolCall $call, array $args): ?SubAgentActivity
            {
                return null;
            }

            public function withActivitySink(ActivitySink $sink): Tool
            {
                return new self($this->name, static function (SubAgentActivity $beat) use ($sink): void {
                    $sink->emit($beat);
                });
            }
        };
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
