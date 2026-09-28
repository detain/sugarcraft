<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tests\Support\RosterAgent;

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
    public function testABoundTaskRunsTheSubAgentThroughAToolLoopUntilItReports(): void
    {
        $probe = self::probe('probe');
        $provider = self::scripted([
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
        $provider = self::scripted([new CompleteResponse(content: 'nothing to delegate')]);
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
        $provider = self::scripted([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('ended without a final report (step cap 1)', $result->content());
        $this->assertCount(1, $probe->calls, 'the work it did still happened');
    }

    public function testTheSessionHookChainGovernsTheSubAgentsCalls(): void
    {
        // Named `Read` so the built-in ProtectFilesHook judges its file_path.
        $read = self::probe('Read');
        $provider = self::scripted([
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
        $engine = EngineBackend::new(self::scripted([]), 'm');

        $this->assertFalse((new TaskTool())->isParallelSafe());
        $this->assertFalse((new TaskTool($manager))->isParallelSafe(), 'the pool path stays a barrier');
        $this->assertTrue((new TaskTool($manager))->withEngine($engine)->isParallelSafe());
    }

    public function testTheRunningEngineBindsItselfIntoTheTaskToolEveryTurn(): void
    {
        // One scripted provider serves both levels, in call order: the caller
        // delegates, the sub-agent answers, the caller wraps up.
        $provider = self::scripted([
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
        $engine = EngineBackend::new(self::scripted([]), 'm')->withTools([$task]);

        $this->assertSame([$task], $engine->tools(), 'binding happens per turn, never on the stored list');
    }

    public function testAnUnresolvableGrantIsRefusedBeforeAnyProviderCall(): void
    {
        $provider = self::scripted([new CompleteResponse(content: 'unreachable')]);
        $manager = self::manager([self::probe('probe')], RosterAgent::named('coder', ['Reed']));
        $engine = EngineBackend::new($provider, 'm');

        $result = (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Error: Task refused', $result->content());
        $this->assertSame([], $provider->requests);
    }

    /**
     * @return array<string, string>
     */
    private static function call(): array
    {
        return [
            'description' => 'Audit candy-core',
            'prompt' => 'Audit candy-core and report the findings',
            'agent' => 'coder',
        ];
    }

    /**
     * @param list<Tool> $registry
     */
    private static function manager(array $registry, Agent $agent): AgentManager
    {
        $manager = new AgentManager(
            self::scripted([]),
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

    /**
     * A batch provider answering from a script, in call order, recording every
     * request. Past the end of the script it repeats the last answer, so a run
     * that loops longer than expected fails on an assertion, not a crash.
     *
     * @param list<CompleteResponse> $script
     *
     * @return ProviderInterface&object{requests: list<CompleteRequest>}
     */
    private static function scripted(array $script): ProviderInterface
    {
        return new class ($script) implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            /** @param list<CompleteResponse> $script */
            public function __construct(private array $script)
            {
            }

            public function name(): string
            {
                return 'scripted';
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

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->requests[] = $request;
                $index = min(\count($this->requests), \count($this->script)) - 1;

                return $this->script[$index] ?? new CompleteResponse(content: '');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }
}
