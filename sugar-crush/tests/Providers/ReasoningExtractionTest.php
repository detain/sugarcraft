<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Chat\CreateStreamedResponse as ChatCreateStreamedResponse;
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * Regression coverage for crush_feat.md §12 D3: `SglangProvider`,
 * `CustomProvider` and `OpenAIProvider` each used to hardcode
 * `reasoning: null` in `parseResponse()`/`parseChunk()`, discarding
 * `reasoning_content` unconditionally and leaving any inline `<think>`
 * markup un-stripped in `content`. Every "reasoning extracted" assertion
 * below would fail against that old code - `reasoning` would come back
 * `null` and, in the Case 2 tests, `content` would still contain the raw
 * `<think>...</think>` tags instead of the split-out text.
 *
 * All three providers share one extraction routine -
 * {@see \SugarCraft\Crush\Providers\Concerns\ReasoningExtractor} - so it is
 * exercised once per provider through each provider's real `complete()`/
 * `parseChunk()` call path rather than duplicated as three copy-pasted
 * assertions of the same logic.
 */
final class ReasoningExtractionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case 1: server-side parser already split reasoning into its own field
    // (SGLang's deployed `--reasoning-parser minimax` / Qwen3Detector).
    // -------------------------------------------------------------------------

    public function testSglangParseResponseTrustsReasoningContentFieldDirectly(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [[
                'message' => [
                    'content' => 'The answer is 4.',
                    'reasoning_content' => 'Let me add 2 and 2.',
                ],
            ]],
            'usage' => ['total_tokens' => 10],
        ])));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $result = $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('2+2?')]));

        $this->assertSame('Let me add 2 and 2.', $result->reasoning);
        $this->assertSame('The answer is 4.', $result->content);
    }

    public function testCustomProviderParseResponseTrustsReasoningContentFieldDirectly(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [[
                'message' => [
                    'content' => 'The answer is 4.',
                    'reasoning_content' => 'Let me add 2 and 2.',
                ],
            ]],
            'usage' => ['total_tokens' => 10],
        ])));

        $provider = new CustomProvider('local-sglang', 'https://api.example.com', 'MiniMax-M2.7', null, $httpClient, true, true);
        $result = $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('2+2?')]));

        $this->assertSame('Let me add 2 and 2.', $result->reasoning);
        $this->assertSame('The answer is 4.', $result->content);
    }

    public function testSglangParseResponseExtractsSingleZeroCharacterReasoningContent(): void
    {
        // Regression: the Case 1 guard used to be `!empty()`, and PHP counts
        // the string "0" as empty - a server that populated
        // reasoning_content with exactly "0" had its reasoning silently
        // dropped and reported as null.
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [[
                'message' => [
                    'content' => 'The answer is 4.',
                    'reasoning_content' => '0',
                ],
            ]],
            'usage' => ['total_tokens' => 10],
        ])));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $result = $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('2+2?')]));

        $this->assertSame('0', $result->reasoning);
        $this->assertSame('The answer is 4.', $result->content);
    }

    public function testSglangParseChunkExtractsSingleZeroCharacterReasoningContent(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];
        $result = $method->invokeArgs($provider, [
            ['choices' => [['delta' => ['content' => 'partial', 'reasoning_content' => '0']]]],
            &$buffer,
        ]);

        $this->assertSame('0', $result->reasoning);
        $this->assertSame('partial', $result->content);
    }

    // -------------------------------------------------------------------------
    // Case 2: no reasoning_content field, raw <think> markup left inline in
    // content (SGLang's `minimax-append-think` detector, or any non-splitting
    // parser). Must be stripped out client-side and surfaced separately.
    // -------------------------------------------------------------------------

    public function testSglangParseResponseStripsInlineThinkTagsWhenReasoningContentAbsent(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [[
                'message' => [
                    'content' => '<think>Let me add 2 and 2.</think>The answer is 4.',
                ],
            ]],
            'usage' => ['total_tokens' => 10],
        ])));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $result = $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('2+2?')]));

        $this->assertSame('Let me add 2 and 2.', $result->reasoning);
        $this->assertSame('The answer is 4.', $result->content);
    }

    public function testCustomProviderParseResponseStripsInlineThinkTagsWhenReasoningContentAbsent(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [[
                'message' => [
                    'content' => '<think>Let me add 2 and 2.</think>The answer is 4.',
                ],
            ]],
            'usage' => ['total_tokens' => 10],
        ])));

        $provider = new CustomProvider('local-sglang', 'https://api.example.com', 'MiniMax-M2.7', null, $httpClient, true, true);
        $result = $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('2+2?')]));

        $this->assertSame('Let me add 2 and 2.', $result->reasoning);
        $this->assertSame('The answer is 4.', $result->content);
    }

    public function testOpenAiProviderParseResponseStripsInlineThinkTagsWhenReasoningContentAbsent(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $chatMock->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => '<think>Let me add 2 and 2.</think>The answer is 4.',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], MetaInformation::from([])));

        $provider = new OpenAIProvider($client, 'gpt-4o');
        $result = $provider->complete(new CompleteRequest(model: 'gpt-4o', messages: [new UserMessage('2+2?')]));

        $this->assertSame('Let me add 2 and 2.', $result->reasoning);
        $this->assertSame('The answer is 4.', $result->content);
    }

    // -------------------------------------------------------------------------
    // No reasoning present at all - must not fabricate a split.
    // -------------------------------------------------------------------------

    public function testSglangParseResponseLeavesReasoningNullWhenNeitherShapeIsPresent(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'Just an answer, no thinking markup.']]],
            'usage' => ['total_tokens' => 5],
        ])));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $result = $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]));

        $this->assertNull($result->reasoning);
        $this->assertSame('Just an answer, no thinking markup.', $result->content);
    }

    // -------------------------------------------------------------------------
    // Streaming: reasoning_content passes through per chunk unchanged (Case 1
    // is unambiguous per delta), and inline <think> markup is stripped per
    // chunk on a best-effort basis (Case 2's accepted chunk-boundary caveat -
    // see SglangProvider::parseChunk()'s docblock).
    // -------------------------------------------------------------------------

    public function testSglangParseChunkPassesThroughReasoningContentAndKeepsExistingBehaviour(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];
        $result = $method->invokeArgs($provider, [
            ['choices' => [['delta' => ['content' => 'partial', 'reasoning_content' => 'thinking...']]]],
            &$buffer,
        ]);

        $this->assertSame('thinking...', $result->reasoning);
        $this->assertSame('partial', $result->content);
    }

    public function testSglangParseChunkStripsThinkTagsWhenWhollyContainedInOneChunk(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];
        $result = $method->invokeArgs($provider, [
            ['choices' => [['delta' => ['content' => '<think>reasoning here</think>final answer']]]],
            &$buffer,
        ]);

        $this->assertSame('reasoning here', $result->reasoning);
        $this->assertSame('final answer', $result->content);
    }

    public function testCustomProviderParseChunkStripsThinkTagsWhenWhollyContainedInOneChunk(): void
    {
        $client = new Client();
        $provider = new CustomProvider('local-sglang', 'https://api.example.com', 'MiniMax-M2.7', null, $client, true, true);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];
        $result = $method->invokeArgs($provider, [
            ['choices' => [['delta' => ['content' => '<think>reasoning here</think>final answer']]]],
            &$buffer,
        ]);

        $this->assertSame('reasoning here', $result->reasoning);
        $this->assertSame('final answer', $result->content);
    }

    public function testOpenAiProviderParseChunkStripsThinkTagsWhenWhollyContainedInOneChunk(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $chunk = ChatCreateStreamedResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion.chunk',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'delta' => ['content' => '<think>reasoning here</think>final answer'],
                'finish_reason' => null,
            ]],
        ]);

        $result = $method->invoke($provider, $chunk);

        $this->assertSame('reasoning here', $result->reasoning);
        $this->assertSame('final answer', $result->content);
    }

    // -------------------------------------------------------------------------
    // D3 also asks to pin `extra_body.separate_reasoning = true` on the
    // outgoing request so a properly-splitting parser is told to populate
    // reasoning_content even if a given deployment's default ever changes.
    // -------------------------------------------------------------------------

    public function testSglangCompletePinsSeparateReasoningInRequestBody(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
                'usage' => ['total_tokens' => 1],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]));

        $sent = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertTrue($sent['extra_body']['separate_reasoning'] ?? null);
    }

    public function testCustomProviderCompletePinsSeparateReasoningInRequestBody(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
                'usage' => ['total_tokens' => 1],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]);

        $provider = new CustomProvider('local-sglang', 'https://api.example.com', 'MiniMax-M2.7', null, $httpClient, true, true);
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]));

        $sent = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertTrue($sent['extra_body']['separate_reasoning'] ?? null);
    }
}
