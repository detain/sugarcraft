<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ConversationFork;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Message;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The conversation-inheriting fork (CC `--fork-session` parity): a Task
 * launch with `fork: true` starts the child knowing the tail of the parent
 * conversation, read through the real persisted-transcript path — the same
 * store binding the launch records child sessions into.
 *
 * Pinned here: the default-off byte identity (a plain launch sees exactly
 * [system, task] even with a store bound), the inheritance content pin on
 * both dispatch shapes (engine and pool), the background and resume
 * interactions, the thirty-row window, and the guard absences.
 */
final class TaskToolForkContextTest extends TestCase
{
    private string $storeDir;
    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->storeDir = \sys_get_temp_dir() . '/sc_fork_ctx_' . \bin2hex(\random_bytes(6));
        \mkdir($this->storeDir, 0700, true);

        $this->store = new EnhancedSessionStore($this->storeDir . '/session.db');
        $this->store->createSession('parent-sess', 'p', 'm');
        $this->store->saveTranscript('parent-sess', [
            Message::system('the parent system prompt must never ride'),
            Message::user('the parent asked about sugar-core'),
            Message::assistant('the parent learned about widths'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->storeDir . '/*') ?: [] as $leftover) {
            @\unlink($leftover);
        }
        @\rmdir($this->storeDir);
    }

    public function testAForkStartsTheChildInsideTheParentConversation(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'the inherited audit')]);
        $task = $this->forkEngineTask($this->forkManager(), EngineBackend::new($provider, 'm')->withSessionId('parent-sess'));

        $result = $task->execute($this->forkCall(['fork' => true]));

        self::assertFalse($result->isError());
        self::assertSame([
            ['system', 'You are coder.'],
            ['user', 'the parent asked about sugar-core'],
            ['assistant', 'the parent learned about widths'],
            ['user', 'Inherit this conversation and report'],
        ], self::forkTurns($provider->requests[0]), 'inherited rows sit between the system turn and the child\'s own prompt');
    }

    public function testAPlainLaunchCarriesNothingEvenWithTheStoreBound(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'the plain audit')]);
        $task = $this->forkEngineTask($this->forkManager(), EngineBackend::new($provider, 'm')->withSessionId('parent-sess'));

        $result = $task->execute($this->forkCall());

        self::assertFalse($result->isError());
        // The byte-identity pin: with no `fork` argument the child request is
        // exactly the pre-fork shape — [system, task] and nothing else.
        self::assertSame([
            ['system', 'You are coder.'],
            ['user', 'Inherit this conversation and report'],
        ], self::forkTurns($provider->requests[0]));
    }

    public function testForkFalseIsThePlainShape(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'the plain audit')]);
        $task = $this->forkEngineTask($this->forkManager(), EngineBackend::new($provider, 'm')->withSessionId('parent-sess'));

        $task->execute($this->forkCall(['fork' => false]));

        self::assertSame([
            ['system', 'You are coder.'],
            ['user', 'Inherit this conversation and report'],
        ], self::forkTurns($provider->requests[0]));
    }

    public function testForkAskedTogetherWithBackgroundRunsForegroundInheritingAndSaysSo(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'the inherited audit')]);
        // No supervisor is bound on this TaskTool, so a background request
        // could not daemonise anyway — the pin is that the COMBINATION still
        // delivers the inheritance and leads the result with an honest note.
        $task = $this->forkEngineTask($this->forkManager(), EngineBackend::new($provider, 'm')->withSessionId('parent-sess'));

        $result = $task->execute($this->forkCall(['fork' => true, 'background' => true]));

        self::assertStringStartsWith(
            '[fork inheritance was requested together with background',
            $result->content(),
        );
        self::assertSame([
            ['system', 'You are coder.'],
            ['user', 'the parent asked about sugar-core'],
            ['assistant', 'the parent learned about widths'],
            ['user', 'Inherit this conversation and report'],
        ], self::forkTurns($provider->requests[0]));
    }

    public function testAResumedDelegationKeepsItsOwnTranscriptAndIgnoresFork(): void
    {
        $suspended = new SuspendedDelegations($this->storeDir . '/suspended');
        \mkdir($this->storeDir . '/suspended', 0700, true);
        $resumeId = $suspended->save('coder', [new UserMessage('the paused task')], 0);

        $provider = new ScriptedProvider([new CompleteResponse(content: 'the resumed audit')]);
        $manager = $this->forkManager();
        $task = (new TaskTool($manager, suspended: $suspended))
            ->withEngine(EngineBackend::new($provider, 'm')->withSessionId('parent-sess'));

        $result = $task->execute($this->forkCall([
            'fork' => true,
            'resume' => $resumeId,
            'prompt' => 'carry on and report',
        ]));

        self::assertFalse($result->isError());
        $contents = \array_map(static fn (array $turn): string => $turn[1], self::forkTurns($provider->requests[0]));
        self::assertContains('the paused task', $contents, 'the saved transcript rides as the child\'s past');
        self::assertContains('carry on and report', $contents);
        self::assertNotContains('the parent asked about sugar-core', $contents, 'fork never layers parent rows onto a resumed transcript');
    }

    public function testAForkWithoutASessionEngineSaysSoAndCarriesNothing(): void
    {
        $manager = $this->forkManager();
        $executor = new ForkContextExecutor();
        $pool = new AgentWorkerPool(executor: $executor);
        $executor->pool = $pool;

        // No engine at all: the pool path. The call degrades honestly.
        $task = new TaskTool($manager, $pool);
        $result = $task->execute($this->forkCall(['fork' => true]));

        self::assertStringStartsWith(
            '[fork inheritance was requested, but this launch has no session engine',
            $result->content(),
        );
        self::assertSame(
            [['role' => 'user', 'content' => 'Inherit this conversation and report']],
            $executor->seen[0]['messages'],
            'the no-engine fork carries nothing: the pool request keeps the plain shape',
        );
    }

    public function testPoolRequestsMergeInheritedRowsAheadOfTheTaskRow(): void
    {
        $executor = new ForkContextExecutor();
        $pool = new AgentWorkerPool(executor: $executor);
        $executor->pool = $pool;

        $agent = new SubAgent(
            id: 'carrier',
            agent: RosterAgent::named('coder'),
            task: 'report after inheriting',
        );
        $agent->inheritedContext = [new UserMessage('an inherited parent turn')];

        $results = \iterator_to_array($pool->executeAll([$agent], new CompleteRequest(model: 'm', messages: [])), false);

        self::assertSame('ok', $results[0]->output);
        $messages = $executor->seen[0]['messages'];
        self::assertCount(2, $messages);
        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertSame('an inherited parent turn', $messages[0]->content());
        self::assertSame(['role' => 'user', 'content' => 'report after inheriting'], $messages[1]);
    }

    public function testTheWindowCarriesTheLatestThirtyRowsThroughTheRealReader(): void
    {
        $rows = [Message::system('the parent system prompt must never ride')];
        for ($i = 1; $i <= 40; ++$i) {
            $rows[] = $i % 2 === 0
                ? Message::assistant('parent answer ' . $i)
                : Message::user('parent question ' . $i);
        }
        $this->store->saveTranscript('parent-sess', $rows);

        $inherited = ConversationFork::inheritedContext($this->forkManager(), 'parent-sess');

        self::assertCount(ConversationFork::MAX_INHERITED_MESSAGES, $inherited);
        self::assertInstanceOf(UserMessage::class, $inherited[0]);
        self::assertSame('parent question 11', $inherited[0]->content());
        self::assertSame('parent answer 40', $inherited[29]->content());
        foreach ($inherited as $row) {
            self::assertTrue(
                $row instanceof UserMessage || $row instanceof AssistantMessage,
                'text turns only, and system rows never ride',
            );
        }
    }

    public function testEveryMissingBindingAnswersWithAnEmptyContext(): void
    {
        $manager = $this->forkManager();

        self::assertSame([], ConversationFork::inheritedContext(null, 'parent-sess'));
        self::assertSame([], ConversationFork::inheritedContext($manager, null));
        self::assertSame([], ConversationFork::inheritedContext($manager, ''));
        self::assertSame(
            [],
            ConversationFork::inheritedContext(new AgentManager(new ScriptedProvider([]), new SkillRegistry()), 'parent-sess'),
            'a manager with no store bound inherits nothing',
        );
        self::assertSame(
            [],
            ConversationFork::inheritedContext($manager, 'no-such-session'),
            'an unknown session inherits nothing',
        );
    }

    public function testAForkAtTheDepthCapStillInheritsAndKeepsTaskOutOfTheChild(): void
    {
        $probe = self::forkProbe();
        $provider = new ScriptedProvider([new CompleteResponse(content: 'nothing more to delegate')]);
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: [$probe],
            toolUniverse: [$probe],
        );
        $manager->register(RosterAgent::named('coder', ['probe']));
        $store = $this->store;
        $manager->recordChildSessionsIn(static fn () => $store);

        $task = (new TaskTool($manager))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([$probe])->withSessionId('parent-sess'))
            ->withDelegationLimits(1, 8);

        $task->execute($this->forkCall(['fork' => true]));

        $request = $provider->requests[0];
        $toolNames = \array_map(
            static fn (Tool $tool): string => $tool->name(),
            $request->tools ?? [],
        );
        self::assertSame(['probe'], $toolNames, 'the depth cap law is untouched by fork');
        self::assertCount(4, self::forkTurns($request), 'and the inheritance still rode');
    }

    private function forkManager(): AgentManager
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('coder'));
        $store = $this->store;
        $manager->recordChildSessionsIn(static fn () => $store);

        return $manager;
    }

    private function forkEngineTask(AgentManager $manager, EngineBackend $engine): TaskTool
    {
        return (new TaskTool($manager))->withEngine($engine);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function forkCall(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Inherit the parent conversation',
            'prompt' => 'Inherit this conversation and report',
            'agent' => 'coder',
        ];
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function forkTurns(CompleteRequest $request): array
    {
        return \array_map(
            static function (mixed $entry): array {
                if ($entry instanceof TypedMessage) {
                    return [$entry->role(), $entry->content()];
                }

                return [(string) $entry['role'], (string) $entry['content']];
            },
            TurnContextBlock::strip($request->messages),
        );
    }

    private static function forkProbe(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'records context-inheriting calls';
            }

            /**
             * @return array<string, mixed>
             */
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            /**
             * @param array<string, mixed> $args
             */
            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probe ok');
            }
        };
    }
}

/**
 * Synchronous executor double: records every dispatch request (and the pool
 * snapshots at dispatch time) without forking a process, so ordering and
 * message-assembly pins need no timers (E646-clean).
 */
final class ForkContextExecutor implements ExecutorInterface
{
    /** @var list<array{id:string,messages:list<mixed>,queue:?int,active:?int}> */
    public array $seen = [];
    public ?AgentWorkerPool $pool = null;

    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        $this->seen[] = [
            'id' => $agent->id,
            'messages' => $request->messages,
            'queue' => $this->pool?->queueSize(),
            'active' => $this->pool?->activeCount(),
        ];

        return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'ok');
    }

    /**
     * @return \Generator<int,AgentResult>
     */
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
    {
        yield $this->execute($agent, $request);
    }

    public function cancel(string $agentId): void
    {
    }

    public function cancelAll(): void
    {
    }
}
