<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ReportsServedModel;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;

/**
 * Roadmap 4.1-1: a delegated run's MODEL and REASONING EFFORT are the
 * agent's, on the session's provider — and a choice the provider cannot
 * honour is refused before anything is billed, never relabelled or dropped.
 */
final class TaskToolPresetModelTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_model_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testAPresetThatNamesAModelRunsOnIt(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);

        $result = $this->delegate($provider, 'session-model', self::agent(model: 'preset-model'));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('preset-model', $provider->requests[0]->model);
    }

    public function testAnInheritingAgentFollowsTheSessionsCurrentModel(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);

        // The roster row says the launch model; the session has moved on.
        $this->delegate($provider, 'switched-model', self::agent(model: 'launch-model', inherits: true));

        $this->assertSame('switched-model', $provider->requests[0]->model);
    }

    public function testTheCallsOwnModelWinsOverTheAgents(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);

        $this->delegate($provider, 'session-model', self::agent(model: 'preset-model'), ['model' => 'call-model']);

        $this->assertSame('call-model', $provider->requests[0]->model);
    }

    public function testTheSchemaOffersTheModelArgument(): void
    {
        $schema = (new TaskTool())->inputSchema();

        $this->assertArrayHasKey('model', $schema['properties']);
        $this->assertNotContains('model', $schema['required'], 'the model is optional');
    }

    public function testAClaudeTierNameIsRefusedWhereNoModelAnswersToIt(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);

        $result = $this->delegate($provider, 'Qwen/Qwen3.8-Flash-Next', self::agent(model: 'sonnet'));

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('"sonnet", a Claude Code tier name', $result->content());
        $this->assertSame([], $provider->requests, 'refused before anything was sent or billed');
    }

    public function testAClaudeTierNameResolvesToTheSessionModelOfThatTier(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);

        $result = $this->delegate($provider, 'claude-sonnet-4-6', self::agent(model: 'sonnet'));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('claude-sonnet-4-6', $provider->requests[0]->model);
    }

    public function testASingleModelServerIsHeldToTheModelItServes(): void
    {
        $provider = new class ([new CompleteResponse(content: 'report')]) extends \ArrayObject implements \SugarCraft\Crush\Providers\ProviderInterface, ReportsServedModel {
            private ScriptedProvider $inner;

            public function __construct(array $script)
            {
                parent::__construct();
                $this->inner = new ScriptedProvider($script);
            }

            public function servedModel(): ?string
            {
                return 'Served/Model-FP8';
            }

            public function noteServedModel(string $servedModel): void
            {
            }

            public function name(): string
            {
                return 'sglang';
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
                $this[] = $request;

                return $this->inner->complete($request);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(\SugarCraft\Crush\Providers\EmbeddingsRequest $request): \SugarCraft\Crush\Providers\EmbeddingsResponse
            {
                return $this->inner->embeddings($request);
            }
        };

        $refused = $this->delegate($provider, 'default', self::agent(model: 'Other/Model'));
        $this->assertTrue($refused->isError());
        $this->assertStringContainsString('serves only "Served/Model-FP8"', $refused->content());
        $this->assertCount(0, $provider, 'nothing was sent to a server that cannot serve it');

        $served = $this->delegate($provider, 'default', self::agent(model: 'Served/Model-FP8'));
        $this->assertFalse($served->isError(), $served->content());
        $this->assertSame('Served/Model-FP8', $provider[0]->model, 'the served model itself is a real choice');
    }

    public function testAnEffortIsRefusedOnAProviderThatWouldDropIt(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);

        $result = $this->delegate($provider, 'm', self::agent(effort: Effort::High));

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('declares `effort: high`, but provider "scripted" sends no reasoning effort', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testAnEffortReachesTheSglangWireAndNoEffortSendsTheProvidersOwn(): void
    {
        $wire = [];
        $stream = 'data: {"choices":[{"delta":{"content":"report"}}]}' . "\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}' . "\n"
            . 'data: [DONE]' . "\n";
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $stream), new Response(200, [], $stream)]));
        $stack->push(Middleware::history($wire));
        $sglang = new SglangProvider('https://gw.example', 'MiniMax-M2.7', null, new Client(['base_uri' => 'https://gw.example/', 'handler' => $stack]));

        $this->delegate($sglang, 'MiniMax-M2.7', self::agent(effort: Effort::Low));
        $this->delegate($sglang, 'MiniMax-M2.7', self::agent());

        $sent = array_map(
            static fn (array $entry): array => json_decode((string) $entry['request']->getBody(), true, 512, \JSON_THROW_ON_ERROR),
            $wire,
        );
        $this->assertSame('low', $sent[0]['reasoning_effort'] ?? null, 'the preset effort is this run\'s request knob');
        $this->assertArrayNotHasKey('reasoning_effort', $sent[1], 'no declared effort leaves the model\'s own default (none for MiniMax)');
    }

    /**
     * @param array<string, string> $args
     */
    private function delegate(\SugarCraft\Crush\Providers\ProviderInterface $provider, string $sessionModel, Agent $agent, array $args = []): \SugarCraft\Crush\Tools\ToolResult
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register($agent);
        $engine = EngineBackend::new($provider, $sessionModel);

        return (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine($engine)
            ->execute($args + ['description' => 'Map it', 'prompt' => 'Map the module', 'agent' => 'mapper']);
    }

    private static function agent(string $model = 'roster-model', bool $inherits = false, ?Effort $effort = null): Agent
    {
        return new Agent(
            name: 'mapper',
            description: 'maps',
            prompt: 'You are mapper.',
            model: $model,
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
            effort: $effort,
            inheritsModel: $inherits,
        );
    }
}
