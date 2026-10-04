<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use GuzzleHttp\Client;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ModelMetadata;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * `EngineBackend::withModel()` moves the PROVIDER with the wire (roadmap
 * N-P3b remainder, 4.1-1), and `withReasoningEffort()` reaches every request.
 *
 * Before the rebind the model id changed on the wire while
 * {@see EngineBackend::contextWindow()} — the denominator of every context
 * tier — kept answering for the model the provider was built with.
 */
final class EngineBackendWithModelTest extends TestCase
{
    public function testWithModelRebindsTheProvidersContextWindow(): void
    {
        $engine = EngineBackend::new(new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4'), 'gpt-4');
        $this->assertSame(8_192, $engine->contextWindow());

        $moved = $engine->withModel('gpt-4.1');

        $this->assertSame('gpt-4.1', $moved->model());
        $this->assertSame(1_047_576, $moved->contextWindow(), 'the window follows the model, not the build');
        $this->assertSame(8_192, $engine->contextWindow(), 'the receiver is untouched');
        $this->assertNotSame($engine->provider(), $moved->provider());
    }

    public function testACustomProviderRebindsTheWindowItReadsOffTheModelDatabase(): void
    {
        $metadata = ModelMetadata::fromTable([
            'small' => ['max_input_tokens' => 8_000],
            'large' => ['max_input_tokens' => 200_000],
        ]);
        $custom = new CustomProvider('custom', 'https://gw.example', 'small', null, new Client(), true, true, modelMetadata: $metadata);
        $engine = EngineBackend::new($custom, 'small');

        $moved = $engine->withModel('large');

        $this->assertSame(8_000, $engine->contextWindow());
        $this->assertSame(200_000, $moved->contextWindow());
        $this->assertNotSame($custom, $moved->provider(), 'a rebindable provider is copied, never mutated');
    }

    public function testAProviderThatCannotRebindIsKeptAndTheSameIdIsANoOp(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        $engine = EngineBackend::new($provider, 'm');

        $this->assertSame($provider, $engine->withModel('other')->provider(), 'no RebindsModel: the provider stays');
        $this->assertSame($engine, $engine->withModel('m'), 'the model it already runs on changes nothing');
    }

    public function testTheModelReachesTheWire(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);

        EngineBackend::new($provider, 'm')->withModel('picked')->complete([Message::user('hi')]);

        $this->assertSame('picked', $provider->requests[0]->model);
    }

    public function testAReasoningEffortRidesEveryRequestAndNoneAsksNothing(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'ok'),
            new CompleteResponse(content: 'ok'),
        ]);
        $engine = EngineBackend::new($provider, 'm');

        $engine->complete([Message::user('one')]);
        $engine->withReasoningEffort('low')->complete([Message::user('two')]);

        $this->assertNull($provider->requests[0]->reasoningEffort, 'a top-level turn asks no effort of its own');
        $this->assertSame('low', $provider->requests[1]->reasoningEffort);
        $this->assertNull($engine->reasoningEffort());
        $this->assertSame('low', $engine->withReasoningEffort('low')->reasoningEffort());
    }

    public function testOnlyTheSglangProviderHonoursAReasoningEffort(): void
    {
        $this->assertFalse(EngineBackend::new(new ScriptedProvider([]), 'm')->honoursReasoningEffort());
        $this->assertTrue(
            EngineBackend::new(new SglangProvider('https://gw.example', 'MiniMax-M2.7', null, new Client()), 'MiniMax-M2.7')
                ->honoursReasoningEffort(),
        );
    }

    public function testBlankModelsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EngineBackend::new(new ScriptedProvider([]), 'm')->withModel('  ');
    }
}
