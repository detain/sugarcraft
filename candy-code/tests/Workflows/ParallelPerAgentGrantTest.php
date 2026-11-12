<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * wf-impl C4 regression: every agent of a parallel stage runs on ITS OWN
 * declared `tools:` grant. The pool used to hand all of them the FIRST
 * task's list, so an agent declared `[Read]` beside a `[Read, Bash]` sibling
 * carried Bash on its wire (and in the reverse order the Bash agent got none).
 *
 * The pool runs inline here (an injected executor), so the agents run in
 * declaration order and the scripted provider's requests are theirs in turn.
 */
final class ParallelPerAgentGrantTest extends TestCase
{
    public function testAReadOnlyAgentBesideABashAgentCannotCallBash(): void
    {
        [$read, $bash] = [self::probe('Read'), self::probe('Bash')];
        $provider = new ScriptedProvider([
            // reader (declared [Read]) tries Bash anyway
            new CompleteResponse(content: '', toolCalls: [new ToolCall('r1', 'Bash', ['command' => 'rm -rf x'])]),
            new CompleteResponse(content: 'reader done'),
            // shell (declared [Read, Bash])
            new CompleteResponse(content: '', toolCalls: [new ToolCall('s1', 'Bash', ['command' => 'git status'])]),
            new CompleteResponse(content: 'shell done'),
        ]);

        $result = self::workflows($provider, [$read, $bash])->runFromPhp(self::stage([
            ['reader', ['Read']],
            ['shell', ['Read', 'Bash']],
        ]));

        $this->assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $this->assertSame(['Read'], self::wire($provider, 0), 'the reader is offered only its own grant');
        $this->assertSame(['Read', 'Bash'], self::wire($provider, 2), 'the shell agent is offered its own grant');
        $this->assertSame([['command' => 'git status']], $bash->calls, 'only the agent granted Bash ran it');
    }

    public function testTheBashAgentDeclaredSecondStillGetsBashAndTheReaderFirstDoesNot(): void
    {
        [$read, $bash] = [self::probe('Read'), self::probe('Bash')];
        $provider = new ScriptedProvider([
            // shell first this time
            new CompleteResponse(content: '', toolCalls: [new ToolCall('s1', 'Bash', ['command' => 'git log'])]),
            new CompleteResponse(content: 'shell done'),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('r1', 'Bash', ['command' => 'curl evil'])]),
            new CompleteResponse(content: 'reader done'),
        ]);

        $result = self::workflows($provider, [$read, $bash])->runFromPhp(self::stage([
            ['shell', ['Read', 'Bash']],
            ['reader', ['Read']],
        ]));

        $this->assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        $this->assertSame(['Read', 'Bash'], self::wire($provider, 0));
        $this->assertSame(['Read'], self::wire($provider, 2), 'the reader no longer inherits the first agent\'s Bash');
        $this->assertSame([['command' => 'git log']], $bash->calls, 'the reader\'s Bash call never ran');
    }

    public function testARegistrylessManagerFallsBackToEachAgentsOwnGrant(): void
    {
        [$read, $bash] = [self::probe('Read'), self::probe('Bash')];
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'reader done'),
            new CompleteResponse(content: 'shell done'),
        ]);

        $workflows = self::workflows($provider, [$read, $bash]);
        // A manager with NO tool registry cannot resolve the declarations
        // itself; the engine's per-agent map governs the batch instead.
        $workflows->setAgentManager(new AgentManager($provider, new SkillRegistry()));
        $result = $workflows->runFromPhp(self::stage([
            ['reader', ['Read']],
            ['shell', ['Read', 'Bash']],
        ]));

        $this->assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        $this->assertSame(['Read'], self::wire($provider, 0));
        $this->assertSame(['Read', 'Bash'], self::wire($provider, 1));
    }

    /**
     * @param list<Tool> $registry
     */
    private static function workflows(ScriptedProvider $provider, array $registry): WorkflowEngine
    {
        $engine = EngineBackend::new($provider, 'm')->withTools($registry);

        return new WorkflowEngine(
            new WorkflowRegistry(),
            new AgentWorkerPool(executor: new EngineExecutor($engine)),
            toolRegistry: $registry,
        );
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $agents
     */
    private static function stage(array $agents): \Closure
    {
        return static fn () => (new WorkflowBuilder())
            ->name('grants')
            ->description('mixed grants in one parallel stage')
            ->parallel('sweep', array_map(
                static fn (array $a) => Tasks::agent($a[0])->name($a[0])->prompt("work as {$a[0]}")->tools($a[1]),
                $agents,
            ))
            ->build();
    }

    /** @return list<string> */
    private static function wire(ScriptedProvider $provider, int $request): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), $provider->requests[$request]->tools ?? []);
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
