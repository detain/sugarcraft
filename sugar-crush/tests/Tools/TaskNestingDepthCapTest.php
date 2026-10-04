<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\DelegationSlot;
use SugarCraft\Crush\Agents\DelegationSlots;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.7-3: delegation nests — a sub-agent that inherits the whole tool
 * set keeps `Task` until the depth cap, where the leaf gets none — and one
 * session runs at most N delegated runs at once across every process, refusing
 * (never queueing) the one past the cap.
 */
final class TaskNestingDepthCapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!function_exists('posix_getuid')) {
            $this->markTestSkipped('the seat directory is named by uid');
        }
        $this->dir = sys_get_temp_dir() . '/crush-nest-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testASubAgentDelegatesOneLevelDownAndTheLeafGetsNoTask(): void
    {
        $provider = new ScriptedProvider([
            // Level 1, "lead": delegates.
            new CompleteResponse(content: '', toolCalls: [new ToolCall('n1', 'Task', ['agent' => 'worker', 'prompt' => 'count the files', 'description' => 'Count files'])]),
            // Level 2, "worker": the leaf under a cap of 2.
            new CompleteResponse(content: 'there are 3 files'),
            // Level 1 again, with the worker's report.
            new CompleteResponse(content: 'lead: the worker counted 3 files'),
        ]);
        $frames = [];
        $task = $this->task($provider, maxDepth: 2, emitter: static function (SubAgentActivity $a) use (&$frames): void {
            $frames[] = $a;
        });

        $result = $task->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'survey the repo', 'description' => 'Survey']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('lead: the worker counted 3 files', $result->content());
        $this->assertSame(['Grep', 'Task'], self::toolNames($provider->requests[0]), 'level 1 keeps Task');
        $this->assertSame(['Grep'], self::toolNames($provider->requests[1]), 'the level-2 leaf has none under a depth cap of 2');
        $this->assertStringContainsString('there are 3 files', serialize($provider->requests[2]->messages), 'the nested report came back to its delegator');

        $started = array_values(array_filter($frames, static fn (SubAgentActivity $a): bool => $a->op === SubAgentActivity::OP_STARTED));
        $this->assertSame(['lead', 'worker'], array_map(static fn (SubAgentActivity $a): string => $a->name, $started), 'the nested run reaches the parent\'s emitter');
        $this->assertNull($started[0]->parentAgentId);
        $this->assertSame($started[0]->id, $started[1]->parentAgentId, 'and names the run that delegated it');
    }

    public function testTheDefaultCapIsThreeLevelsDeep(): void
    {
        $delegate = static fn (string $to): CompleteResponse => new CompleteResponse(content: '', toolCalls: [new ToolCall('d_' . $to, 'Task', ['agent' => $to, 'prompt' => 'go deeper', 'description' => 'Deeper'])]);
        $provider = new ScriptedProvider([
            $delegate('worker'),                       // level 1 → 2
            $delegate('worker'),                       // level 2 → 3
            new CompleteResponse(content: 'leaf'),     // level 3
            new CompleteResponse(content: 'two'),      // level 2
            new CompleteResponse(content: 'one'),      // level 1
        ]);

        $result = $this->task($provider)->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'p', 'description' => 'd']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(3, TaskTool::MAX_DELEGATION_DEPTH);
        $this->assertSame([['Grep', 'Task'], ['Grep', 'Task'], ['Grep']], array_map(self::toolNames(...), array_slice($provider->requests, 0, 3)));
    }

    public function testARunHoldsOneSeatForItsLifeAndGivesItBack(): void
    {
        $seen = [];
        $probe = $this->probe(function () use (&$seen): void {
            $seen[] = DelegationSlots::held('sess-a', 8, $this->dir);
        });
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Grep', [])]),
            new CompleteResponse(content: 'done'),
        ]);

        $result = $this->task($provider, probe: $probe)->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'p', 'description' => 'd']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([1], $seen, 'one seat while it ran');
        $this->assertSame(0, DelegationSlots::held('sess-a', 8, $this->dir), 'none once it returned');
    }

    public function testARunPastTheSessionsCapIsRefusedNotQueued(): void
    {
        $held = DelegationSlots::acquire('sess-a', 2, $this->dir);
        $also = DelegationSlots::acquire('sess-a', 2, $this->dir);
        $this->assertInstanceOf(DelegationSlot::class, $held);
        $this->assertInstanceOf(DelegationSlot::class, $also);
        $this->assertFalse(DelegationSlots::acquire('sess-a', 2, $this->dir));
        $this->assertInstanceOf(DelegationSlot::class, DelegationSlots::acquire('sess-b', 2, $this->dir), 'another session has its own seats');

        $provider = new ScriptedProvider([new CompleteResponse(content: 'never asked')]);
        $result = $this->task($provider, maxConcurrent: 2)->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'p', 'description' => 'd']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('2 sub-agents are already running in this session', $result->content());
        $this->assertStringContainsString('Do not retry this call right away', $result->content());
        $this->assertSame([], $provider->requests, 'refused before anything was billed');

        $also->release();
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ran')]);
        $this->assertFalse($this->task($provider, maxConcurrent: 2)->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'p', 'description' => 'd'])->isError(), 'a freed seat is taken');
        unset($held);
    }

    public function testASeatHeldInAnotherProcessCountsAndDiesWithIt(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork() unavailable');
        }
        $ready = $this->dir . '/ready';
        $pid = pcntl_fork();
        if ($pid === 0) {
            $seat = DelegationSlots::acquire('sess-a', 1, $this->dir);
            touch($ready);
            usleep($seat instanceof DelegationSlot ? 1_500_000 : 0);
            posix_kill(getmypid(), SIGKILL);
        }
        $deadline = microtime(true) + 5.0;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $refused = $this->task(new ScriptedProvider([new CompleteResponse(content: 'x')]), maxConcurrent: 1)
            ->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'p', 'description' => 'd']);
        $this->assertTrue($refused->isError(), 'a member in another process holds the only seat');

        pcntl_waitpid($pid, $status);
        $ran = $this->task(new ScriptedProvider([new CompleteResponse(content: 'ran')]), maxConcurrent: 1)
            ->execute(['id' => 'top', 'agent' => 'lead', 'prompt' => 'p', 'description' => 'd']);
        $this->assertFalse($ran->isError(), 'a killed holder frees its seat: ' . $ran->content());
    }

    public function testLimitsBelowOneAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TaskTool())->withDelegationLimits(0, 8);
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function task(ScriptedProvider $provider, int $maxDepth = TaskTool::MAX_DELEGATION_DEPTH, int $maxConcurrent = 8, ?\Closure $emitter = null, ?Tool $probe = null): TaskTool
    {
        $probe ??= $this->probe(null);
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$probe], toolUniverse: [$probe]);
        // No `tools:` grant: both inherit the session's whole set.
        $manager->register(RosterAgent::named('lead', maxTurns: 5));
        $manager->register(RosterAgent::named('worker', maxTurns: 5));

        $task = (new TaskTool($manager))
            ->withDelegationLimits($maxDepth, $maxConcurrent)
            ->withDelegationSlotRoot($this->dir)
            ->withTranscriptRoot($this->dir . '/subagents');
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withSessionId('sess-a')->withTools([$probe, $task]);

        return $task->withEngine($engine, null, $emitter);
    }

    /** @return list<string> */
    private static function toolNames(CompleteRequest $request): array
    {
        $names = array_map(static fn (Tool $tool): string => $tool->name(), $request->tools ?? []);
        sort($names);

        return $names;
    }

    private function probe(?\Closure $onCall): Tool
    {
        return new class ($onCall) implements Tool {
            public function __construct(private ?\Closure $onCall)
            {
            }

            public function name(): string
            {
                return 'Grep';
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
                if ($this->onCall !== null) {
                    ($this->onCall)();
                }

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
