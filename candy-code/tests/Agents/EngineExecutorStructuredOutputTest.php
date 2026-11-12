<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\StructuredOutputTool;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
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

/**
 * workflow-update P0-2: a sub-agent that declares an outputSchema answers
 * through a per-run StructuredOutput tool call, validated against the schema,
 * whatever tools it ran first — and P1-6's `effort` plumbing on the same
 * executor.
 */
final class EngineExecutorStructuredOutputTest extends TestCase
{
    private const VERDICT = [
        'type' => 'object',
        'properties' => [
            'verdict' => ['type' => 'string', 'enum' => ['pass', 'fail']],
            'notes' => ['type' => 'string'],
        ],
        'required' => ['verdict'],
    ];

    public function testAToolUsingRunEndsOnItsAcceptedCallAndTheOutputIsTheJson(): void
    {
        $probe = $this->probe();
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'reading first', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c2', StructuredOutputTool::NAME, ['verdict' => 'pass', 'notes' => 'a/b ü'])]),
            new CompleteResponse(content: 'must never be asked for'),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([$probe]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('{"verdict":"pass","notes":"a/b ü"}', $result->output, 'the output is the validated document, nothing appended');
        self::assertCount(2, $provider->requests, 'an accepted call ends the run at that step boundary — no extra provider call');
        self::assertSame(1, $probe->calls);
        self::assertTrue($result->requestedWrite, 'the probe round still counts as a possible write');
    }

    public function testTheToolRidesTheWireWithTheSchemaAndNoJsonModeIsRequested(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, ['verdict' => 'fail'])]),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([$this->probe()]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('{"verdict":"fail"}', $result->output);
        self::assertFalse($result->requestedWrite, 'the answer call writes nothing');

        $request = $provider->requests[0];
        self::assertNull($request->jsonSchema, 'JSON mode would forbid the very tool call the run must end with');
        $tools = [];
        foreach ($request->tools ?? [] as $tool) {
            $tools[$tool->name()] = $tool;
        }
        self::assertSame(['probe', StructuredOutputTool::NAME], array_keys($tools));
        self::assertSame(self::VERDICT, $tools[StructuredOutputTool::NAME]->inputSchema());
        self::assertStringContainsString(StructuredOutputTool::INSTRUCTION, $this->systemText($request), 'the run is told how it ends');
    }

    public function testAnInvalidCallIsRejectedWithTheErrorsAndTheModelRetries(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, ['verdict' => 'maybe'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c2', StructuredOutputTool::NAME, ['verdict' => 'pass'])]),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('{"verdict":"pass"}', $result->output);
        self::assertCount(2, $provider->requests);
        $retry = $this->wireText($provider->requests[1]);
        self::assertStringContainsString('do not match the required output schema', $retry);
        self::assertStringContainsString('$.verdict: must be one of pass, fail', $retry);
    }

    public function testAProseEndingIsNudgedOnceThenTheCallIsAccepted(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'Looks fine to me.'),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, ['verdict' => 'pass'])]),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('{"verdict":"pass"}', $result->output, 'the prose is never the output');
        self::assertCount(2, $provider->requests);
        self::assertStringContainsString(StructuredOutputTool::NUDGE, $this->wireText($provider->requests[1]));
    }

    public function testProseAfterTheNudgeFailsTheRunLoudly(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'Looks fine to me.'),
            new CompleteResponse(content: 'Still fine, honestly.'),
            new CompleteResponse(content: 'never asked'),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([]));

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertNull($result->output);
        $why = (string) $result->error?->getMessage();
        self::assertStringContainsString('ended without an accepted StructuredOutput call', $why);
        self::assertStringContainsString('Still fine, honestly.', $why);
        self::assertCount(2, $provider->requests, 'exactly one re-prompt');
    }

    public function testTheNudgeRunsInsideTheRemainingStepBudget(): void
    {
        $probe = $this->probe();
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'done, I think'),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c2', 'probe', [])]),
            new CompleteResponse(content: 'summary text'),
            new CompleteResponse(content: 'never asked'),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT, maxTurns: 3), $this->request([$probe]));

        self::assertSame(AgentStatus::Failed, $result->status);
        // 2 steps spent of 3 → the nudge gets 1 step; its tool call exhausts
        // it and the harness's stopped-turn summary is the last call made.
        self::assertCount(4, $provider->requests);
    }

    public function testAReplyThatIsExactlyAValidDocumentIsAccepted(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: "```json\n{\"verdict\": \"fail\", \"notes\": \"x\"}\n```"),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('{"verdict":"fail","notes":"x"}', $result->output);
        self::assertCount(1, $provider->requests, 'no nudge for an answer that already is the document');
    }

    public function testANonObjectSchemaIsWrappedOnTheWireAndUnwrappedInTheOutput(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, [StructuredOutputTool::WRAP_KEY => ['a', 'b']])]),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent($schema), $this->request([]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('["a","b"]', $result->output);
    }

    public function testAProviderThatCannotCallToolsIsRefusedBeforeAnyRequest(): void
    {
        $inner = new ScriptedProvider([new CompleteResponse(content: 'never asked')]);

        $result = (new EngineExecutor(EngineBackend::new($this->withoutTools($inner), 'm')))
            ->execute($this->agent(self::VERDICT), $this->request([]));

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertStringContainsString('cannot make tool calls', (string) $result->error?->getMessage());
        self::assertStringContainsString('was not started', (string) $result->error?->getMessage());
        self::assertSame([], $inner->requests);
    }

    public function testTheAnswerCallPassesEveryPermissionModeAndTheStagesOwnToolGrant(): void
    {
        foreach ([PermissionMode::Default, PermissionMode::Plan, PermissionMode::DontAsk] as $mode) {
            $provider = new ScriptedProvider([
                new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, ['verdict' => 'pass'])]),
                new CompleteResponse(content: 'never asked'),
            ]);
            $engine = EngineBackend::new($provider, 'm')->withPermissionGate(new PermissionGate($mode));

            $result = (new EngineExecutor($engine))->execute(
                $this->agent(self::VERDICT, tools: ['Read']),
                $this->request([]),
            );

            self::assertSame(AgentStatus::Completed, $result->status, $mode->value . ': ' . $result->error?->getMessage());
            self::assertSame('{"verdict":"pass"}', $result->output, $mode->value);
        }
    }

    public function testASchemalessRunCarriesNoAnswerTool(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'plain report')]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(null), $this->request([$this->probe()]));

        self::assertSame('plain report', $result->output);
        self::assertSame(['probe'], array_map(static fn (Tool $t): string => $t->name(), $provider->requests[0]->tools ?? []));
        // System text only: the turn-context block carries the working tree's
        // diff, which in this checkout may itself mention the tool.
        self::assertStringNotContainsString('end your run by calling', $this->systemText($provider->requests[0]));
    }

    // ------------------------------------------------------------------ P1-6 effort

    public function testAStageEffortRidesTheRequestAsItsReasoningEffort(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(null, effort: Effort::Low), $this->request([]));

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('low', $provider->requests[0]->reasoningEffort);
        self::assertStringContainsString('[effort: low sent, but provider "scripted" ignores reasoning effort]', (string) $result->output);
    }

    public function testANullEffortLeavesTheRequestUntouched(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->agent(null), $this->request([]));

        self::assertSame('ok', $result->output, 'no note without an effort');
        self::assertNull($provider->requests[0]->reasoningEffort);
    }

    public function testASchemaRunKeepsItsNotesOutOfTheDocumentAndOnTheStream(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, ['verdict' => 'pass'])]),
        ]);

        $chunks = [];
        $final = null;
        foreach ((new EngineExecutor(EngineBackend::new($provider, 'm')))->executeStream($this->agent(self::VERDICT, effort: Effort::High), $this->request([])) as $result) {
            if ($result->status === AgentStatus::Streaming) {
                $chunks[] = (string) $result->output;
            } else {
                $final = $result;
            }
        }

        self::assertNotNull($final);
        self::assertSame('{"verdict":"pass"}', $final->output);
        self::assertStringContainsString('[effort: high sent', implode('', $chunks));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param ?array<string, mixed> $schema
     * @param list<string> $tools
     */
    private function agent(?array $schema, ?int $maxTurns = null, array $tools = [], ?Effort $effort = null): SubAgent
    {
        return new SubAgent(
            id: 'stage-' . bin2hex(random_bytes(4)),
            agent: RosterAgent::named('reviewer', $tools, [], $maxTurns),
            task: 'review the lib',
            outputSchema: $schema,
            effort: $effort,
        );
    }

    /** @param list<Tool> $tools */
    private function request(array $tools): CompleteRequest
    {
        return new CompleteRequest(
            model: 'm',
            messages: [['role' => 'user', 'content' => 'review the lib']],
            tools: $tools,
        );
    }

    /** Every text the request carries, system prompt and turns alike. */
    private function wireText(CompleteRequest $request): string
    {
        $text = (string) $request->systemPrompt;
        foreach ($request->messages as $message) {
            $text .= "\n" . ($message instanceof TypedMessage ? $message->content() : json_encode($message));
        }

        return $text;
    }

    private function systemText(CompleteRequest $request): string
    {
        $text = (string) $request->systemPrompt;
        foreach ($request->messages as $message) {
            if ($message instanceof \SugarCraft\Code\Messages\SystemMessage) {
                $text .= "\n" . $message->content();
            }
        }

        return $text;
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

                return new ToolResult(toolCallId: '', content: 'probed');
            }
        };
    }

    private function withoutTools(ScriptedProvider $inner): ProviderInterface
    {
        return new class ($inner) implements ProviderInterface {
            public function __construct(private readonly ScriptedProvider $inner)
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
                return false;
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
                return null;
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
}
