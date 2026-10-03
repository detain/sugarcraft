<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Audit B4: a Task sub-agent's spend reaches the CALLING turn — its
 * {@see Message::$usage} (so the session tracker and `/cost` see it), its
 * mid-turn spend-cap check, and the sub-agent's own cap, which must start
 * from what the calling turn had already spent.
 *
 * Before the fix the run's usage was written only onto the {@see SubAgent}
 * row, which on the engine path lives in a forked child and dies with it,
 * and {@see ToolResult} had no usage field at all: a turn that delegated
 * fifty billed steps reported only its own two.
 *
 * Every provider here is ONE routing closure rather than an ordered script,
 * because parallel Tasks fork and each fork holds its own copy of a script's
 * cursor; the route is decided from the request itself — a request that
 * offers `Task` is the caller, one that does not is a sub-agent.
 */
final class TaskSpendPropagationTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_spend_' . getmypid() . '_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testASequentialTasksDollarsAndTokensReachTheCallingTurnsUsage(): void
    {
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [new ToolCall('call_task', 'Task', self::taskArgs())])
                : self::reply('all done', 10, 0.10),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 100, 1.0, [new ToolCall('call_p', 'probe', [])])
                : self::reply('the report', 100, 1.0),
        );
        $engine = EngineBackend::new($provider, 'm')->withTools([self::probeTool(), new TaskTool(self::roster())]);

        $reply = $engine->complete([Message::user('delegate it')]);

        $this->assertSame('all done', $reply->content);
        $this->assertCount(4, $provider->requests, 'caller, two sub-agent steps, caller');
        $this->assertNotNull($reply->usage);
        $this->assertEqualsWithDelta(2.20, $reply->usage->costUsd, 1e-9, 'the sub-agent\'s $2 is billed to the turn');
        $this->assertSame(220, $reply->usage->totalTokens, 'and so are its tokens');
    }

    public function testParallelTasksForkedBelowTheTurnStillBillTheCallingTurn(): void
    {
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [
                    new ToolCall('call_a', 'Task', self::taskArgs()),
                    new ToolCall('call_b', 'Task', self::taskArgs()),
                ])
                : self::reply('all done', 10, 0.10),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::reply('report from pid ' . getmypid(), 100, 1.0),
        );
        $engine = EngineBackend::new($provider, 'm')->withTools([new TaskTool(self::roster())]);

        $reply = $engine->complete([Message::user('delegate twice')]);

        $this->assertSame('all done', $reply->content);
        $this->assertEqualsWithDelta(2.20, $reply->usage?->costUsd ?? 0.0, 1e-9, 'both forked runs\' dollars crossed the fork IPC');
        $this->assertSame(220, $reply->usage?->totalTokens);

        if (function_exists('pcntl_fork')) {
            // The routing proves the dollars; this proves the path: the runs
            // really were forked, so the IPC codec — not shared memory — is
            // what carried them.
            $reports = self::toolTurnContents(end($provider->requests));
            $this->assertCount(2, $reports);
            foreach ($reports as $report) {
                $this->assertStringNotContainsString('report from pid ' . getmypid(), $report, 'the Task ran in a forked child');
            }
        }
    }

    public function testTheSubAgentsCapStartsFromWhatTheCallingTurnHadAlreadySpent(): void
    {
        // The caller's first step costs $1.00; the sub-agent's first $0.60.
        // Judged from the session's starting $0 the sub-agent would see $0.60
        // and run on; judged from the CALLING turn's $1.00 it is at $1.60,
        // over the $1.50 cap, and must not make a second call.
        $subCalls = 0;
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 1.00, [new ToolCall('call_task', 'Task', self::taskArgs())])
                : self::reply('all done', 10, 0.0),
            subAgent: static function (CompleteRequest $r) use (&$subCalls): CompleteResponse {
                $subCalls++;

                return self::reply('still looking', 100, 0.60, [new ToolCall('call_p' . $subCalls, 'probe', [])]);
            },
        );
        $events = [];
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([self::probeTool(), new TaskTool(self::roster(), suspended: new SuspendedDelegations($this->storeDir))])
            ->withSpendCap(1.50, 0.0);

        $reply = $engine->complete([Message::user('delegate it')], onEvent: static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertSame(1, $subCalls, 'the sub-agent was stopped after one call, not left to run to its step cap');
        $this->assertStringContainsString('stopped by the session spend cap', self::lastTaskRefusal($events), 'refused naming the cap, not passed off as a report');

        // And the CALLER's boundary now counts the sub-agent: $1.00 + $0.60.
        $breaches = array_values(array_filter($events, static fn (object $e): bool => $e instanceof SpendCapBreached));
        $this->assertCount(1, $breaches, 'the caller stopped too, at its own boundary');
        $this->assertEqualsWithDelta(1.60, $breaches[0]->spentUsd, 1e-9);
        $this->assertEqualsWithDelta(1.60, $reply->usage?->costUsd ?? 0.0, 1e-9);
        $this->assertCount(2, $provider->requests, 'no caller call after the breach');
    }

    public function testACapOfHalfADollarStopsADollarAStepSubAgentAndTheCallerCountsIt(): void
    {
        // The audit's own shape: $1 a step against a $0.50 cap. Before the
        // fix the sub-agent's breach event hit a TaskTool listener typed
        // ToolStarted|ToolFinished and surfaced as a TypeError "failure",
        // its dollar was dropped, and the caller ran on.
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [new ToolCall('call_task', 'Task', self::taskArgs())])
                : self::reply('all done', 10, 0.10),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::reply('', 100, 1.0, [new ToolCall('call_p', 'probe', [])]),
        );
        $task = new TaskTool(self::roster(), suspended: new SuspendedDelegations($this->storeDir));
        $engine = EngineBackend::new($provider, 'm')->withTools([self::probeTool(), $task])->withSpendCap(0.50, 0.0);
        $events = [];

        $reply = $engine->complete([Message::user('delegate it')], onEvent: static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertCount(2, $provider->requests, 'one caller call, one sub-agent call, then nothing');
        $refusal = self::lastTaskRefusal($events);
        $this->assertStringContainsString('stopped by the session spend cap after 1 provider call', $refusal);
        $this->assertStringNotContainsString('TypeError', $refusal);
        $this->assertEqualsWithDelta(1.10, $reply->usage?->costUsd ?? 0.0, 1e-9);
    }

    public function testDelegatedSpendLeavesTheCallersPromptBucketsAlone(): void
    {
        // E17's calibration pairs promptTokens() against the prompt THIS
        // conversation sent. The sub-agent's prompt is another conversation.
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 50, 0.10, [new ToolCall('call_task', 'Task', self::taskArgs())], Usage::new(50, 0.10, 30, 20, 0, 0))
                : self::reply('all done', 50, 0.10, null, Usage::new(50, 0.10, 30, 20, 0, 0)),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::reply('the report', 9000, 2.0, null, Usage::new(9000, 2.0, 5000, 1000, 2000, 1000, 400)),
        );
        $engine = EngineBackend::new($provider, 'm')->withTools([new TaskTool(self::roster())]);

        $usage = $engine->complete([Message::user('delegate it')])->usage;

        $this->assertNotNull($usage);
        $this->assertSame(60, $usage->promptTokens(), 'the caller\'s own two prompts, nothing of the sub-agent\'s');
        $this->assertSame([60, 40, 0, 0, null], [
            $usage->inputTokens, $usage->outputTokens, $usage->cacheReadTokens, $usage->cacheCreationTokens, $usage->reasoningTokens,
        ]);
        $this->assertSame(9100, $usage->totalTokens, 'the spend side does count the run');
        $this->assertEqualsWithDelta(2.20, $usage->costUsd, 1e-9);
    }

    public function testAnUnpricedSubAgentMarksTheCallingTurnUnpriced(): void
    {
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [new ToolCall('call_task', 'Task', self::taskArgs())])
                : self::reply('all done', 10, 0.10),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::reply('the report', 100, 0.0, null, Usage::new(100, 0.0, unpricedModel: 'mystery-1')),
        );
        $engine = EngineBackend::new($provider, 'm')->withTools([new TaskTool(self::roster())]);

        $usage = $engine->complete([Message::user('delegate it')])->usage;

        $this->assertSame('mystery-1', $usage?->unpricedModel, 'the session\'s $ figure is a lower bound now, and Chat must say so');
    }

    public function testAnInterruptedRunStillBillsTheStepsItCompleted(): void
    {
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [new ToolCall('call_task', 'Task', self::taskArgs())])
                : self::reply('all done', 10, 0.10),
            subAgent: static function (CompleteRequest $r): CompleteResponse {
                if (self::toolTurnCount($r) > 0) {
                    throw new \RuntimeException('connection reset mid-run');
                }

                return self::reply('', 100, 1.0, [new ToolCall('call_p', 'probe', [])]);
            },
        );
        $events = [];
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([self::probeTool(), new TaskTool(self::roster(), suspended: new SuspendedDelegations($this->storeDir))]);

        $reply = $engine->complete([Message::user('delegate it')], onEvent: static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertStringContainsString('connection reset mid-run', self::lastTaskRefusal($events));
        $this->assertEqualsWithDelta(1.20, $reply->usage?->costUsd ?? 0.0, 1e-9, 'the completed $1 step was billed though the run failed');
    }

    public function testAResumedRunBillsOnlyItsOwnStepsNotTheSavedOnes(): void
    {
        // First run: one $1 step, then the step cap and its $0.50 no-tools
        // summary request (WAVE_PLAN_2 §5), which comes back empty so the run
        // is still reportless; second (resume): one new $1 step, then a
        // provider failure. The saved transcript's steps were billed by the
        // FIRST Task call and must not be billed again.
        $provider = new ScriptedProvider([
            self::reply('', 100, 1.0, [new ToolCall('call_1', 'probe', [])]),
            self::reply('', 50, 0.5),
            self::reply('', 100, 1.0, [new ToolCall('call_2', 'probe', [])]),
            new \RuntimeException('provider went away'),
        ]);
        $manager = self::roster(maxTurns: 1);
        $store = new SuspendedDelegations($this->storeDir);
        $task = (new TaskTool($manager, suspended: $store))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([self::probeTool()]));

        $first = $task->execute(self::taskArgs());
        $this->assertTrue($first->isError());
        $this->assertEqualsWithDelta(1.5, $first->usage()?->costUsd ?? 0.0, 1e-9, 'a step-capped run without a report still billed, its summary request included');
        preg_match('/"resume": "([0-9a-f]{16})"/', $first->content(), $m);
        $this->assertArrayHasKey(1, $m, 'the refusal names a resume id');

        $manager2 = self::roster(maxTurns: 5);
        $resumed = (new TaskTool($manager2, suspended: $store))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([self::probeTool()]))
            ->execute(self::taskArgs(['resume' => $m[1]]));

        $this->assertTrue($resumed->isError());
        $this->assertStringContainsString('provider went away', $resumed->content());
        $this->assertEqualsWithDelta(1.0, $resumed->usage()?->costUsd ?? 0.0, 1e-9, 'only the resumed run\'s own step');
    }

    public function testTheCallingTurnMarksTheSubAgentsTokensAsItsDelegatedShare(): void
    {
        // B4-rem(iii)'s seam: the turn's totalTokens counts the sub-agent's
        // run (spend), and the share says how much of it was not this
        // conversation's own, for the readers that treat it as a size.
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [new ToolCall('call_task', 'Task', self::taskArgs())])
                : self::reply('all done', 10, 0.10),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::reply('the report', 900, 1.0),
        );
        $engine = EngineBackend::new($provider, 'm')->withTools([new TaskTool(self::roster())]);

        $usage = $engine->complete([Message::user('delegate it')])->usage;

        $this->assertSame(920, $usage?->totalTokens);
        $this->assertSame(900, $usage?->delegatedTokens, 'the sub-agent\'s run, marked as delegated');
        $this->assertSame(20, $usage?->ownTokens(), 'the caller\'s own two calls');
    }

    public function testParallelSiblingsSeeEachOthersSpendSoTheBatchStopsAtTheCap(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }

        // Audit B4-rem(i). Two Tasks in one message fork side by side; each
        // sub-agent step costs $0.60 against a $1.00 cap, after the caller's
        // own $0.10. The tool each sub-agent calls waits long enough for the
        // sibling's first step to be billed, so at the first boundary each run
        // sees $0.10 + its own $0.60 + its sibling's $0.60 = $1.30 and stops.
        // Blind to each other (before the fix) each saw only $0.70, ran a
        // second step, and the batch spent $2.40 against the $0.90 left.
        $wait = self::namedTool('wait', static function (): string {
            usleep(750_000);

            return 'waited';
        });
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [
                    new ToolCall('call_a', 'Task', self::taskArgs()),
                    new ToolCall('call_b', 'Task', self::taskArgs()),
                ])
                : self::reply('all done', 10, 0.0),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => self::reply(
                'still looking',
                100,
                0.60,
                [new ToolCall('call_w' . self::toolTurnCount($r), 'wait', [])],
            ),
        );
        $task = new TaskTool(self::roster(5, $wait), suspended: new SuspendedDelegations($this->storeDir));
        $engine = EngineBackend::new($provider, 'm')->withTools([$task])->withSpendCap(1.00, 0.0);
        $events = [];

        $reply = $engine->complete([Message::user('delegate twice')], onEvent: static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $refusals = self::taskResults($events);
        $this->assertCount(2, $refusals);
        foreach ($refusals as $refusal) {
            $this->assertStringContainsString(
                'stopped by the session spend cap after 1 provider call',
                $refusal,
                'each sibling counted the other\'s step and stopped after its own first',
            );
        }
        $this->assertEqualsWithDelta(1.30, $reply->usage?->costUsd ?? 0.0, 1e-9, 'one step each, not two');
    }

    public function testATaskWhoseForkedChildDiesStillBillsTheStepsItRecorded(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }

        // Audit B4-rem(ii). One of two parallel Tasks bills a $1.00 step and
        // then its forked child is SIGKILLed mid-run, so it never writes a
        // result. Before the fix that dollar vanished with the child; the
        // step was recorded on the group's spend ledger as it was billed, and
        // the parent now bills it from there.
        $testPid = getmypid();
        $die = self::namedTool('die', static function () use ($testPid): string {
            if (getmypid() !== $testPid) {
                posix_kill(getmypid(), SIGKILL);
            }

            return 'not reached in a forked child';
        });
        $provider = self::routed(
            caller: static fn (CompleteRequest $r): CompleteResponse => self::toolTurnCount($r) === 0
                ? self::reply('', 10, 0.10, [
                    new ToolCall('call_dies', 'Task', self::taskArgs(['prompt' => 'crash please'])),
                    new ToolCall('call_ok', 'Task', self::taskArgs()),
                ])
                : self::reply('all done', 10, 0.10),
            subAgent: static fn (CompleteRequest $r): CompleteResponse => str_contains(self::userText($r), 'crash please')
                ? self::reply('', 100, 1.0, [new ToolCall('call_die', 'die', [])])
                : self::reply('the report', 50, 0.50),
        );
        $engine = EngineBackend::new($provider, 'm')->withTools([new TaskTool(self::roster(5, $die))]);
        $events = [];

        $reply = $engine->complete([Message::user('delegate twice')], onEvent: static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $results = self::taskResults($events);
        $this->assertCount(2, $results);
        $this->assertStringContainsString('produced no result', $results[0], 'the first Task\'s child really died');
        $this->assertSame(\SugarCraft\Crush\Context\DelegatedOutputFence::wrap('the report'), $results[1]);
        $this->assertEqualsWithDelta(1.70, $reply->usage?->costUsd ?? 0.0, 1e-9, 'the dead run\'s $1 is billed with everything else');
        $this->assertSame(170, $reply->usage?->totalTokens);
    }

    public function testThePoolPathCarriesTheWorkersSpend(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'pooled report', tokensUsed: 77, costUsd: 0.33);
            }

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
        };
        $task = new TaskTool(self::roster(), new AgentWorkerPool(maxConcurrent: 1, executor: $executor));

        $result = $task->execute(self::taskArgs());

        $this->assertSame(\SugarCraft\Crush\Context\DelegatedOutputFence::wrap('pooled report'), $result->content());
        $this->assertSame(77, $result->usage()?->totalTokens);
        $this->assertEqualsWithDelta(0.33, $result->usage()?->costUsd ?? 0.0, 1e-9);
    }

    // =========================================================================

    /**
     * @param \Closure(CompleteRequest): CompleteResponse $caller
     * @param \Closure(CompleteRequest): CompleteResponse $subAgent
     */
    private static function routed(\Closure $caller, \Closure $subAgent): ScriptedProvider
    {
        return new ScriptedProvider([
            static function (CompleteRequest $request) use ($caller, $subAgent): CompleteResponse {
                $offersTask = in_array('Task', array_map(static fn (Tool $t): string => $t->name(), $request->tools ?? []), true);

                return $offersTask ? $caller($request) : $subAgent($request);
            },
        ]);
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private static function reply(string $content, int $tokens, float $cost, ?array $toolCalls = null, ?Usage $usage = null): CompleteResponse
    {
        return new CompleteResponse(content: $content, toolCalls: $toolCalls, tokensUsed: $tokens, costUsd: $cost, usage: $usage);
    }

    private static function toolTurnCount(CompleteRequest $request): int
    {
        return count(self::toolTurnContents($request));
    }

    /**
     * @return list<string>
     */
    private static function toolTurnContents(CompleteRequest $request): array
    {
        $contents = [];
        foreach ($request->messages as $message) {
            if ($message instanceof TypedMessage && $message->role() === 'tool') {
                $contents[] = $message->content();
            }
        }

        return $contents;
    }

    /**
     * @param list<object> $events
     */
    private static function lastTaskRefusal(array $events): string
    {
        $last = '';
        foreach ($events as $event) {
            if ($event instanceof \SugarCraft\Crush\Events\ToolFinished && $event->toolName === 'Task') {
                $last = $event->result->content();
            }
        }

        return $last;
    }

    private static function roster(?int $maxTurns = 5, Tool ...$extra): AgentManager
    {
        $tools = [self::probeTool(), ...$extra];
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: $tools, toolUniverse: $tools);
        $manager->register(RosterAgent::named(
            'coder',
            array_map(static fn (Tool $tool): string => $tool->name(), $tools),
            maxTurns: $maxTurns,
        ));

        return $manager;
    }

    /**
     * Every Task call's final result text, in the order they finished.
     *
     * @param list<object> $events
     *
     * @return list<string>
     */
    private static function taskResults(array $events): array
    {
        $results = [];
        foreach ($events as $event) {
            if ($event instanceof \SugarCraft\Crush\Events\ToolFinished && $event->toolName === 'Task') {
                $results[] = $event->result->content();
            }
        }

        return $results;
    }

    /** The text of every user turn in $request, joined — where a sub-agent's task prompt sits. */
    private static function userText(CompleteRequest $request): string
    {
        $text = '';
        foreach ($request->messages as $message) {
            if ($message instanceof TypedMessage && $message->role() === 'user') {
                $text .= $message->content() . "\n";
            }
        }

        return $text;
    }

    /**
     * @param \Closure(): string $run
     */
    private static function namedTool(string $name, \Closure $run): Tool
    {
        return new class ($name, $run) implements Tool {
            public function __construct(private string $toolName, private \Closure $run)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: ($this->run)());
            }
        };
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function taskArgs(array $overrides = []): array
    {
        return $overrides + ['description' => 'Audit candy-core', 'prompt' => 'Audit candy-core', 'agent' => 'coder'];
    }

    private static function probeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'answers ok';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probe ok');
            }
        };
    }
}
