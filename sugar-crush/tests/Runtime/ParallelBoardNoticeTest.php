<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Board\ActiveBoard;
use SugarCraft\Crush\Agents\Board\Board;
use SugarCraft\Crush\Agents\Board\BoardKind;
use SugarCraft\Crush\Agents\Board\BoardMember;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\BuiltIn\BoardNoticeHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\BoardPostTool;
use SugarCraft\Crush\Tools\BuiltIn\BoardReadTool;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\SharesBoard;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.5: the members of one parallel batch share a board.
 *
 * The Runtime half: `executeConcurrently()` makes one board per batch when two
 * or more members can join, each forked member runs with its own view, a post
 * by one is news to the other — delivered as a notice on its next tool result
 * — and the board is gone once the batch is. The TaskTool half: a member's
 * delegated run is offered `BoardRead`/`BoardPost` (held to its preset's
 * grant) and hears of its peers' posts on its next tool result.
 */
final class ParallelBoardNoticeTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_board_task_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
        parent::tearDown();
    }

    public function testTwoForkedMembersShareOneBoardAndEachHearsOfTheOthersPost(): void
    {
        $this->requireFork();

        $results = $this->runBatch(self::member('alpha'), self::member('beta'));

        $a = json_decode($results[0]->content(), true);
        $b = json_decode($results[1]->content(), true);
        $this->assertIsArray($a, $results[0]->content());
        $this->assertIsArray($b, $results[1]->content());

        $this->assertSame('member-1', $a['me']);
        $this->assertSame('member-2', $b['me']);
        $this->assertSame($a['path'], $b['path'], 'one board for the batch');
        $this->assertNotSame(getmypid(), $a['pid'], 'the member ran forked');
        $this->assertSame(BoardNoticeHook::NOTICE, $a['notice'], 'alpha heard of beta\'s post on its next tool result');
        $this->assertSame(BoardNoticeHook::NOTICE, $b['notice'], 'beta heard of alpha\'s post');
        $this->assertSame('alpha', $a['task']);
        $this->assertFileDoesNotExist($a['path'], 'the board went with its batch');
    }

    public function testALoneMemberGetsNoBoard(): void
    {
        $this->requireFork();

        $results = $this->runBatch(self::member('alpha'), self::plain('Read'));

        $this->assertSame('no board', $results[0]->content());
    }

    public function testAMemberThatWouldNotJoinLeavesItsPeerAlone(): void
    {
        $this->requireFork();

        $results = $this->runBatch(self::member('alpha'), self::member('beta', joins: false));

        $this->assertSame('no board', $results[0]->content());
        $this->assertSame('no board', $results[1]->content());
    }

    public function testATaskMembersRunIsOfferedTheBoardAndHearsOfItsPeersPostOnItsNextResult(): void
    {
        $board = Board::create([BoardMember::new('coder', 'A'), BoardMember::new('coder', 'B')]);
        $this->assertNotNull($board);
        $board->forMember('coder-2')->post(Board::ALL, BoardKind::Info, 'I am editing src/Auth.php');

        $read = self::probe('Read');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['path' => 'a'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c2', BoardReadTool::NAME, [])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c3', BoardPostTool::NAME, ['to' => 'coder-2', 'kind' => 'RESULT', 'body' => 'leaving Auth.php to you'])]),
            new CompleteResponse(content: 'done'),
        ]);
        $task = $this->task(self::manager([$read], RosterAgent::named('coder')), $provider, [$read])
            ->withBoard($board->forMember('coder-1'));

        $result = $task->execute(['description' => 'A', 'prompt' => 'do A', 'agent' => 'coder']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertContains(BoardReadTool::NAME, self::toolNames($provider->requests[0]));
        $this->assertContains(BoardPostTool::NAME, self::toolNames($provider->requests[0]));
        $this->assertStringContainsString(BoardNoticeHook::NOTICE, self::lastToolTurn($provider->requests[1]), 'the peer\'s earlier post is news on the first result');
        $this->assertStringContainsString('#1 coder-2 → ALL [INFO] I am editing src/Auth.php', self::lastToolTurn($provider->requests[2]));
        $this->assertStringNotContainsString(BoardNoticeHook::NOTICE, self::lastToolTurn($provider->requests[2]));
        $this->assertSame('Posted #2 to coder-2 [RESULT].', self::lastToolTurn($provider->requests[3]));
        $this->assertSame('coder-1', $board->entries()[1]->from);
        $this->assertNull(ActiveBoard::current(), 'the run\'s binding ends with the run');
        $board->discard();
    }

    public function testAToolsListDoesNotKeepAMemberOffTheBoardButADenylistDoes(): void
    {
        $board = Board::create([BoardMember::new('reader', 'A'), BoardMember::new('coder', 'B')]);
        $this->assertNotNull($board);
        $board->forMember('coder-2')->post(Board::ALL, BoardKind::Info, 'news');
        $read = self::probe('Read');
        $script = static fn (): ScriptedProvider => new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['path' => 'a'])]),
            new CompleteResponse(content: 'done'),
        ]);

        // Every built-in agent declares `tools:`, so this is the common case.
        $declared = $script();
        $this->task(self::manager([$read], RosterAgent::named('reader', ['Read'])), $declared, [$read])
            ->withBoard($board->forMember('reader-1'))
            ->execute(['description' => 'A', 'prompt' => 'do A', 'agent' => 'reader']);
        $this->assertSame(['Read', BoardReadTool::NAME, BoardPostTool::NAME], self::toolNames($declared->requests[0]));
        $this->assertStringContainsString(BoardNoticeHook::NOTICE, self::lastToolTurn($declared->requests[1]));

        $noPosting = $script();
        $this->task(self::manager([$read], self::denying('reader', ['Read'], ['BoardPost'])), $noPosting, [$read])
            ->withBoard($board->forMember('reader-1'))
            ->execute(['description' => 'A', 'prompt' => 'do A', 'agent' => 'reader']);
        $this->assertSame(['Read', BoardReadTool::NAME], self::toolNames($noPosting->requests[0]));

        $noReading = $script();
        $this->task(self::manager([$read], self::denying('reader', ['Read'], ['BoardRead'])), $noReading, [$read])
            ->withBoard($board->forMember('reader-1'))
            ->execute(['description' => 'A', 'prompt' => 'do A', 'agent' => 'reader']);
        $this->assertSame(['Read', BoardPostTool::NAME], self::toolNames($noReading->requests[0]));
        $this->assertStringNotContainsString(BoardNoticeHook::NOTICE, self::lastToolTurn($noReading->requests[1]), 'no notice for a member that cannot read the board');
        $board->discard();
    }

    public function testTheGrantHookLetsAMemberCallTheBoardToolsItsListDoesNotName(): void
    {
        $manager = self::manager([], RosterAgent::named('reader', ['Read']));
        $subAgent = $manager->createSubAgent('reader', 'read');

        $this->assertNull($manager->grantRefusalFor(new \SugarCraft\Crush\ToolCall(BoardReadTool::NAME, []), $subAgent));
        $this->assertNull($manager->grantRefusalFor(new \SugarCraft\Crush\ToolCall(BoardPostTool::NAME, ['to' => 'ALL']), $subAgent));
        $this->assertNotNull($manager->grantRefusalFor(new \SugarCraft\Crush\ToolCall('Bash', ['command' => 'ls']), $subAgent), 'every other name is still held to the list');

        $denying = self::manager([], self::denying('reader', ['Read'], ['BoardPost']));
        $this->assertNotNull($denying->grantRefusalFor(new \SugarCraft\Crush\ToolCall(BoardPostTool::NAME, []), $denying->createSubAgent('reader', 'read')));
    }

    public function testATaskCallNamesItsBoardParticipant(): void
    {
        $task = new TaskTool();

        $member = $task->boardMember(['agent' => ' coder ', 'description' => 'Rename the helper']);
        $this->assertNotNull($member);
        $this->assertSame('coder', $member->agent);
        $this->assertSame('Rename the helper', $member->task);
        $this->assertSame('tester', $task->boardMember(['subagent_type' => 'tester'])?->agent);
        $this->assertNull($task->boardMember(['description' => 'no agent']));
        $this->assertNotNull($task->boardMember(['agent' => 'coder', 'background' => true]), 'with no supervisor bound a background request runs in the foreground, so it joins');
    }

    /**
     * @return list<ToolResultMessage>
     */
    private function runBatch(Tool $a, Tool $b): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new BoardNoticeHook());
        $runtime = new Runtime($provider, $hooks, null, true);
        $app = App::new($provider, 'gpt-4')->withTools([$a, $b]);

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');

        /** @var list<ToolResultMessage> */
        return array_values(iterator_to_array($method->invoke(
            $runtime,
            [new ToolCall('call_a', $a->name(), ['description' => $a->name()]), new ToolCall('call_b', $b->name(), ['description' => $b->name()])],
            $app,
            static function (): void {},
            null,
            null,
        )));
    }

    /**
     * A parallel batch member standing in for a `Task`: seated on a board it
     * posts to everyone, waits until a peer has posted too, then runs the
     * notice hook as its run's next PostToolUse would, and reports.
     */
    private static function member(string $name, bool $joins = true): Tool
    {
        return new class ($name, $joins) implements Tool, ParallelSafe, ExemptFromParallelDeadline, SharesBoard {
            public function __construct(
                private string $name,
                private bool $joins,
                private ?Board $board = null,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'a board member';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function boardMember(array $args): ?BoardMember
            {
                return $this->joins ? BoardMember::new('member', (string) ($args['description'] ?? '')) : null;
            }

            public function withBoard(Board $board): Tool
            {
                return new self($this->name, $this->joins, $board);
            }

            public function execute(array $args): ToolResult
            {
                $id = (string) ($args['id'] ?? '');
                if ($this->board === null) {
                    return new ToolResult($id, 'no board');
                }

                $seat = ActiveBoard::enter($this->board);
                $this->board->post(Board::ALL, BoardKind::Info, $this->name . ' was here');
                $deadline = microtime(true) + 10;
                while (\count($this->board->entries()) < 2 && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                $notice = (new BoardNoticeHook())->execute(new HookContext('s', 'Read', [], '{}', 'out', 'm', 'p', '/tmp'))->additionalContext;
                unset($seat);

                return new ToolResult($id, (string) json_encode([
                    'me' => $this->board->member(),
                    'task' => $this->board->find($this->board->member())?->task,
                    'path' => $this->board->path(),
                    'pid' => getmypid(),
                    'notice' => $notice,
                ]));
            }
        };
    }

    private static function plain(string $name): Tool
    {
        return new class ($name) implements Tool, ParallelSafe {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'plain';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult((string) ($args['id'] ?? ''), 'plain');
            }
        };
    }

    /**
     * @param list<Tool> $tools the session engine's own tools
     */
    private function task(AgentManager $manager, ScriptedProvider $provider, array $tools): TaskTool
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new BoardNoticeHook());

        return (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools($tools)->withHooks($hooks));
    }

    /**
     * @param list<Tool> $registry
     */
    private static function manager(array $registry, Agent $agent): AgentManager
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: $registry, toolUniverse: $registry);
        $manager->register($agent);

        return $manager;
    }

    /**
     * @param list<string> $tools
     * @param list<string> $disallowed
     */
    private static function denying(string $name, array $tools, array $disallowed): Agent
    {
        return new Agent(
            name: $name,
            description: "test agent {$name}",
            prompt: "You are {$name}.",
            model: 'test-model',
            provider: 'test',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: $disallowed,
        );
    }

    /**
     * @return list<string>
     */
    private static function toolNames(CompleteRequest $request): array
    {
        return array_map(static fn (Tool $tool): string => $tool->name(), $request->tools ?? []);
    }

    private static function lastToolTurn(CompleteRequest $request): string
    {
        $turns = array_values(array_filter($request->messages, static fn (TypedMessage $m): bool => $m->role() === 'tool'));

        return $turns === [] ? '' : $turns[\count($turns) - 1]->content();
    }

    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'probe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult((string) ($args['id'] ?? ''), $this->name . ' ok');
            }
        };
    }

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }
}
