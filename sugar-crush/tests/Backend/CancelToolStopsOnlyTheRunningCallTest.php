<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Agents\AgentManager;
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
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\ActivitySink;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\StreamsActivity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-4b: `cancel_tool{callId}` — the first Esc stops the call that
 * is running, not the whole turn and not after the call finishes. Pinned at
 * every hop: the token the TUI flips, the child's channel, the seam the turn
 * child consults, the concurrent reap loop that kills one member, and a lone
 * Task that stops itself.
 */
final class CancelToolStopsOnlyTheRunningCallTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        ToolCancelRequests::forget();
        foreach ($this->open as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        parent::tearDown();
    }

    public function testTheTokenQueuesEachCallOnceAndHandsItOutOnce(): void
    {
        $token = new CancellationToken();
        $token->cancelTool('call_a');
        $token->cancelTool('call_a');
        $token->cancelTool('');
        $token->cancelTool('call_b');

        $this->assertSame(['call_a', 'call_b'], $token->takeToolCancels());
        $this->assertSame([], $token->takeToolCancels(), 'each id is handed out once');
        $this->assertTrue($token->isToolCancelled('call_a'));
        $this->assertFalse($token->isToolCancelled('call_c'));
        $this->assertFalse($token->isSoftCancelled(), 'stopping one call is not stopping the turn');
    }

    public function testTheChannelHandsOutToolCancelsAndLeavesTheSoftCancelForTheTurnLoop(): void
    {
        [$parent, $child] = $this->pair();
        $written = [];
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use (&$written): void {
                $written[] = $frame;
            },
            ForkChannelEofIsUnansweredTest::drain(),
        );

        self::send($parent, ['kind' => ChildChannel::CANCEL_TOOL, 'callId' => 'call_x']);
        self::send($parent, ['kind' => ChildChannel::CANCEL_SOFT]);
        self::send($parent, ['kind' => ChildChannel::CANCEL_TOOL, 'callId' => '']);

        $this->assertSame(['call_x'], $channel->takeToolCancels());
        $this->assertSame([], $channel->takeToolCancels());
        $this->assertTrue($channel->softCancelRequested(), 'the soft cancel was not consumed by the tool cancel');
    }

    public function testTheSeamAnswersOnlyInTheListeningProcessAndLatches(): void
    {
        $pulls = 0;
        ToolCancelRequests::listen(static function () use (&$pulls): array {
            return ++$pulls === 1 ? ['call_a'] : [];
        });

        $this->assertTrue(ToolCancelRequests::isRequested('call_a'));
        $this->assertTrue(ToolCancelRequests::isRequested('call_a'), 'latched for the rest of the turn');
        $this->assertFalse(ToolCancelRequests::isRequested('call_b'));
        $this->assertFalse(ToolCancelRequests::isRequested(''));

        if (!\function_exists('pcntl_fork')) {
            return;
        }
        $pid = $this->forkTracked();
        if ($pid === 0) {
            \SugarCraft\Crush\Support\ForkedChild::exitNow(ToolCancelRequests::isRequested('call_a') ? 1 : 0);
        }
        $status = 0;
        \pcntl_waitpid($pid, $status);
        $this->assertSame(0, \pcntl_wexitstatus($status), 'a forked process never acts on the turn child\'s requests');

        ToolCancelRequests::forget();
        $this->assertFalse(ToolCancelRequests::isRequested('call_a'));
    }

    public function testACancelledConcurrentMemberIsKilledAndItsSiblingStillReports(): void
    {
        $this->requirePcntl();
        ToolCancelRequests::listen(static fn (): array => ['call_slow']);

        $started = microtime(true);
        $results = $this->runGroup(
            [self::sleeper('slow', 30_000_000), self::sleeper('quick', 100_000)],
            ['call_slow', 'call_quick'],
        );

        $this->assertLessThan(10.0, microtime(true) - $started, 'the 30 s member was not waited for');
        $this->assertTrue($results[0]->isError());
        $this->assertSame(ToolCancelRequests::CANCELLED, $results[0]->content());
        $this->assertFalse($results[1]->isError(), $results[1]->content());
        $this->assertSame('quick done', $results[1]->content(), 'only the named call stopped');
    }

    public function testADelegationStillQueuedForASlotIsNeverStarted(): void
    {
        $this->requirePcntl();
        $marker = sys_get_temp_dir() . '/sc_cancel_queued_' . bin2hex(random_bytes(4));
        ToolCancelRequests::listen(static fn (): array => ['call_second']);

        $results = $this->runGroup(
            [self::delegator('first', null, 200_000), self::delegator('second', $marker, 0)],
            ['call_first', 'call_second'],
            maxConcurrentDelegations: 1,
        );

        $this->assertFalse($results[0]->isError(), $results[0]->content());
        $this->assertSame(ToolCancelRequests::CANCELLED, $results[1]->content());
        $this->assertFileDoesNotExist($marker, 'the queued member never ran');
    }

    public function testACancelledMembersRunIsClosedAsCancelledBeforeItsToolFinished(): void
    {
        $this->requirePcntl();
        /** @var list<SubAgentActivity> $beats */
        $beats = [];
        $emitter = static function (SubAgentActivity $beat) use (&$beats): void {
            $beats[] = $beat;
        };
        // Esc arrives once the member is seen running.
        $sent = false;
        ToolCancelRequests::listen(static function () use (&$beats, &$sent): array {
            if ($sent || $beats === []) {
                return [];
            }
            $sent = true;

            return ['call_stream'];
        });

        $results = $this->runGroup(
            [self::streamer('stream', $emitter), self::sleeper('other', 100_000)],
            ['call_stream', 'call_other'],
        );

        $this->assertSame(ToolCancelRequests::CANCELLED, $results[0]->content());
        $last = end($beats);
        $this->assertSame(SubAgentActivity::OP_FINISHED, $last->op);
        $this->assertSame(SubAgentActivity::OUTCOME_CANCELLED, $last->outcome);
        $this->assertSame(ToolCancelRequests::CANCELLED, $last->error);
    }

    public function testALoneTaskStopsAtItsNextStepAndStaysResumable(): void
    {
        $probe = self::recordingTool('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'never reached'),
        ]);
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$probe], toolUniverse: [$probe]);
        $manager->register(RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);
        $store = new \SugarCraft\Crush\Agents\SuspendedDelegations(sys_get_temp_dir() . '/sc_cancel_task_' . bin2hex(random_bytes(4)));
        /** @var list<SubAgentActivity> $beats */
        $beats = [];

        // Cancelled once the run has made its first call.
        ToolCancelRequests::listen(static fn (): array => $probe->calls > 0 ? ['call_task'] : []);

        $result = (new TaskTool($manager, suspended: $store))
            ->withEngine($engine, null, static function (SubAgentActivity $beat) use (&$beats): void {
                $beats[] = $beat;
            })
            ->execute(['id' => 'call_task', 'description' => 'probe', 'prompt' => 'probe it', 'agent' => 'coder']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('sub-agent "coder" was cancelled by the user (Esc)', $result->content());
        $this->assertMatchesRegularExpression('/"resume": "[0-9a-f]{16}"/', $result->content(), 'a cancelled run is resumable');
        $this->assertSame(1, $probe->calls, 'the call already made ran; nothing after the cancel did');
        $this->assertCount(1, $provider->requests, 'no further provider step after the cancel');
        $last = end($beats);
        $this->assertSame(SubAgentActivity::OUTCOME_CANCELLED, $last->outcome);
    }

    /**
     * End to end across completeAsync()'s fork: the parent's token names the
     * running call, the cancel tick sends `cancel_tool` down, the turn child
     * kills that member, and the turn ends at its boundary with the sibling's
     * result and its own reply.
     */
    public function testAcrossTheForkEscStopsTheRunningCallAndTheTurnEndsAtItsBoundary(): void
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
                        // What Chat's first Esc does with a running call.
                        $token->cancelTool($e->toolCallId);
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
        $this->assertNull($state['error'], 'stopping one call does not fail the turn');
        $this->assertLessThan(15.0, microtime(true) - $started, 'the 30 s call was not waited for');
        $this->assertSame(ToolCancelRequests::CANCELLED, $finished['slow'] ?? null);
        $this->assertSame('quick done', $finished['quick'] ?? null);
        // The provider ran in the child, so its request log is not ours to
        // read; each step answers with its own number, so a reply of
        // "step 1" is what says no step followed.
        $this->assertSame('step 1', $state['value']->content, 'and the turn ended at the boundary');
    }

    private function requirePcntl(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
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
     * A member that reports a run started and then works for a long time —
     * so the cancel arrives while the run is open.
     */
    private static function streamer(string $name, \Closure $emitter): Tool
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
                usleep(30_000_000);

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

    private static function recordingTool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public int $calls = 0;

            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'records its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;
                // Past the seam's pull interval, so the next step's check
                // reads the channel afresh.
                usleep(80_000);

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
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
