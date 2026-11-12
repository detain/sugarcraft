<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * wf-impl P1-6: task keys `preset: <name>` (an agent preset from the roster
 * `Task` uses, merged under the task's own keys) and `effort:` (a closed set
 * riding SubAgent::$effort to the request's reasoning effort).
 */
final class StagePresetEffortTest extends TestCase
{
    // ------------------------------------------------------------------ loader

    public function testTheLoaderReadsPresetAndEffortOnEveryTaskSurface(): void
    {
        $workflow = (new WorkflowRegistry())->fromYamlString(<<<'YAML'
            name: p
            stages:
              - name: one
                preset: reviewer
                effort: low
                prompt: go
              - name: fan
                parallel: true
                agents:
                  - {type: a, preset: coder, effort: max, prompt: x}
              - name: per
                type: map
                from: {context: items}
                steps:
                  - {name: s, preset: reviewer, effort: xhigh, prompt: "{{item}}"}
            YAML);

        $one = $workflow->stages[0]['tasks'][0];
        self::assertSame('reviewer', $one->preset);
        self::assertSame(Effort::Low, $one->effort);
        self::assertSame('coder', $workflow->stages[1]['tasks'][0]->preset);
        self::assertSame(Effort::Max, $workflow->stages[1]['tasks'][0]->effort);
        self::assertSame(Effort::XHigh, $workflow->stages[2]['steps'][0]['tasks'][0]->effort);
    }

    public function testAnEffortOutsideTheClosedSetIsRefused(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('effort must be one of low, medium, high, xhigh, max, got "huge"');

        (new WorkflowRegistry())->fromYamlString("name: p\nstages:\n  - {name: one, prompt: go, effort: huge}\n");
    }

    public function testANonStringEffortAndAnEmptyPresetAreRefused(): void
    {
        try {
            (new WorkflowRegistry())->fromYamlString("name: p\nstages:\n  - {name: one, prompt: go, effort: 3}\n");
            self::fail('a numeric effort loaded');
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString('"effort" must be one of low, medium, high, xhigh, max, got int', $e->getMessage());
        }

        try {
            (new WorkflowRegistry())->fromYamlString("name: p\nstages:\n  - {name: one, prompt: go, preset: '  '}\n");
            self::fail('an empty preset loaded');
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString('"preset" must name an agent preset (e.g. reviewer), got an empty string', $e->getMessage());
        }
    }

    public function testTheBuilderHasParity(): void
    {
        $task = Tasks::agent('x')->prompt('p')->preset(' reviewer ')->effort('high')->build();
        self::assertSame('reviewer', $task->preset);
        self::assertSame(Effort::High, $task->effort);
        self::assertSame(Effort::Low, Tasks::agent('x')->effort(Effort::Low)->build()->effort);

        $this->expectException(\InvalidArgumentException::class);
        Tasks::agent('x')->effort('Huge');
    }

    public function testTheLinterMirrorsTheUnknownPresetRefusalWhenItKnowsTheRoster(): void
    {
        $workflow = (new WorkflowBuilder())->name('l')->description('d')
            ->stage('one', Tasks::agent('x')->prompt('p')->preset('nope'))
            ->build();

        $known = \SugarCraft\Code\Workflows\WorkflowLinter::lint($workflow, presetNames: ['reviewer', 'coder']);
        self::assertSame(1, $known->errorCount());
        self::assertStringContainsString('preset "nope" is not an agent preset this session knows (known: coder, reviewer)', $known->renderFindings());

        self::assertFalse(\SugarCraft\Code\Workflows\WorkflowLinter::lint($workflow)->hasErrors(), 'an unknown roster checks the spelling only');
        self::assertFalse(\SugarCraft\Code\Workflows\WorkflowLinter::lint($workflow, presetNames: ['nope'])->hasErrors());
    }

    // ------------------------------------------------------------------ engine

    public function testAnUnknownPresetRefusesTheRunWithNothingDispatched(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);
        $workflows = self::workflows($provider, [self::probe('Read')]);
        $workflows->setAgentRoster([self::preset('reviewer', 'You review.')]);

        $result = $workflows->runFromPhp(self::oneStage(Tasks::agent('x')->prompt('p')->preset('nope')));

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString(
            'Stage \'one\' preset "nope": unknown agent preset "nope" (known: reviewer); nothing was dispatched',
            (string) $result->firstFailure()?->error,
        );
        self::assertSame([], $provider->requests);
    }

    public function testAPresetWithNoRosterToResolveAgainstIsRefused(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);

        $result = self::workflows($provider, [])->runFromPhp(self::oneStage(Tasks::agent('x')->prompt('p')->preset('reviewer')));

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString('has no agent roster', (string) $result->firstFailure()?->error);
        self::assertSame([], $provider->requests);
    }

    public function testThePresetSuppliesPromptToolsEffortAndStepCap(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'reviewed')]);
        $workflows = self::workflows($provider, [self::probe('Read'), self::probe('Bash')]);
        $workflows->setAgentRoster([self::preset('reviewer', 'You are the reviewer.', ['Read'], Effort::High)]);

        $result = $workflows->runFromPhp(self::oneStage(Tasks::agent('label')->prompt('review it')->preset('reviewer')));

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertSame(['Read'], self::wire($provider, 0));
        self::assertSame('high', $provider->requests[0]->reasoningEffort);
        self::assertStringStartsWith('You are the reviewer.', self::systemTurn($provider, 0));
    }

    public function testTheTasksOwnKeysOverrideThePreset(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        $workflows = self::workflows($provider, [self::probe('Read'), self::probe('Bash')]);
        $workflows->setAgentRoster([self::preset('reviewer', 'You are the reviewer.', ['Read'], Effort::High)]);

        $result = $workflows->runFromPhp(self::oneStage(
            Tasks::agent('x')->prompt('p')->preset('reviewer')->tools(['Bash'])->effort('low'),
        ));

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertSame(['Bash'], self::wire($provider, 0));
        self::assertSame('low', $provider->requests[0]->reasoningEffort);
    }

    public function testAnEffortWithoutAPresetRidesTheRequestAndNoneLeavesItUntouched(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'a'), new CompleteResponse(content: 'b')]);

        $result = self::workflows($provider, [])->runFromPhp(static fn () => (new WorkflowBuilder())
            ->name('e')->description('d')
            ->stage('one', Tasks::agent('x')->prompt('p')->effort('medium'))
            ->stage('two', Tasks::agent('x')->prompt('q'))
            ->build());

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertSame('medium', $provider->requests[0]->reasoningEffort);
        self::assertNull($provider->requests[1]->reasoningEffort);
        self::assertStringNotContainsString('reviewer', self::systemTurn($provider, 1));
    }

    public function testParallelAgentsEachSpeakWithTheirOwnPresetBody(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'a'), new CompleteResponse(content: 'b')]);
        $workflows = self::workflows($provider, []);
        $workflows->setAgentRoster([self::preset('reviewer', 'You are the reviewer.'), self::preset('coder', 'You are the coder.')]);

        $result = $workflows->runFromPhp(static fn () => (new WorkflowBuilder())
            ->name('pp')->description('d')
            ->parallel('fan', [
                Tasks::agent('r')->name('r')->prompt('x')->preset('reviewer'),
                Tasks::agent('c')->name('c')->prompt('y')->preset('coder'),
            ])
            ->build());

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertStringStartsWith('You are the reviewer.', self::systemTurn($provider, 0));
        self::assertStringStartsWith('You are the coder.', self::systemTurn($provider, 1));
    }

    public function testPresetAndEffortAreStageIdentityAndAbsenceKeepsOldHashes(): void
    {
        $hash = new \ReflectionMethod(WorkflowEngine::class, 'stageInputHash');
        $stage = static fn (WorkflowTask $t): array => ['name' => 's', 'type' => 'stage', 'tasks' => [$t]];

        $plain = new WorkflowTask(agentType: 'x', prompt: 'p');
        $legacy = hash('sha256', (string) json_encode(
            ['type' => 'stage', 'tasks' => [['prompt' => 'p', 'model' => null, 'schema' => null]]],
            JSON_UNESCAPED_SLASHES,
        ));
        self::assertSame($legacy, $hash->invoke(null, $stage($plain), 'stage'), 'a task without preset/effort hashes as before');

        $withEffort = $hash->invoke(null, $stage($plain->with(['effort' => Effort::Low])), 'stage');
        $withPreset = $hash->invoke(null, $stage($plain->with(['preset' => 'reviewer'])), 'stage');
        self::assertNotSame($legacy, $withEffort);
        self::assertNotSame($legacy, $withPreset);
        self::assertNotSame($withEffort, $withPreset);
    }

    public function testThePresetsIsolationIsMergedUnlessTheTaskSetsItsOwn(): void
    {
        $merge = new \ReflectionMethod(WorkflowEngine::class, 'mergePreset');
        $preset = \SugarCraft\Code\Agents\StagePreset::fromAgent(self::preset('w', 'body', [], null, Isolation::Worktree, 'opus'));

        $merged = $merge->invoke(null, new WorkflowTask(agentType: 'x', prompt: 'p', preset: 'w'), $preset);
        self::assertSame(Isolation::Worktree, $merged->isolation);
        self::assertSame('opus', $merged->model);

        $own = $merge->invoke(null, new WorkflowTask(agentType: 'x', prompt: 'p', isolation: Isolation::None, model: 'm2', preset: 'w'), $preset);
        self::assertSame(Isolation::None, $own->isolation);
        self::assertSame('m2', $own->model);

        $inherit = $merge->invoke(null, new WorkflowTask(agentType: 'x', prompt: 'p', model: 'inherit', preset: 'w'), $preset);
        self::assertSame('opus', $inherit->model, '`model: inherit` defers to the preset\'s model');
    }

    public function testAStricterPresetModeStepCapAndDenylistRideTheStageAgent(): void
    {
        $seen = new \ArrayObject();
        $executor = new class ($seen) implements \SugarCraft\Code\Agents\ExecutorInterface {
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function execute(\SugarCraft\Code\Agents\SubAgent $agent, \SugarCraft\Code\Providers\CompleteRequest $request): \SugarCraft\Code\Agents\AgentResult
            {
                $this->seen[] = $agent;

                return new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'ok',
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function executeStream(\SugarCraft\Code\Agents\SubAgent $agent, \SugarCraft\Code\Providers\CompleteRequest $request): \Generator
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
        $gate = new \SugarCraft\Code\Permissions\PermissionGate(\SugarCraft\Code\Permissions\PermissionMode::Default);
        $workflows = new WorkflowEngine(new WorkflowRegistry(), new AgentWorkerPool(executor: $executor), permissionGate: $gate);
        $planner = new Agent(
            name: 'planner',
            description: 'plans',
            prompt: 'Plan only.',
            model: '',
            provider: 'x',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
            disallowedTools: ['Write'],
            permissionMode: \SugarCraft\Code\Permissions\PermissionMode::Plan,
            maxTurns: 4,
            inheritsModel: true,
        );
        $workflows->setAgentRoster([$planner]);

        $result = $workflows->runFromPhp(static fn () => (new WorkflowBuilder())
            ->name('m')->description('d')
            ->stage('a', Tasks::agent('x')->prompt('p')->preset('planner'))
            ->stage('b', Tasks::agent('x')->prompt('q'))
            ->build());

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        [$a, $b] = [$seen[0], $seen[1]];
        self::assertSame(\SugarCraft\Code\Permissions\PermissionMode::Plan, $a->permissionGate?->mode());
        self::assertSame(\SugarCraft\Code\Permissions\PermissionMode::Plan, $a->agent->permissionMode);
        self::assertSame(['Write'], $a->agent->disallowedTools);
        self::assertSame(4, $a->agent->maxTurns);
        self::assertSame('Plan only.', $a->agent->prompt);
        self::assertSame($gate, $b->permissionGate, 'a stage without a preset carries the session gate itself');
        self::assertSame('', $b->agent->prompt);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<Tool> $registry
     */
    private static function workflows(ScriptedProvider $provider, array $registry): WorkflowEngine
    {
        return new WorkflowEngine(
            new WorkflowRegistry(),
            new AgentWorkerPool(executor: new EngineExecutor(EngineBackend::new($provider, 'm')->withTools($registry))),
            toolRegistry: $registry,
        );
    }

    /** @param list<string> $tools */
    private static function preset(string $name, string $body, array $tools = [], ?Effort $effort = null, ?Isolation $isolation = null, ?string $model = null): Agent
    {
        return new Agent(
            name: $name,
            description: "the {$name}",
            prompt: $body,
            model: $model ?? '',
            provider: 'scripted',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: false,
            effort: $effort,
            isolation: $isolation,
            inheritsModel: $model === null,
        );
    }

    private static function oneStage(\SugarCraft\Code\Workflows\TaskBuilder $task): \Closure
    {
        return static fn () => (new WorkflowBuilder())->name('ps')->description('d')->stage('one', $task)->build();
    }

    /** @return list<string> */
    private static function wire(ScriptedProvider $provider, int $request): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), $provider->requests[$request]->tools ?? []);
    }

    private static function systemTurn(ScriptedProvider $provider, int $request): string
    {
        foreach ($provider->requests[$request]->messages as $message) {
            if ($message instanceof Message && $message->role() === 'system') {
                return $message->content();
            }
        }

        return '';
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
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
            }
        };
    }
}
