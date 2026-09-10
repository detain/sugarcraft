<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * E641: a workflow task declares tools by NAME; a {@see CompleteRequest} must
 * carry {@see Tool} objects, because every provider calls `->name()` on each
 * entry or type-hints its formatter parameter as {@see Tool}. These tests pin
 * the resolution at the WorkflowEngine boundary for every stage type, plus the
 * `[]`-vs-null normalization and the fail-loud refusal of unknown names.
 */
final class WorkflowToolsHandoffTest extends TestCase
{
    private WorkflowRegistry $registry;
    private AgentWorkerPool $pool;
    private ExecutorInterface $mockExecutor;

    /** @var list<CompleteRequest> every request the engine handed to the executor */
    private array $capturedRequests = [];

    protected function setUp(): void
    {
        $this->registry = new WorkflowRegistry();

        $this->capturedRequests = [];
        $this->mockExecutor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();
        $this->mockExecutor
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request): AgentResult {
                $this->capturedRequests[] = $request;

                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'ok',
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            });

        $this->pool = new AgentWorkerPool(5, $this->mockExecutor);
    }

    /**
     * A registry of fake Tools with the real built-ins' NAMES, so declarations
     * like ['Read','Bash'] resolve exactly as they would against
     * Bootstrap::tools() without dragging in its filesystem side effects.
     *
     * @param list<string> $names
     * @param-out list<Tool> $names
     * @return list<Tool>
     */
    private function registryOf(array $names): array
    {
        return array_map(
            static fn (string $name): Tool => new FakeWorkflowTool($name),
            $names,
        );
    }

    private function engineWithRegistry(?array $toolRegistry): WorkflowEngine
    {
        return new WorkflowEngine(
            $this->registry,
            $this->pool,
            toolRegistry: $toolRegistry,
        );
    }

    // =========================================================================
    // Typed objects at every CompleteRequest site
    // =========================================================================

    public function testAStageRequestCarriesToolObjectsInRegistryOrder(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read', 'Bash']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('typed-stage')
                ->description('Declares tools in non-registry order, with a duplicate')
                ->stage('work', Tasks::agent('coder')->prompt('Go')->tools(['Bash', 'Read', 'Bash']))
                ->build(),
        );

        $result = $engine->run('typed-stage', []);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $this->capturedRequests);
        $tools = $this->capturedRequests[0]->tools;
        $this->assertIsArray($tools);
        // Registry order, deduped by construction: Read, Bash — not the
        // declaration's Bash, Read, Bash.
        $this->assertCount(2, $tools);
        $this->assertContainsOnlyInstancesOf(Tool::class, $tools);
        $this->assertSame(['Read', 'Bash'], array_map(static fn (Tool $t): string => $t->name(), $tools));
    }

    public function testAPipelineStageRequestCarriesToolObjects(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read', 'Bash']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('typed-pipeline')
                ->description('Nested stage declares a tool')
                ->pipeline('chain', [
                    Tasks::agent('step-one')->prompt('Fetch')->tools(['Bash']),
                    Tasks::agent('step-two')->prompt('Transform {{prevResult}}')->tools(['Read']),
                ])
                ->build(),
        );

        $result = $engine->run('typed-pipeline', []);

        $this->assertTrue($result->isSuccess(), (string) $result->stageResults[0]->error);
        $this->assertNotEmpty($this->capturedRequests);
        foreach ($this->capturedRequests as $request) {
            $this->assertIsArray($request->tools);
            $this->assertContainsOnlyInstancesOf(Tool::class, $request->tools);
        }
    }

    public function testVerificationStageResolvesToolObjectsForTaskAndVerifier(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read', 'Bash']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('typed-verification')
                ->description('Task and verifier each declare a tool')
                ->withVerification(
                    'verify',
                    Tasks::agent('coder')->prompt('Do it')->tools(['Read']),
                    Tasks::agent('reviewer')->prompt('Check {{prevResult}}')->tools(['Bash']),
                )
                ->build(),
        );

        $result = $engine->run('typed-verification', []);

        $this->assertTrue($result->isSuccess(), (string) $result->stageResults[0]->error);
        $this->assertCount(2, $this->capturedRequests);
        $this->assertSame(
            [['Read'], ['Bash']],
            array_map(
                static fn (CompleteRequest $r): array => array_map(
                    static fn (Tool $t): string => $t->name(),
                    (array) $r->tools,
                ),
                $this->capturedRequests,
            ),
        );
    }

    public function testAParallelStageDefaultRequestCarriesToolObjects(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read', 'Bash']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('typed-parallel')
                ->description('Two parallel agents declaring tools')
                ->parallel('fan', [
                    Tasks::agent('coder')->name('reader')->prompt('Read it')->tools(['Read']),
                    Tasks::agent('coder')->name('shell')->prompt('Run it')->tools(['Bash']),
                ])
                ->build(),
        );

        $result = $engine->run('typed-parallel', []);

        $this->assertTrue($result->isSuccess(), (string) $result->stageResults[0]->error);
        $this->assertNotEmpty($this->capturedRequests);
        foreach ($this->capturedRequests as $request) {
            $this->assertIsArray($request->tools);
            $this->assertContainsOnlyInstancesOf(Tool::class, $request->tools);
        }
    }

    /**
     * E641 fixup: the build-loop resolution runs over EVERY parallel task,
     * not just the one whose list becomes the stage's default request. An
     * unresolvable name on task 2 is a refusal at build time, and "refused
     * stages dispatch nothing" holds — the throw fires before executeAll()
     * is reached, so even task 1, whose own grant is fine, never dispatches.
     */
    public function testAParallelTaskBeyondTheFirstWithAnUnresolvableNameRefusesTheWholeStage(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read', 'Bash']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('unknown-not-first')
                ->description('Task 1 resolves, task 2 names a tool the registry does not have')
                ->parallel('fan', [
                    Tasks::agent('coder')->name('fine')->prompt('Read')->tools(['Read']),
                    Tasks::agent('coder')->name('broken')->prompt('Typo')->tools(['Reed']),
                ])
                ->build(),
        );

        $this->mockExecutor->expects($this->never())->method('execute');

        $result = $engine->run('unknown-not-first', []);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $error = (string) $result->stageResults[0]->error;
        $this->assertStringContainsString('Reed', $error);
        $this->assertStringContainsString('does not contain', $error);
    }

    // =========================================================================
    // Fail loud: an unknown name never reaches a provider
    // =========================================================================

    public function testAnUnknownToolNameFailsTheStageWithANamedErrorNotAProviderFatal(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('unknown-tool')
                ->description('Declares a tool the registry does not have')
                ->stage('work', Tasks::agent('coder')->prompt('Go')->tools(['Reed']))
                ->build(),
        );

        // The refusal fires while the stage builds its request — nothing is
        // dispatched, so no provider (and no `->name()` fatal) ever sees it.
        $this->mockExecutor->expects($this->never())->method('execute');

        $result = $engine->run('unknown-tool', []);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $error = (string) $result->stageResults[0]->error;
        $this->assertStringContainsString('Reed', $error);
        $this->assertStringContainsString('does not contain', $error);
    }

    public function testAnEmptyRegistryRefusesAnyDeclaration(): void
    {
        $engine = $this->engineWithRegistry([]);

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('empty-registry')
                ->description('A registry that exists and offers nothing')
                ->stage('work', Tasks::agent('coder')->prompt('Go')->tools(['Bash']))
                ->build(),
        );

        $result = $engine->run('empty-registry', []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Bash', (string) $result->stageResults[0]->error);
    }

    public function testANonStringDeclarationIsRefusedNotSilentlyDropped(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read']));

        // The PHP DSL can build `->tools([42])`; the YAML loader cannot.
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('int-declaration')
                ->description('Declares a tool that is not a name')
                ->stage('work', Tasks::agent('coder')->prompt('Go')->tools([42]))
                ->build(),
        );

        $result = $engine->run('int-declaration', []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('not a non-empty tool name', (string) $result->stageResults[0]->error);
    }

    // =========================================================================
    // The []-vs-null normalization
    // =========================================================================

    public function testAnUndeclaredTaskSendsNullNotNullArray(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read', 'Bash']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('undeclared')
                ->description('No tools() call at all — WorkflowTask::$tools defaults to []')
                ->stage('work', Tasks::agent('coder')->prompt('Go'))
                ->build(),
        );

        $result = $engine->run('undeclared', []);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $this->capturedRequests);
        // Pinned semantics: `[]` is "says nothing about tools", and every
        // provider gates its tool block on `!== null`, so the empty
        // declaration must travel as NULL — an empty `tools: []` wire
        // parameter is a different (OpenAI-rejected) request.
        $this->assertNull($this->capturedRequests[0]->tools);
    }

    public function testAnExplicitlyEmptyToolsListSendsNull(): void
    {
        $engine = $this->engineWithRegistry($this->registryOf(['Read']));

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('explicit-empty')
                ->description('->tools([]) — same reading as no call')
                ->stage('work', Tasks::agent('coder')->prompt('Go')->tools([]))
                ->build(),
        );

        $result = $engine->run('explicit-empty', []);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($this->capturedRequests[0]->tools);
    }

    public function testWithoutARegistryADeclarationTravelsAsNullNeverAsStrings(): void
    {
        // Every pre-wiring construction site (Bootstrap until its one-line
        // change lands, every test double) constructs the engine with no
        // registry. The E641 shape — strings inside CompleteRequest::$tools —
        // must be structurally impossible there too: null, not the names.
        $engine = new WorkflowEngine($this->registry, $this->pool);

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('no-registry')
                ->description('Declares tools on an engine with no registry')
                ->stage('work', Tasks::agent('coder')->prompt('Go')->tools(['Bash']))
                ->build(),
        );

        $result = $engine->run('no-registry', []);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($this->capturedRequests[0]->tools);
    }
}

/**
 * A registry-resolvable Tool with no side effects: name fixed, execute() a
 * refusal. Lives in this file only — production code must not be able to
 * select it.
 */
final class FakeWorkflowTool implements Tool
{
    public function __construct(private readonly string $toolName) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'fake tool ' . $this->toolName;
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $args): ToolResult
    {
        throw new \LogicException('FakeWorkflowTool is never executed.');
    }
}
