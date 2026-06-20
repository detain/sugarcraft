<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * @see EchoProvider
 */
final class EchoProviderTest extends TestCase
{
    private EchoProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new EchoProvider();
    }

    public function testImplementsProviderInterface(): void
    {
        $this->assertInstanceOf(ProviderInterface::class, $this->provider);
    }

    public function testName(): void
    {
        $this->assertSame('echo', $this->provider->name());
    }

    public function testCapabilities(): void
    {
        $this->assertTrue($this->provider->supportsStreaming());
        $this->assertFalse($this->provider->supportsFunctionCalling());
        $this->assertFalse($this->provider->supportsVision());
        $this->assertFalse($this->provider->supportsJsonSchema());
        $this->assertGreaterThan(0, $this->provider->contextWindow());
    }

    public function testCostIsAlwaysZero(): void
    {
        $this->assertSame(0.0, $this->provider->costPer1kTokens('echo', 'input'));
        $this->assertSame(0.0, $this->provider->costPer1kTokens('echo', 'output'));
    }

    public function testCompleteEchoesLastUserMessageAsBlockquote(): void
    {
        $request = new CompleteRequest(
            model: 'echo',
            messages: [
                new SystemMessage('be helpful'),
                new UserMessage('first'),
                new AssistantMessage('ok'),
                new UserMessage('hello world'),
            ],
        );

        $response = $this->provider->complete($request);

        $this->assertStringContainsString('You said:', $response->content);
        $this->assertStringContainsString('> hello world', $response->content);
        $this->assertStringNotContainsString('first', $response->content);
        $this->assertNull($response->toolCalls);
    }

    public function testCompleteWithNoUserMessage(): void
    {
        $request = new CompleteRequest(model: 'echo', messages: [new SystemMessage('x')]);

        $response = $this->provider->complete($request);

        $this->assertStringContainsString('nothing to echo', $response->content);
    }

    public function testCompleteQuotesEveryLine(): void
    {
        $request = new CompleteRequest(
            model: 'echo',
            messages: [new UserMessage("line one\nline two")],
        );

        $response = $this->provider->complete($request);

        $this->assertStringContainsString('> line one', $response->content);
        $this->assertStringContainsString('> line two', $response->content);
    }

    public function testCompleteStreamYieldsPiecesThatReassemble(): void
    {
        $request = new CompleteRequest(model: 'echo', messages: [new UserMessage('alpha beta')]);

        $pieces = [];
        foreach ($this->provider->completeStream($request) as $chunk) {
            $pieces[] = $chunk->content;
        }

        $this->assertNotEmpty($pieces);
        $reassembled = implode('', $pieces);
        $this->assertSame($this->provider->complete($request)->content, $reassembled);
    }

    public function testEmbeddingsReturnsOneVectorPerStringInput(): void
    {
        $response = $this->provider->embeddings(new EmbeddingsRequest('echo', ['ab', 'abcd']));

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([2.0], $response->embeddings[0]);
        $this->assertSame([4.0], $response->embeddings[1]);
    }

    public function testEmbeddingsAcceptsScalarInput(): void
    {
        $response = $this->provider->embeddings(new EmbeddingsRequest('echo', 'hi'));

        $this->assertSame([[2.0]], $response->embeddings);
    }
}
