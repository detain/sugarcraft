<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\AcceptsAssistantPrefill;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ReplyContinuation;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 2.7-2: a request that ends on the assistant's own partial reply asks
 * SGLang for the REST of that message — `continue_final_message` with no
 * generation prompt — and no other request does. Which providers take such a
 * prefill at all is declared per model.
 */
final class SglangContinueFinalMessageTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    public function testAPrefillRequestContinuesTheFinalMessage(): void
    {
        $body = $this->sentBody([new UserMessage('explain'), new AssistantMessage('The reason is')], stream: false);

        $this->assertTrue($body['continue_final_message']);
        $this->assertFalse($body['add_generation_prompt']);
        $last = $body['messages'][array_key_last($body['messages'])];
        $this->assertSame(['role' => 'assistant', 'content' => 'The reason is'], $last);
    }

    public function testTheStreamingBodyCarriesItToo(): void
    {
        $body = $this->sentBody([new UserMessage('explain'), new AssistantMessage('The reason is')], stream: true);

        $this->assertTrue($body['continue_final_message']);
        $this->assertFalse($body['add_generation_prompt']);
    }

    public function testAnOrdinaryRequestCarriesNeitherKey(): void
    {
        foreach ([
            'ends on the user' => [new UserMessage('hi')],
            'ends on a tool result' => [new UserMessage('hi'), new AssistantMessage('', [new ToolCall('c1', 'Read', [])]), new ToolResultMessage('c1', 'x')],
            'ends on an assistant tool call' => [new UserMessage('hi'), new AssistantMessage('let me look', [new ToolCall('c1', 'Read', [])])],
            'ends on an empty assistant' => [new UserMessage('hi'), new AssistantMessage('')],
        ] as $case => $messages) {
            $body = $this->sentBody($messages, stream: false);
            $this->assertArrayNotHasKey('continue_final_message', $body, $case);
            $this->assertArrayNotHasKey('add_generation_prompt', $body, $case);
        }
    }

    public function testWhichProvidersTakeAPrefill(): void
    {
        $sglang = SglangProvider::openAiCompatible('https://api.example.com', 'm');
        $this->assertInstanceOf(AcceptsAssistantPrefill::class, $sglang);
        $this->assertTrue(ReplyContinuation::prefills($sglang, 'any-model'));

        $vertex = new VertexProvider('p', 'us-east5', 'claude-sonnet-4-6@20250514', static fn (): array => []);
        $this->assertTrue($vertex->acceptsAssistantPrefill('claude-sonnet-4-6@20250514'));
        $this->assertTrue($vertex->acceptsAssistantPrefill(''), 'an unnamed model is the provider\'s own');
        $this->assertFalse($vertex->acceptsAssistantPrefill('gemini-2.5-pro'), 'Gemini is asked with a user row');

        $bedrock = (new \ReflectionClass(BedrockProvider::class))->newInstanceWithoutConstructor();
        $this->assertTrue($bedrock->acceptsAssistantPrefill('us.anthropic.claude-sonnet-4-6'));
        $this->assertFalse($bedrock->acceptsAssistantPrefill('meta.llama3-70b-instruct-v1:0'));
    }

    public function testAPrefillRefusalIsRecognised(): void
    {
        $this->assertTrue(ReplyContinuation::rejectsPrefill(new \RuntimeException('This model does not support assistant message prefill. The conversation must end with a user message.')));
        $this->assertFalse(ReplyContinuation::rejectsPrefill(new \RuntimeException('401 Unauthorized')));
    }

    /**
     * @param list<\SugarCraft\Crush\Messages\Message> $messages
     * @return array<string, mixed>
     */
    private function sentBody(array $messages, bool $stream): array
    {
        $this->history = [];
        $answer = $stream
            ? new Response(200, ['Content-Type' => 'text/event-stream'], 'data: {"choices":[{"index":0,"delta":{"content":" more"},"finish_reason":"stop"}]}' . "\n\ndata: [DONE]\n\n")
            : new Response(200, [], '{"choices":[{"message":{"content":" more"},"finish_reason":"stop"}],"usage":{"total_tokens":1}}');
        $stack = HandlerStack::create(new MockHandler([$answer]));
        $stack->push(Middleware::history($this->history));
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]));
        $request = new CompleteRequest(model: 'MiniMax-M2.7', messages: $messages, maxTokens: 64);

        if ($stream) {
            iterator_to_array($provider->completeStream($request), false);
        } else {
            $provider->complete($request);
        }

        $this->assertCount(1, $this->history);

        return json_decode((string) $this->history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
