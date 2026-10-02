<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\SseData;

/**
 * Audit 15a A5: the SSE spec makes the space after `data:` optional, and some
 * OpenAI-compatible gateways send `data:{...}`. Both OpenAI-wire stream loops
 * matched the literal `data: `, so every such frame was dropped - and, since
 * the A3 cut detection, the stream then ended in a premature-end error because
 * neither its finish frame nor its `[DONE]` was ever seen.
 */
final class SseDataNoSpaceTest extends TestCase
{
    private const NO_SPACE_BODY =
        'data:{"choices":[{"index":0,"delta":{"content":"Hello"}}]}' . "\n\n"
        . 'data:{"choices":[{"index":0,"delta":{"content":" world"},"finish_reason":"stop"}]}' . "\n\n"
        . 'data:{"choices":[],"usage":{"prompt_tokens":3,"completion_tokens":2,"total_tokens":5}}' . "\n\n"
        . "data:[DONE]\n\n";

    /** No finish_reason on any frame: only the `data:[DONE]` sentinel says the stream closed. */
    private const DONE_ONLY_BODY =
        'data:{"choices":[{"index":0,"delta":{"content":"Hi"}}]}' . "\n\n"
        . 'data:[DONE]';

    private static function client(string $body): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)])),
            'base_uri' => 'http://provider.invalid/',
        ]);
    }

    private static function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: [new UserMessage('hi')]);
    }

    /** @return list<CompleteResponse> */
    private static function sglang(string $body): array
    {
        $provider = new SglangProvider('http://provider.invalid', 'm', null, self::client($body));

        return iterator_to_array($provider->completeStream(self::request()), false);
    }

    /** @return list<CompleteResponse> */
    private static function custom(string $body): array
    {
        $provider = new CustomProvider('custom', 'http://provider.invalid', 'm', null, self::client($body), true, true);

        return iterator_to_array($provider->completeStream(self::request()), false);
    }

    /** @param list<CompleteResponse> $chunks */
    private static function text(array $chunks): string
    {
        return implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks));
    }

    /** @param list<CompleteResponse> $chunks */
    private static function assertNoErrorChunk(array $chunks): void
    {
        foreach ($chunks as $chunk) {
            self::assertFalse($chunk->isError, 'unexpected error chunk: ' . (string) $chunk->errorMessage);
        }
    }

    public function testValueStripsExactlyOneOptionalSpace(): void
    {
        $this->assertSame('{"a":1}', SseData::value('data:{"a":1}'));
        $this->assertSame('{"a":1}', SseData::value('data: {"a":1}'));
        $this->assertSame(' x', SseData::value('data:  x'), 'only ONE space is the separator; the rest is value');
        $this->assertSame('', SseData::value('data:'));
        $this->assertNull(SseData::value('event: message'));
        $this->assertNull(SseData::value(': keepalive comment'));
        $this->assertNull(SseData::value('dat: x'));
    }

    public function testIsDoneAcceptsBothSpellingsAndSurroundingWhitespace(): void
    {
        $this->assertTrue(SseData::isDone('data: [DONE]'));
        $this->assertTrue(SseData::isDone('data:[DONE]'));
        $this->assertTrue(SseData::isDone("  data:[DONE]\r\n"));
        $this->assertFalse(SseData::isDone('data: {"choices":[]}'));
        $this->assertFalse(SseData::isDone(''));
    }

    public function testSglangReadsFramesWithNoSpaceAfterTheColon(): void
    {
        $chunks = self::sglang(self::NO_SPACE_BODY);

        $this->assertSame('Hello world', self::text($chunks));
        $usage = null;
        foreach ($chunks as $chunk) {
            $usage = $chunk->usage ?? $usage;
        }
        $this->assertNotNull($usage, 'the no-space usage frame reaches the fold too');
        $this->assertSame(5, $usage->totalTokens);
    }

    public function testSglangTreatsANoSpaceDoneAsAClosedStreamNotACut(): void
    {
        // Pre-fix this threw ProviderStreamException::prematureEnd().
        $this->assertSame('Hi', self::text(self::sglang(self::DONE_ONLY_BODY)));
    }

    public function testCustomReadsFramesWithNoSpaceAfterTheColon(): void
    {
        $chunks = self::custom(self::NO_SPACE_BODY);

        $this->assertNoErrorChunk($chunks);
        $this->assertSame('Hello world', self::text($chunks));
    }

    public function testCustomTreatsANoSpaceDoneAsAClosedStreamNotACut(): void
    {
        // Pre-fix this ended in one transient premature-end isError chunk.
        $chunks = self::custom(self::DONE_ONLY_BODY);

        $this->assertNoErrorChunk($chunks);
        $this->assertSame('Hi', self::text($chunks));
    }

    public function testTheSpacedFramingStillParsesUnchanged(): void
    {
        $spaced = str_replace('data:', 'data: ', self::NO_SPACE_BODY);

        $this->assertSame('Hello world', self::text(self::sglang($spaced)));
        $chunks = self::custom($spaced);
        $this->assertNoErrorChunk($chunks);
        $this->assertSame('Hello world', self::text($chunks));
    }
}
