<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;

/**
 * Regression coverage for audit 15a A10: CustomProvider used to put a literal
 * `"extra_body": {"separate_reasoning": true}` key in both the batch and the
 * streaming body. `extra_body` is an OpenAI Python-SDK client argument the
 * SDK flattens into the body; sent raw it is an unknown top-level field that
 * strict OpenAI-compatible servers answer 400 on every request. The
 * default-body assertions below fail against that old code.
 */
final class CustomProviderExtraBodyTest extends TestCase
{
    private const SSE_OK = 'data: {"choices":[{"delta":{"content":"ok"},"finish_reason":"stop"}]}' . "\n\n"
        . 'data: [DONE]' . "\n\n";

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param array<string, mixed> $extraBody
     */
    private function provider(Response $response, array $extraBody = []): CustomProvider
    {
        return new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $this->client($response),
            true,
            true,
            extraBody: $extraBody,
        );
    }

    private function client(Response $response): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));

        return new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]);
    }

    private static function batchResponse(): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], JSON_THROW_ON_ERROR));
    }

    private static function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'gpt-4', messages: [new UserMessage('Hi')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $this->assertCount(1, $this->history);

        return json_decode((string) $this->history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testCompleteDefaultBodyCarriesNoExtraBodyKey(): void
    {
        $response = $this->provider(self::batchResponse())->complete(self::request());

        $this->assertFalse($response->isError);
        $sent = $this->sentBody();
        $this->assertArrayNotHasKey('extra_body', $sent);
        $this->assertArrayNotHasKey('separate_reasoning', $sent);
        $this->assertSame(['model', 'messages', 'temperature', 'max_tokens'], array_keys($sent));
    }

    public function testCompleteStreamDefaultBodyCarriesNoExtraBodyKey(): void
    {
        $chunks = iterator_to_array($this->provider(new Response(200, [], self::SSE_OK))->completeStream(self::request()), false);

        $this->assertNotEmpty($chunks);
        $sent = $this->sentBody();
        $this->assertArrayNotHasKey('extra_body', $sent);
        $this->assertArrayNotHasKey('separate_reasoning', $sent);
        $this->assertSame(['model', 'messages', 'temperature', 'max_tokens', 'stream', 'stream_options'], array_keys($sent));
    }

    public function testCompleteMergesOptInExtrasAtTopLevel(): void
    {
        $extras = ['separate_reasoning' => true, 'chat_template_kwargs' => ['thinking' => true]];

        $this->provider(self::batchResponse(), $extras)->complete(self::request());

        $sent = $this->sentBody();
        $this->assertArrayNotHasKey('extra_body', $sent);
        $this->assertTrue($sent['separate_reasoning']);
        $this->assertSame(['thinking' => true], $sent['chat_template_kwargs']);
        $this->assertSame('gpt-4', $sent['model']);
    }

    public function testCompleteStreamMergesOptInExtrasAtTopLevel(): void
    {
        $extras = ['separate_reasoning' => true, 'top_k' => 20];

        iterator_to_array($this->provider(new Response(200, [], self::SSE_OK), $extras)->completeStream(self::request()), false);

        $sent = $this->sentBody();
        $this->assertArrayNotHasKey('extra_body', $sent);
        $this->assertTrue($sent['separate_reasoning']);
        $this->assertSame(20, $sent['top_k']);
        $this->assertTrue($sent['stream']);
        $this->assertSame(['include_usage' => true], $sent['stream_options']);
    }

    public function testOpenAiCompatibleThreadsExtraBody(): void
    {
        $provider = CustomProvider::openAiCompatible(
            name: 'custom',
            baseUrl: 'https://api.example.com/v1',
            model: 'gpt-4',
            extraBody: ['separate_reasoning' => true],
        );

        $property = new \ReflectionProperty($provider, 'extraBody');
        $this->assertSame(['separate_reasoning' => true], $property->getValue($provider));
    }

    public function testOpenAiCompatibleDefaultsToNoExtras(): void
    {
        $provider = CustomProvider::openAiCompatible(name: 'custom', baseUrl: 'https://api.example.com/v1', model: 'gpt-4');

        $property = new \ReflectionProperty($provider, 'extraBody');
        $this->assertSame([], $property->getValue($provider));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function rejectedExtraBodies(): iterable
    {
        foreach (['model', 'messages', 'temperature', 'max_tokens', 'stream', 'stream_options', 'tools'] as $key) {
            yield "reserved {$key}" => [[$key => 'x'], $key];
        }
        yield 'literal extra_body' => [['extra_body' => ['separate_reasoning' => true]], 'inner keys'];
        yield 'empty-string key' => [['' => true], 'non-empty strings'];
        yield 'integer key' => [[true], 'non-empty strings'];
    }

    /**
     * @param array<array-key, mixed> $extraBody
     */
    #[DataProvider('rejectedExtraBodies')]
    public function testConstructorRejectsInvalidExtraBody(array $extraBody, string $messageFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messageFragment);

        new CustomProvider('custom', 'https://api.example.com', 'gpt-4', null, new Client(), true, true, extraBody: $extraBody);
    }
}
