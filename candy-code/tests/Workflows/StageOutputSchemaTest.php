<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\TaskBuilder;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowTask;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * G3: a stage's `outputSchema` rides the SAME terminal-turn leg headless `-p
 * --json-schema` rides (EngineBackend::withJsonSchema → the one newRuntime()
 * construction site → Runtime::jsonSchemaFor), so a stage agent's settled
 * answer IS the structured JSON a driver can parse. Absent, the request is
 * untouched and the stage reads byte-identical to before; a schema that
 * cannot be decoded fails CLOSED where it is declared, and a provider that
 * cannot honour it refuses the run rather than billing a structured answer
 * that arrives unconstrained.
 */
final class StageOutputSchemaTest extends TestCase
{
    private string $tempDir;

    /** @var list<SubAgent> */
    private array $dispatched = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_schema_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        foreach (glob($this->tempDir . '/workflows/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir . '/workflows');
        @rmdir($this->tempDir);
    }

    // ------------------------------------------------------------------ builder boundary

    public function testADecodedSchemaMapPassesThroughTheBuilderUntouched(): void
    {
        $schema = ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']]];

        $task = (new TaskBuilder())->agent('coder')->prompt('check')->outputSchema($schema)->build();

        self::assertSame($schema, $task->outputSchema, 'a map is already parsed — it must not be re-encoded or re-keyed');
    }

    public function testAnInlineJsonStringDecodesAtBuildTime(): void
    {
        $task = (new TaskBuilder())->agent('coder')->prompt('check')->outputSchema('{"type":"object"}')->build();

        self::assertSame(['type' => 'object'], $task->outputSchema);
    }

    public function testAFilePathIsReadAtBuildTime(): void
    {
        $path = $this->schemaFile('{"type":"string"}');

        $task = (new TaskBuilder())->agent('coder')->prompt('check')->outputSchema($path)->build();

        self::assertSame(['type' => 'string'], $task->outputSchema);
    }

    public function testAMalformedInlineJsonFailsClosedAtBuildTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        (new TaskBuilder())->agent('coder')->prompt('check')->outputSchema('{oops')->build();
    }

    public function testAListSchemaFailsClosedAtBuildTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a JSON object/');

        (new TaskBuilder())->agent('coder')->prompt('check')->outputSchema([1, 2, 3])->build();
    }

    public function testAnUnreadableSchemaPathFailsClosedAtBuildTime(): void
    {
        $missing = $this->tempDir . '/no-such-schema.json';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no-such-schema\.json/');

        (new TaskBuilder())->agent('coder')->prompt('check')->outputSchema($missing)->build();
    }

    // ------------------------------------------------------------------ wire shape

    public function testTheSubAgentWireCarriesTheSchemaAlongsideItsSiblingFields(): void
    {
        $agent = $this->stageAgent(outputSchema: ['type' => 'object']);

        self::assertSame(['type' => 'object'], $agent->toArray()['output_schema']);
    }

    // ------------------------------------------------------------------ engine executor legs

    public function testTheSchemaRidesTheTerminalRequestAndTheReplyIsTheJson(): void
    {
        $schema = ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']], 'required' => ['verdict']];
        $provider = $this->schemaCapable(new ScriptedProvider([
            new CompleteResponse(content: '{"verdict":"pass"}'),
        ]));
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute($this->stageAgent(outputSchema: $schema), $this->request());

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('{"verdict":"pass"}', $result->output, 'the stage result text IS the structured JSON');
        self::assertCount(1, $provider->inner->requests);
        self::assertSame($schema, $provider->inner->requests[0]->jsonSchema, 'the request carries the decoded schema, exactly as the -p run stamps it');
    }

    public function testAnAbsentSchemaLeavesTheRequestByteIdenticalToToday(): void
    {
        $provider = $this->schemaCapable(new ScriptedProvider([
            new CompleteResponse(content: 'plain report'),
        ]));
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute($this->stageAgent(), $this->request());

        self::assertSame(AgentStatus::Completed, $result->status);
        self::assertSame('plain report', $result->output, 'no disclosure sentence rides a schemaless run');
        self::assertNull($provider->inner->requests[0]->jsonSchema, 'the leg is never entered when no task declares a schema');
    }

    public function testAProviderWithoutTheCapabilityRefusesTheRunLoudly(): void
    {
        // Bare ScriptedProvider: supportsJsonSchema() is false by design.
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never asked')]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute($this->stageAgent(outputSchema: ['type' => 'object']), $this->request());

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertStringContainsString('cannot constrain the answer to a schema', (string) $result->error?->getMessage());
        self::assertStringContainsString('was not started', (string) $result->error?->getMessage());
        self::assertSame([], $provider->requests, 'a schema the provider cannot honour is refused before a single billable call');
    }

    public function testTheSettledReplyDisclosesWhenToolsAteTheTerminalTurn(): void
    {
        $probe = $this->probe();
        $provider = $this->schemaCapable(new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'second pass'),
        ]));
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm')->withTools([$probe]));

        $result = $executor->execute($this->stageAgent(outputSchema: ['type' => 'object']), $this->request(tools: [$probe]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame(
            "second pass\n\n[the reply was not constrained to the output schema because the model ran tools before answering]",
            $result->output,
            'the honesty leg mirrors headless `json_schema_applied: false` — the driver reads it in-band',
        );
        self::assertSame(['type' => 'object'], $provider->inner->requests[0]->jsonSchema, 'the opening request carries it');
        self::assertNull($provider->inner->requests[1]->jsonSchema, 'mid-loop the schema steps aside — same position gate the -p run uses');
    }

    // ------------------------------------------------------------------ engine threading

    public function testEveryStageDispatchCarriesItsOwnTaskSchema(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('threaded')
                ->stage('work', [
                    Tasks::agent('coder')->prompt('first')->outputSchema(['type' => 'object']),
                    Tasks::agent('coder')->prompt('second')->outputSchema(['type' => 'string']),
                    Tasks::agent('coder')->prompt('third'),
                ])
                ->build(),
        );

        $result = $this->engine($registry)->run('threaded');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(
            [['type' => 'object'], ['type' => 'string'], null],
            array_map(static fn (SubAgent $agent): ?array => $agent->outputSchema, $this->dispatched),
            'each schema reaches its own SubAgent; the unset task stays null',
        );
    }

    public function testAParallelStageCarriesEachAgentsSchemaSeparately(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('fanout')
                ->parallel('work', [
                    Tasks::agent('coder')->prompt('left')->outputSchema(['type' => 'object']),
                    Tasks::agent('coder')->prompt('right'),
                ])
                ->build(),
        );

        $result = $this->engine($registry)->run('fanout');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertCount(2, $this->dispatched);
        $byTask = [];
        foreach ($this->dispatched as $agent) {
            $byTask[$agent->task] = $agent->outputSchema;
        }
        self::assertSame(['left' => ['type' => 'object'], 'right' => null], $byTask);
    }

    // ------------------------------------------------------------------ YAML forms

    public function testTheYamlFormsCarryTheSchemaIntoTheTaskDto(): void
    {
        $fileSchema = $this->schemaFile('{"type":"number"}');
        $yaml = <<< "YAML"
        name: yaml-carries
        description: every spelling of the stage schema
        stages:
          - name: single
            agent: coder
            prompt: one
            outputSchema:
              type: object
          - name: spelled
            agent: coder
            prompt: two
            outputSchema: '{"type":"boolean"}'
          - name: file-backed
            agent: coder
            prompt: three
            outputSchema: {$fileSchema}
          - name: fanned
            parallel: true
            agents:
              - name: left
                prompt: four
                outputSchema:
                  type: string
              - name: right
                prompt: five
          - name: listed
            tasks:
              - agent: coder
                prompt: six
                outputSchema:
                  type: integer
        YAML;

        $workflow = (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'yaml-carries');

        self::assertSame(
            ['type' => 'object'],
            $this->taskAt($workflow, 0, 0)->outputSchema,
            'a native YAML map is the schema itself',
        );
        self::assertSame(
            ['type' => 'boolean'],
            $this->taskAt($workflow, 1, 0)->outputSchema,
            'an inline JSON string decodes exactly like the -p flag',
        );
        self::assertSame(
            ['type' => 'number'],
            $this->taskAt($workflow, 2, 0)->outputSchema,
            'a path is read and decoded at the boundary',
        );
        self::assertSame(
            ['type' => 'string'],
            $this->taskAt($workflow, 3, 0)->outputSchema,
            'parallel agents carry their own schema',
        );
        self::assertNull($this->taskAt($workflow, 3, 1)->outputSchema, 'a sibling without one stays null');
        self::assertSame(
            ['type' => 'integer'],
            $this->taskAt($workflow, 4, 0)->outputSchema,
            'task-list entries carry their own schema',
        );
    }

    public function testMalformedStageSchemasFailClosedAtLoadTime(): void
    {
        $cases = [
            'a top-level sequence is not a schema' => "outputSchema:\n      - one\n      - two",
            'a scalar is not a schema' => 'outputSchema: 7',
            'malformed inline JSON cannot decode' => "outputSchema: '{oops'",
            'an unreadable path is refused' => "outputSchema: {$this->tempDir}/absent.json",
        ];

        foreach ($cases as $why => $line) {
            $yaml = "name: closed\n"
                . "description: {$why}\n"
                . "stages:\n"
                . "  - name: work\n"
                . "    agent: coder\n"
                . "    prompt: check\n"
                . "    {$line}\n";

            $failed = false;
            try {
                (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'closed');
            } catch (WorkflowLoadException $refusal) {
                $failed = true;
                self::assertStringContainsString('outputSchema', $refusal->getMessage(), $why);
            }
            self::assertTrue($failed, $why . ' — a malformed schema must never load');
        }
    }

    public function testTheStageTaskCollisionGuardCoversTheSchemaKey(): void
    {
        $yaml = "name: collide\n"
            . "description: the key belongs on each task\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    outputSchema:\n"
            . "      type: object\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: check\n";

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/"outputSchema" belongs on each task/');

        (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'collide');
    }

    public function testTheEmptyMapSchemaPassesThroughBothBoundaries(): void
    {
        // NIT-2 (wf-g2g3-r1): the headless `{}` pass-through is pinned in the
        // Cli family; this pins the SAME empty map through the two workflow
        // boundaries — an inline JSON string at build(), a quoted YAML scalar
        // at load(). An empty schema object is a legal ask, never a missing one.
        $task = (new TaskBuilder())->agent('coder')->prompt('say it small')->outputSchema('{}')->build();
        self::assertSame([], $task->outputSchema, 'the inline "{}" decodes to the empty map at build()');

        $workflow = (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString(
            "name: empty-map\n"
            . "stages:\n"
            . "  - name: verdict\n"
            . "    agent: coder\n"
            . "    prompt: answer\n"
            . "    outputSchema: '{}'\n",
            'empty-map',
        );
        self::assertSame([], $this->taskAt($workflow, 0, 0)->outputSchema, 'the quoted YAML string decodes to the same empty map');
    }

    // ------------------------------------------------------------------ helpers

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->dispatched[] = $subAgent;

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'OUT[' . $subAgent->task . ']',
            error: null,
            tokensUsed: 5,
            costUsd: 0.25,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function engine(WorkflowRegistry $registry): WorkflowEngine
    {
        return new WorkflowEngine($registry, new AgentWorkerPool(1, $this->executor()));
    }

    private function executor(): ExecutorInterface
    {
        return new class ($this) implements ExecutorInterface {
            public function __construct(private readonly StageOutputSchemaTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatch($subAgent);
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield $this->execute($subAgent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };
    }

    /**
     * The registry builds its task lists on ->build(), so stage tasks are
     * WorkflowTask DTOs already — the G3 legs are observable directly.
     */
    private function taskAt(Workflow $workflow, int $stageIndex, int $taskIndex): WorkflowTask
    {
        return $workflow->stages[$stageIndex]['tasks'][$taskIndex];
    }

    private function schemaFile(string $body): string
    {
        $path = $this->tempDir . '/schema-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, $body);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function stageAgent(?array $outputSchema = null): SubAgent
    {
        return new SubAgent(
            id: 'stage-' . bin2hex(random_bytes(4)),
            agent: RosterAgent::named('coder'),
            task: 'check the lib',
            outputSchema: $outputSchema,
        );
    }

    private function request(?array $tools = null): CompleteRequest
    {
        return new CompleteRequest(
            model: 'm',
            messages: [['role' => 'user', 'content' => 'check the lib']],
            tools: $tools,
        );
    }

    /**
     * ScriptedProvider is final and honestly reports NO schema capability;
     * this decorator forwards every call to the inner provider and flips
     * only that one answer — the shape of a real schema-capable backend.
     */
    private function schemaCapable(ScriptedProvider $inner): ProviderInterface
    {
        return new class ($inner) implements ProviderInterface {
            public function __construct(public readonly ScriptedProvider $inner)
            {
            }

            public function name(): string
            {
                return $this->inner->name();
            }

            public function supportsStreaming(): bool
            {
                return $this->inner->supportsStreaming();
            }

            public function supportsFunctionCalling(): bool
            {
                return $this->inner->supportsFunctionCalling();
            }

            public function supportsVision(): bool
            {
                return $this->inner->supportsVision();
            }

            public function supportsDocuments(): bool
            {
                return $this->inner->supportsDocuments();
            }

            public function supportsJsonSchema(): bool
            {
                return true;
            }

            public function contextWindow(): int
            {
                return $this->inner->contextWindow();
            }

            public function costPer1kTokens(string $model, string $direction): ?float
            {
                return $this->inner->costPer1kTokens($model, $direction);
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return $this->inner->complete($request);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield from $this->inner->completeStream($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return $this->inner->embeddings($request);
            }
        };
    }

    private function probe(): Tool
    {
        return new class () implements Tool {
            public int $calls = 0;

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'counts its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}
