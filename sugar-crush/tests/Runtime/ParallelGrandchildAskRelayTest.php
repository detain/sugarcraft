<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\PermissionAskRelay;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\RelaysPermissionAsks;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-5: a parallel Task member — a GRANDCHILD below the turn child —
 * puts its permission questions to the turn's approver over a per-member
 * {@see PermissionAskRelay}, instead of being refused with
 * {@see ChildChannel::GRANDCHILD_REFUSAL}. Since DEF-MODE made the TUI ask by
 * default, that refusal was every parallel Task write.
 *
 * The witness is the process: the approver must run in the forking process
 * (the one whose approver is the turn's channel), and the member must get the
 * verdict back exactly as it was settled.
 */
final class ParallelGrandchildAskRelayTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        parent::tearDown();
    }

    public function testEachMembersQuestionReachesTheTurnsApproverAndItsVerdictComesBackExactly(): void
    {
        $parent = getmypid();
        /** @var list<array{pid: int|false, call: string, args: array<string, mixed>, gateOnly: bool}> $asked */
        $asked = [];
        $approver = static function (ToolCall $call, HookResult $ask) use (&$asked): ApprovalVerdict {
            $asked[] = [
                'pid' => getmypid(),
                'call' => $call->id(),
                'args' => $call->arguments(),
                'gateOnly' => $ask->askedOnlyBy(PermissionGateHook::NAME),
            ];

            return $call->arguments()['path'] === 'a.txt'
                ? ApprovalVerdict::once()
                : ApprovalVerdict::rejectedByUser('use the other file');
        };

        $results = $this->run2(self::member('task_a', 'a.txt'), self::member('task_b', 'b.txt'), $approver);

        $this->assertCount(2, $asked, 'one question from each member');
        foreach ($asked as $question) {
            $this->assertSame($parent, $question['pid'], 'the question is put in the forking process, to the turn\'s approver');
            $this->assertTrue($question['gateOnly'], 'who asked survives the relay, so `always` stays on offer');
        }
        $this->assertEqualsCanonicalizing(['inner_task_a', 'inner_task_b'], array_column($asked, 'call'));

        $this->assertSame('task_a: once  [forked]', $results[0]->content());
        $this->assertSame('task_b: reject the user said: use the other file [forked]', $results[1]->content());
    }

    public function testAnUnansweredQuestionStaysUnansweredInTheMember(): void
    {
        $approver = static fn (): ApprovalVerdict => ApprovalVerdict::unanswered('the turn ended');

        $results = $this->run2(self::member('task_a', 'a.txt'), self::member('task_b', 'b.txt'), $approver);

        $this->assertSame('task_a: unanswered the turn ended [forked]', $results[0]->content());
        $this->assertSame('task_b: unanswered the turn ended [forked]', $results[1]->content());
    }

    public function testALiteralTrueFromTheApproverIsAGrantAndAnythingElseIsARefusal(): void
    {
        $approver = static fn (ToolCall $call): mixed => $call->arguments()['path'] === 'a.txt' ? true : 'yes please';

        $results = $this->run2(self::member('task_a', 'a.txt'), self::member('task_b', 'b.txt'), $approver);

        $this->assertSame('task_a: once  [forked]', $results[0]->content());
        $this->assertSame('task_b: reject  [forked]', $results[1]->content());
    }

    public function testAnApproverThatThrowsIsARefusalNotACrash(): void
    {
        $approver = static function (): never {
            throw new \RuntimeException('modal broke');
        };

        $results = $this->run2(self::member('task_a', 'a.txt'), self::member('task_b', 'b.txt'), $approver);

        $this->assertSame('task_a: reject the approver failed: modal broke [forked]', $results[0]->content());
        $this->assertFalse($results[1]->isError());
    }

    public function testWithNoApproverNoRelayIsOpenedAndTheMemberKeepsWhatItInherited(): void
    {
        $results = $this->run2(self::member('task_a', 'a.txt'), self::member('task_b', 'b.txt'), null);

        $this->assertSame('task_a: inherited [forked]', $results[0]->content());
        $this->assertSame('task_b: inherited [forked]', $results[1]->content());
    }

    /**
     * The member's half sees the turn child's end close as "nobody answered",
     * never as a grant.
     */
    public function testAMemberWhoseTurnChildIsGoneSettlesUnanswered(): void
    {
        $relay = PermissionAskRelay::open();
        $this->assertNotNull($relay);
        $approver = $relay->childApprover();

        $verdict = $approver(new ToolCall('c1', 'Write', ['path' => 'x']), HookResult::ask('Write?'));

        $this->assertTrue($verdict->isUnanswered());
        $this->assertSame(PermissionAskRelay::PARENT_GONE, $verdict->feedback);
        $relay->close();
    }

    /**
     * RELAY design point 2: the member gives up its inherited copy of the
     * turn socket, so nothing in it can write onto the turn's stream; the
     * process that built the channel keeps its own.
     */
    public function testAForkedMemberReleasesTheTurnSocketItInheritedAndTheOwnerKeepsIt(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        $channel = ChildChannel::new($pair[0], static function (): void {}, static fn (): array => []);

        $this->assertSame(0, ChildChannel::releaseInherited(), 'the owner closes nothing of its own');
        $this->assertIsResource($pair[0]);

        $pid = $this->forkTracked();
        if ($pid === 0) {
            $closed = ChildChannel::releaseInherited();
            \SugarCraft\Crush\Support\ForkedChild::exitNow($closed >= 1 && !is_resource($pair[0]) && $channel->isClosed() ? 0 : 1);
        }
        $status = 0;
        pcntl_waitpid($pid, $status);

        $this->assertSame(0, pcntl_wexitstatus($status), 'the forked process closed the inherited socket and marked the channel closed');
        $this->assertIsResource($pair[0], 'the owner\'s socket is untouched by the fork closing its copy');
        $this->assertFalse($channel->isClosed());
        fclose($pair[0]);
        fclose($pair[1]);
    }

    /**
     * The real tool: a TaskTool rebound by the relay puts its delegated run's
     * questions to the new approver, not to the one its engine was bound with.
     */
    public function testATaskToolsDelegatedRunAsksTheApproverItWasRelayedNotTheInheritedOne(): void
    {
        $edit = self::recordingTool('Edit');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Edit', ['path' => 'x.txt'])]),
            new CompleteResponse(content: 'edited'),
        ]);
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$edit], toolUniverse: [$edit]);
        $manager->register(RosterAgent::named('coder', ['Edit'], maxTurns: 3));
        $inherited = [];
        $relayed = [];
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([$edit])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call) use (&$inherited): ApprovalVerdict {
                $inherited[] = $call->name();

                return ApprovalVerdict::reject(ChildChannel::GRANDCHILD_REFUSAL);
            });

        $task = (new TaskTool($manager))->withEngine($engine);
        $this->assertInstanceOf(RelaysPermissionAsks::class, $task);
        $result = $task->withPermissionApprover(static function (ToolCall $call) use (&$relayed): ApprovalVerdict {
            $relayed[] = $call->name();

            return ApprovalVerdict::once();
        })->execute(['description' => 'Edit x', 'prompt' => 'Edit x.txt', 'agent' => 'coder']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(['Edit'], $relayed);
        $this->assertSame([], $inherited, 'the approver it was bound with is never asked');
        $this->assertSame(1, $edit->calls, 'the approved call ran');
    }

    public function testATaskToolWithNoEngineIsReturnedAsIs(): void
    {
        $task = new TaskTool();

        $this->assertSame($task, $task->withPermissionApprover(static fn (): bool => true));
    }

    /**
     * @return list<ToolResultMessage>
     */
    private function run2(Tool $a, Tool $b, ?\Closure $approver): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true);
        $app = App::new($provider, 'gpt-4')->withTools([$a, $b]);

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');

        /** @var list<ToolResultMessage> */
        return array_values(iterator_to_array($method->invoke(
            $runtime,
            [new ToolCall('call_a', $a->name(), []), new ToolCall('call_b', $b->name(), [])],
            $app,
            static function (): void {},
            $approver,
            null,
        )));
    }

    /**
     * A parallel-safe stand-in for a Task member: its run raises one
     * gate-only question about writing $path and reports what it was told,
     * and whether it ran forked.
     */
    private static function member(string $name, string $path): Tool
    {
        $parent = getmypid();
        $inherited = static fn (): ApprovalVerdict => ApprovalVerdict::reject('inherited');

        return new class ($name, $path, $parent, $inherited) implements Tool, ParallelSafe, ExemptFromParallelDeadline, RelaysPermissionAsks {
            public function __construct(
                private string $name,
                private string $path,
                private int|false $parent,
                private \Closure $approver,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'asks before it writes';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $where = getmypid() === $this->parent ? '[inline]' : '[forked]';
                $verdict = ($this->approver)(
                    new ToolCall('inner_' . $this->name, 'Write', ['path' => $this->path]),
                    HookResult::ask('Write ' . $this->path . '?')->withAskedBy([PermissionGateHook::NAME]),
                );
                if ($verdict->feedback === 'inherited') {
                    return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ': inherited ' . $where);
                }
                $reply = $verdict->reply?->value ?? 'unanswered';

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: "{$this->name}: {$reply} {$verdict->feedback} {$where}");
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function withPermissionApprover(\Closure $approver): Tool
            {
                return new self($this->name, $this->path, $this->parent, $approver);
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
                return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
            }
        };
    }
}
