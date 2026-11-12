<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * wf-impl P0-4: the tool lints before it dispatches (errors refuse with zero
 * spend, warnings ride the headline), `dryRun` stops after the lint, and the
 * report ends with a JSON document whose schema'd `data` is never clipped and
 * whose prose elision says where the elided bytes went.
 */
final class WorkflowToolLintAndResultTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            @rmdir($root);
        }
    }

    public function testALintErrorRefusesTheRunWithNothingDispatched(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);

        $result = $this->tool($provider)->execute([
            'plan' => "stages:\n  - name: a\n    prompt: \"Use {{ghost.output}}\"\n",
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Error: the plan was not run: lint found 1 error, 0 warnings (nothing was dispatched).', $result->content());
        $this->assertStringContainsString('- error [a] {{ghost.output}} names no stage or pipeline step', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testLintWarningsRideTheHeadlineAndTheRunStillHappens(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);

        $result = $this->tool($provider)->execute(['plan' => "stages:\n  - name: a\n    prompt: \"Look at {{scope}}\"\n"]);

        $this->assertFalse($result->isError(), $result->content());
        $lines = explode("\n", $result->content());
        $this->assertStringStartsWith("Workflow 'plan' completed", $lines[0], 'the human headline stays first');
        $this->assertSame('1 lint warning:', $lines[1]);
        $this->assertStringStartsWith('- warning [a] {{scope}} matches no run-context seed', $lines[2]);
        $this->assertCount(1, $provider->requests);
    }

    public function testAContextSeedSilencesTheUnresolvedTokenWarning(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);

        $result = $this->tool($provider)->execute(['plan' => "stages:\n  - name: a\n    prompt: \"Look at {{scope}}\"\n", 'context' => ['scope' => 'src/']]);

        $this->assertStringNotContainsString('lint warning', $result->content());
    }

    public function testADryRunReturnsThePlanRowsAndDispatchesNothing(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);

        $result = $this->tool($provider)->execute([
            'dryRun' => true,
            'plan' => "name: survey\nstages:\n  - name: look\n    prompt: \"read {{scope}}\"\n    tools: [Read]\n  - name: wide\n    parallel: true\n    agents:\n      - {type: x, name: p, prompt: one}\n      - {type: x, name: q, prompt: two}\n",
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringStartsWith("Dry run of workflow 'survey' (no agents dispatched): 2 stages.", $result->content());
        $this->assertMatchesRegularExpression('/look\s*\|\s*stage/', $result->content());
        $this->assertMatchesRegularExpression('/wide\s*\|\s*parallel/', $result->content());
        $this->assertStringContainsString('- warning [look] {{scope}}', $result->content());
        $this->assertStringContainsString('Lint: 0 error(s), 2 warning(s)', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testADryRunOfABrokenPlanIsAnErrorThatSaysTheRunWouldBeRefused(): void
    {
        $provider = new ScriptedProvider([]);

        $result = $this->tool($provider)->execute(['dryRun' => true, 'plan' => "stages:\n  - name: a\n    prompt: x\n    tools: [Write]\n"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('a real run would be refused', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testANonBooleanDryRunIsRefused(): void
    {
        $result = $this->tool(new ScriptedProvider([]))->execute(['dryRun' => 'yes', 'plan' => "stages:\n  - name: a\n    prompt: x\n"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('"dryRun" must be true or false', $result->content());
    }

    public function testASchemadStagesOutputIsDecodedDataAndNeverClipped(): void
    {
        $items = [];
        for ($i = 0; $i < 600; $i++) {
            $items[] = ['id' => "item-{$i}", 'note' => str_repeat('x', 20)];
        }
        $json = json_encode(['items' => $items], JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(WorkflowTool::STAGE_OUTPUT_MAX_BYTES, strlen($json));

        $result = $this->tool(self::schemaCapable([new CompleteResponse(content: $json)]))->execute([
            'plan' => "stages:\n  - name: find\n    prompt: list them\n    outputSchema: {type: object}\n",
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertArrayNotHasKey('output', $row, 'data replaces the prose copy');
        $this->assertCount(600, $row['data']['items']);
        $this->assertSame('item-599', $row['data']['items'][599]['id']);
        $this->assertStringNotContainsString('elided', $result->content());
    }

    public function testASchemadStageWhoseOutputDoesNotDecodeFallsBackToProse(): void
    {
        // Two schema'd agents: each answer is JSON, but the stage output is
        // both joined by a newline — not one document, so it stays prose.
        // A Bash probe keeps them inline (P2-1: an agent that may ask is not
        // forked), so the one in-memory script answers both, in order.
        $result = $this->tool(self::schemaCapable([new CompleteResponse(content: '{"a":1}'), new CompleteResponse(content: '{"b":2}')]), ['Read', 'Bash'])->execute([
            'plan' => "stages:\n  - name: find\n    parallel: true\n    agents:\n      - {type: x, name: p, prompt: one, outputSchema: {type: object}}\n      - {type: x, name: q, prompt: two, outputSchema: {type: object}}\n",
        ]);

        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertSame('completed', $row['status'], $result->content());
        $this->assertArrayNotHasKey('data', $row);
        $this->assertStringContainsString('{"a":1}', $row['output']);
        $this->assertStringContainsString('{"b":2}', $row['output']);
    }

    public function testAStageWithOneUnschemadTaskCarriesNoData(): void
    {
        $result = $this->tool(self::schemaCapable([new CompleteResponse(content: '{"a":1}')]))->execute([
            'plan' => "stages:\n  - name: two\n    tasks:\n      - {prompt: one, outputSchema: {type: object}}\n      - {prompt: two}\n",
        ]);

        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertArrayNotHasKey('data', $row);
        $this->assertArrayHasKey('output', $row);
    }

    public function testAnUnschemadJsonLookingReplyStaysProse(): void
    {
        $result = $this->tool(new ScriptedProvider([new CompleteResponse(content: '{"a":1}')]))->execute([
            'plan' => "stages:\n  - name: a\n    prompt: x\n",
        ]);

        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertArrayNotHasKey('data', $row, 'data is only for stages that declared a schema');
        $this->assertSame('{"a":1}', $row['output']);
    }

    public function testACapturedStagesElisionMarkerNamesTheCaptureFile(): void
    {
        $long = 'HEAD-' . str_repeat('y', WorkflowTool::STAGE_OUTPUT_MAX_BYTES * 2) . '-TAIL';

        $result = $this->tool(new ScriptedProvider([new CompleteResponse(content: $long)]))->execute([
            'plan' => "stages:\n  - name: big\n    prompt: x\n    capture: true\n",
        ]);

        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertArrayHasKey('capture', $row);
        $this->assertFileExists($row['capture']);
        $this->assertSame($long, file_get_contents($row['capture']), 'the capture holds every byte the report elided');
        $this->assertStringContainsString('bytes elided; the full output is in ' . $row['capture'], $row['output']);
        @unlink($row['capture']);
    }

    public function testTheResultDocumentShapeAndTheRunIdSeam(): void
    {
        $result = $this->tool(new ScriptedProvider([new CompleteResponse(content: 'ok')]))->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n"]);

        $document = WorkflowToolTest::document($result->content());
        $this->assertSame('completed', $document['status']);
        $this->assertArrayHasKey('runId', $document);
        $this->assertNull($document['runId'], 'null until tool runs leave a resumable record');
        $this->assertSame(['name', 'status', 'error', 'attempts', 'tokens', 'cost', 'output'], array_keys($document['stages'][0]));
        $this->assertNull($document['stages'][0]['error']);
    }

    /** @param list<string> $tools probe tool names the session carries */
    private function tool(ProviderInterface $provider, array $tools = ['Read']): WorkflowTool
    {
        $dir = sys_get_temp_dir() . '/crush-workflow-tool-lint-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->roots[] = $dir;

        return (new WorkflowTool($dir))->withEngine(EngineBackend::new($provider, 'm')->withTools(array_map(self::probe(...), $tools)));
    }

    /**
     * ScriptedProvider declares no JSON-schema support, and a schema'd task
     * is refused up front on such a provider; this wrapper says yes.
     *
     * @param list<CompleteResponse> $script
     */
    private static function schemaCapable(array $script): ProviderInterface
    {
        return new class (new ScriptedProvider($script)) implements ProviderInterface {
            public function __construct(private ScriptedProvider $inner)
            {
            }

            public function name(): string
            {
                return $this->inner->name();
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsDocuments(): bool
            {
                return false;
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
                return $this->inner->completeStream($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return $this->inner->embeddings($request);
            }
        };
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
                return new ToolResult((string) ($args['id'] ?? ''), 'ok');
            }
        };
    }
}
