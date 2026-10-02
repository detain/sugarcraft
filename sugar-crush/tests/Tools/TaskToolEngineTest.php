<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * The Task tool's ENGINE path: a delegated sub-agent is a whole agentic run
 * through the engine that is running the calling turn, not one completion.
 *
 * The regression behind this file was measured in a live session: the pool
 * path makes a single provider call that advertises the grant and executes
 * nothing, so every sub-agent whose first move was a tool call — ten audit
 * delegations in a row — came back "completed without any output text" in
 * seconds. Every test here therefore scripts a sub-agent that CALLS A TOOL
 * FIRST, and asserts on what the provider was actually sent, not only on the
 * returned string.
 */
final class TaskToolEngineTest extends TestCase
{
    private string $storeDir;

    private SuspendedDelegations $store;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_resume_' . bin2hex(random_bytes(6));
        $this->store = new SuspendedDelegations($this->storeDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testABoundTaskRunsTheSubAgentThroughAToolLoopUntilItReports(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', ['path' => 'candy-core'])]),
            new CompleteResponse(content: 'the audit report'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        $result = (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('the audit report', $result->content());
        $this->assertSame([['path' => 'candy-core']], $probe->calls, 'the sub-agent\'s tool call was executed');
        $this->assertCount(2, $provider->requests, 'one step for the tool call, one for the report');

        $first = $provider->requests[0];
        $this->assertSame(['probe'], self::toolNames($first), 'the preset grant, and no Task');
        $this->assertContains(['system', 'You are coder.'], self::turns($first));
        $this->assertContains(['user', 'Audit candy-core and report the findings'], self::turns($first));
        $this->assertContains('tool', array_column(self::turns($provider->requests[1]), 0), 'the tool result was fed back');
    }

    public function testTheSubAgentNeverInheritsTheTaskToolItself(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([new CompleteResponse(content: 'nothing to delegate')]);
        // No registry: the preset's grant resolves to "no narrowing", so the
        // sub-agent inherits the engine's own tools — minus Task.
        $manager = self::manager([], RosterAgent::named('coder'));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertSame(['probe'], self::toolNames($provider->requests[0]));
    }

    public function testASubAgentThatEndsWithoutAReportIsRefusedNamingTheStepCap(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine)->execute(self::call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('ended without a final report (step cap 1)', $result->content());
        $this->assertCount(1, $probe->calls, 'the work it did still happened');
    }

    public function testAStepCappedSubAgentReportsItsSummaryAndKeepsItsResumeId(): void
    {
        // WAVE_PLAN_2 §5: the capped run's engine makes one no-tools summary
        // request. Its answer is the report — and the run, still unfinished,
        // stays resumable instead of the summary silently costing it that.
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'done: probed once; remaining: everything else'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringStartsWith('done: probed once; remaining: everything else', $result->content());
        $this->assertStringContainsString('stopped at its step cap (1)', $result->content());
        $this->assertNull($provider->requests[1]->tools, 'the summary request offered no tools');
        $this->assertNotNull($this->store->load(self::resumeId($result->content())), 'the capped run was saved for a resume');
    }

    public function testTheSessionHookChainGovernsTheSubAgentsCalls(): void
    {
        // Named `Read` so the built-in ProtectFilesHook judges its file_path.
        $read = self::probe('Read');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Read', ['file_path' => '.env'])]),
            new CompleteResponse(content: 'could not read it'),
        ]);
        $manager = self::manager([$read], RosterAgent::named('coder', ['Read']));
        $engine = EngineBackend::new($provider, 'm')->withTools([$read]);

        $result = (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertSame('could not read it', $result->content());
        $this->assertSame([], $read->calls, 'ProtectFilesHook denied .env inside the sub-agent too');
        $toolTurns = array_values(array_filter(
            self::turns($provider->requests[1]),
            static fn (array $turn): bool => $turn[0] === 'tool',
        ));
        $this->assertStringContainsString('Hook denied', $toolTurns[0][1] ?? '', 'the sub-agent was told why');
    }

    public function testConcurrencyIsOfferedOnlyOnTheSelfBoundingEnginePath(): void
    {
        $manager = self::manager([], RosterAgent::named('coder'));
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm');

        $this->assertFalse((new TaskTool())->isParallelSafe());
        $this->assertFalse((new TaskTool($manager))->isParallelSafe(), 'the pool path stays a barrier');
        $this->assertTrue((new TaskTool($manager))->withEngine($engine)->isParallelSafe());
    }

    public function testTheRunningEngineBindsItselfIntoTheTaskToolEveryTurn(): void
    {
        // One scripted provider serves both levels, in call order: the caller
        // delegates, the sub-agent answers, the caller wraps up.
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_task', 'Task', self::call())]),
            new CompleteResponse(content: 'sub-agent report'),
            new CompleteResponse(content: 'all done'),
        ]);
        $manager = self::manager([], RosterAgent::named('coder'));
        $engine = EngineBackend::new($provider, 'm')->withTools([new TaskTool($manager)]);

        $reply = $engine->complete([Message::user('delegate the audit')]);

        $this->assertSame('all done', $reply->content);
        $this->assertCount(3, $provider->requests);
        $this->assertSame([], self::toolNames($provider->requests[1]), 'the sub-agent was not handed Task');
        $toolTurns = array_values(array_filter(
            self::turns($provider->requests[2]),
            static fn (array $turn): bool => $turn[0] === 'tool',
        ));
        $this->assertSame('sub-agent report', $toolTurns[0][1] ?? null, 'the caller received the sub-agent\'s report');
    }

    public function testTheEngineExposesItsToolsUnbound(): void
    {
        $task = new TaskTool(self::manager([], RosterAgent::named('coder')));
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')->withTools([$task]);

        $this->assertSame([$task], $engine->tools(), 'binding happens per turn, never on the stored list');
    }

    // =========================================================================
    // Resume — a run that ended without a report continues, it does not restart
    // =========================================================================

    public function testARunThatHitItsStepCapResumesWithItsOwnTranscript(): void
    {
        $probe = self::probe('probe');
        // The capped run's no-tools summary request (WAVE_PLAN_2 §5) comes
        // back empty, so the run is still reportless and refused with an id.
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', ['step' => 1])]),
            new CompleteResponse(content: ''),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $task = (new TaskTool($manager, suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([$probe]));

        $first = $task->execute(self::call());
        $id = self::resumeId($first->content());

        $provider->then(new CompleteResponse(content: 'the report, finished'));
        $resumed = $task->execute(self::call(['resume' => $id, 'prompt' => 'carry on and report']));

        $this->assertFalse($resumed->isError(), $resumed->content());
        $this->assertSame('the report, finished', $resumed->content());
        $this->assertCount(1, $probe->calls, 'the resumed run did not redo the finished step');

        $turns = self::turns($provider->requests[2]);
        $this->assertSame(['system', 'You are coder.'], $turns[0], 'the preset prompt was carried, not re-added');
        $this->assertSame(['user', 'Audit candy-core and report the findings'], $turns[1]);
        $this->assertSame('assistant', $turns[2][0]);
        $this->assertSame(['tool', 'probe ok'], $turns[3], 'the earlier tool result is in the resumed context');
        $this->assertSame('user', $turns[4][0]);
        $this->assertStringContainsString('whole budget of 1 tool steps', $turns[4][1], 'the summary exchange is part of the saved transcript');
        $this->assertSame(['user', 'carry on and report'], $turns[array_key_last($turns)]);
        $this->assertNotNull(
            $provider->requests[2]->messages[2]->toArray()['tool_calls'] ?? null,
            'the earlier assistant step kept its tool call across the disk round-trip',
        );
        $this->assertNull($this->store->load($id), 'a report clears the suspension');
    }

    public function testARunInterruptedPartWayResumesFromItsLastCompletedStep(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', ['step' => 1])]),
            new \RuntimeException('connection reset by peer'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe']));
        $task = (new TaskTool($manager, suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([$probe]));

        $first = $task->execute(self::call());

        $this->assertTrue($first->isError());
        $this->assertStringContainsString('failed: connection reset by peer', $first->content());
        $id = self::resumeId($first->content());

        $provider->then(new CompleteResponse(content: 'recovered report'));
        $resumed = $task->execute(self::call(['resume' => $id]));

        $this->assertSame('recovered report', $resumed->content());
        $this->assertSame(
            ['system', 'user', 'assistant', 'tool', 'user'],
            array_column(self::turns($provider->requests[2]), 0),
            'the completed step survived the failure; the failed call did not leave a half step behind',
        );
    }

    public function testRepeatedResumesKeepOneIdAndCountThemselves(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $task = (new TaskTool($manager, suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([$probe]));

        $id = self::resumeId($task->execute(self::call())->content());
        $second = $task->execute(self::call(['resume' => $id]));
        $third = $task->execute(self::call(['resume' => $id]));

        $this->assertSame($id, self::resumeId($second->content()));
        $this->assertStringContainsString('resumed 1 time)', $second->content());
        $this->assertSame($id, self::resumeId($third->content()));
        $this->assertStringContainsString('resumed 2 times)', $third->content());
    }

    public function testAnUnknownResumeIdSaysPlainlyThatItCannotBeResumed(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'unreachable')]);
        $task = (new TaskTool(self::manager([], RosterAgent::named('coder')), suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm'));

        foreach (['0123456789abcdef', '../../etc/passwd'] as $id) {
            $result = $task->execute(self::call(['resume' => $id]));

            $this->assertTrue($result->isError());
            $this->assertStringContainsString('CANNOT be resumed', $result->content());
        }
        $this->assertSame([], $provider->requests);
    }

    public function testAResumeMustNameTheAgentThatWasSuspended(): void
    {
        $id = $this->store->save('reviewer', [new UserMessage('the task')], 0);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'unreachable')]);
        $task = (new TaskTool(self::manager([], RosterAgent::named('coder')), suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm'));

        $result = $task->execute(self::call(['resume' => $id]));

        $this->assertStringContainsString('belongs to agent "reviewer", not "coder"', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testTheUnboundPoolPathRefusesToResume(): void
    {
        $result = (new TaskTool(self::manager([], RosterAgent::named('coder')), suspended: $this->store))
            ->execute(self::call(['resume' => '0123456789abcdef']));

        $this->assertStringContainsString('resume needs the engine-bound Task path', $result->content());
    }

    public function testAnUnresolvableGrantIsRefusedBeforeAnyProviderCall(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'unreachable')]);
        $manager = self::manager([self::probe('probe')], RosterAgent::named('coder', ['Reed']));
        $engine = EngineBackend::new($provider, 'm');

        $result = (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Error: Task refused', $result->content());
        $this->assertSame([], $provider->requests);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Audit candy-core',
            'prompt' => 'Audit candy-core and report the findings',
            'agent' => 'coder',
        ];
    }

    private static function resumeId(string $refusal): string
    {
        self::assertMatchesRegularExpression('/"resume": "([0-9a-f]{16})"/', $refusal, 'the refusal names a resume id');
        preg_match('/"resume": "([0-9a-f]{16})"/', $refusal, $m);

        return $m[1];
    }

    /**
     * @param list<Tool> $registry
     */
    private static function manager(array $registry, Agent $agent): AgentManager
    {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: $registry === [] ? null : $registry,
            toolUniverse: $registry === [] ? null : $registry,
        );
        $manager->register($agent);

        return $manager;
    }

    /**
     * @return list<string>
     */
    private static function toolNames(CompleteRequest $request): array
    {
        return array_map(static fn (Tool $tool): string => $tool->name(), $request->tools ?? []);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function turns(CompleteRequest $request): array
    {
        return array_map(
            static fn (TypedMessage $message): array => [$message->role(), $message->content()],
            $request->messages,
        );
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

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
                $id = (string) ($args['id'] ?? '');
                unset($args['id']);
                $this->calls[] = $args;

                return new ToolResult(toolCallId: $id, content: $this->name . ' ok');
            }
        };
    }
}
