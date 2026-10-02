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
use SugarCraft\Crush\Providers\ProviderStreamException;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit 15a A3: a stream that reaches EOF without the protocol's terminal
 * signal - a proxy idle-timeout, a server restart, a reset connection; Guzzle's
 * StreamHandler just reports eof() - used to come back as a complete answer
 * ("The fix is to chan"), untruncated and unretried. Each provider now reports
 * it as a TRANSIENT failure in its own convention: SglangProvider throws
 * {@see ProviderStreamException::prematureEnd()}, CustomProvider and both
 * VertexProvider arms yield one `isError` chunk. Streams that DID close
 * cleanly are pinned unchanged alongside.
 */
final class StreamPrematureEndTest extends TestCase
{
    private const CUT = 'data: {"choices":[{"index":0,"delta":{"content":"The fix is to chan"}}]}' . "\n\n";

    private const TOOL_FRAGMENT = 'data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"c1",'
        . '"function":{"name":"Read","arguments":"{\"path\":\"a.php\"}"}}]}}]}' . "\n\n";

    private static function client(string $body): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)])),
            'base_uri' => 'http://provider.invalid/',
        ]);
    }

    private static function request(string $model = 'm'): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [new UserMessage('hi')]);
    }

    private static function sglang(string $body): SglangProvider
    {
        return new SglangProvider('http://provider.invalid', 'm', null, self::client($body));
    }

    private static function custom(string $body): CustomProvider
    {
        return new CustomProvider('custom', 'http://provider.invalid', 'm', null, self::client($body), true, true);
    }

    /** @param list<array<string, mixed>> $events */
    private static function vertex(string $model, array $events): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: static fn (): array => [],
            streamer: static function () use ($events): \Generator {
                yield from $events;
            },
        );
    }

    /** @return list<CompleteResponse> */
    private static function drain(\Generator $stream): array
    {
        return iterator_to_array($stream, false);
    }

    private static function assertPrematureEndChunk(CompleteResponse $chunk, string $prefix): void
    {
        self::assertTrue($chunk->isError);
        self::assertSame('', $chunk->content);
        self::assertSame($prefix . ProviderStreamException::PREMATURE_END_MESSAGE, $chunk->errorMessage);
        self::assertTrue($chunk->errorTransient, 'a cut connection is retryable');
        self::assertTrue(TransientFailure::responseIsTransient($chunk));
    }

    public function testThePrematureEndFactoryIsAlwaysTransientAndCarriesNoServerCode(): void
    {
        $e = ProviderStreamException::prematureEnd('X: ');

        $this->assertSame('X: ' . ProviderStreamException::PREMATURE_END_MESSAGE, $e->getMessage());
        $this->assertNull($e->serverCode);
        $this->assertTrue($e->transient);
        $this->assertTrue(TransientFailure::isTransient($e));
    }

    // ---- SglangProvider: throws -------------------------------------------

    public function testSglangThrowsATransientFailureWhenTheStreamIsCut(): void
    {
        $contents = [];
        try {
            foreach (self::sglang(self::CUT)->completeStream(self::request()) as $chunk) {
                $contents[] = $chunk->content;
            }
            $this->fail('a cut stream must not end as a complete answer; got ' . json_encode($contents));
        } catch (ProviderStreamException $e) {
            $this->assertSame('SGLANG request failed: ' . ProviderStreamException::PREMATURE_END_MESSAGE, $e->getMessage());
            $this->assertTrue(TransientFailure::isTransient($e));
        }

        $this->assertSame(['The fix is to chan'], $contents, 'what streamed before the cut still streamed');
    }

    public function testSglangDoesNotExecuteAToolCallSalvagedFromACutStream(): void
    {
        $toolCalls = [];
        try {
            foreach (self::sglang(self::TOOL_FRAGMENT)->completeStream(self::request()) as $chunk) {
                $toolCalls = array_merge($toolCalls, $chunk->toolCalls ?? []);
            }
            $this->fail('a cut stream must throw before the §Q7 flush');
        } catch (ProviderStreamException $e) {
            $this->assertTrue(TransientFailure::isTransient($e));
        }

        $this->assertSame([], $toolCalls, 'the retry yields the whole call; a salvaged one never runs');
    }

    public function testSglangStillFlushesAToolCallWhenDoneArrivesWithoutAFinishReason(): void
    {
        $chunks = self::drain(self::sglang(self::TOOL_FRAGMENT . "data: [DONE]\n\n")->completeStream(self::request()));

        $flushed = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->toolCalls !== null && $c->toolCalls !== []));
        $this->assertCount(1, $flushed, 'the server closed the stream, so the §Q7 null-arm flush is unchanged');
        $this->assertTrue($flushed[0]->truncated);
        $this->assertSame('Read', $flushed[0]->toolCalls[0]->name());
    }

    public function testSglangTreatsDoneWithoutAFinishReasonAsAClosedStream(): void
    {
        $chunks = self::drain(self::sglang(self::CUT . "data: [DONE]\n\n")->completeStream(self::request()));

        $this->assertSame(['The fix is to chan'], array_map(static fn (CompleteResponse $c): string => $c->content, $chunks));
        $this->assertFalse($chunks[0]->truncated);
    }

    public function testSglangReadsADoneSentinelThatHasNoTrailingNewline(): void
    {
        $chunks = self::drain(self::sglang(self::CUT . 'data: [DONE]')->completeStream(self::request()));

        $this->assertSame(['The fix is to chan'], array_map(static fn (CompleteResponse $c): string => $c->content, $chunks));
    }

    public function testSglangTreatsAFinishReasonWithoutDoneAsAClosedStream(): void
    {
        $body = self::CUT . 'data: {"choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}' . "\n\n";

        $chunks = self::drain(self::sglang($body)->completeStream(self::request()));

        $this->assertSame('The fix is to chan', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));
    }

    // ---- CustomProvider: one isError chunk --------------------------------

    public function testCustomReportsACutStreamAsOneTransientErrorChunk(): void
    {
        $chunks = self::drain(self::custom(self::CUT)->completeStream(self::request()));

        $this->assertCount(2, $chunks, 'the streamed text, then the error chunk');
        $this->assertSame('The fix is to chan', $chunks[0]->content);
        $this->assertFalse($chunks[0]->isError);
        self::assertPrematureEndChunk($chunks[1], '');
    }

    public function testCustomTreatsDoneOrAFinishReasonAsAClosedStream(): void
    {
        $finish = 'data: {"choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}' . "\n\n";

        foreach (['[DONE] only' => "data: [DONE]\n\n", 'finish_reason only' => $finish] as $label => $tail) {
            $chunks = self::drain(self::custom(self::CUT . $tail)->completeStream(self::request()));

            foreach ($chunks as $chunk) {
                $this->assertFalse($chunk->isError, $label . ' is a clean end');
            }
        }
    }

    // ---- VertexProvider: Anthropic arm ------------------------------------

    private const ANTHROPIC_MODEL = 'claude-3-sonnet@20240229';

    private const GEMINI_MODEL = 'gemini-1.5-pro-002';

    /** @return list<array<string, mixed>> */
    private static function anthropicPrefix(): array
    {
        return [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'The fix is to chan']],
        ];
    }

    public function testVertexAnthropicReportsAStreamWithoutMessageStopAsATransientErrorChunk(): void
    {
        $chunks = self::drain(self::vertex(self::ANTHROPIC_MODEL, self::anthropicPrefix())->completeStream(self::request(self::ANTHROPIC_MODEL)));

        $this->assertSame('The fix is to chan', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));
        self::assertPrematureEndChunk($chunks[array_key_last($chunks)], 'Vertex streamRawPredict: ');
        $this->assertCount(1, array_filter($chunks, static fn (CompleteResponse $c): bool => $c->isError));
    }

    public function testVertexAnthropicTreatsMessageStopOrAStopReasonAsAClosedStream(): void
    {
        $endings = [
            'message_stop' => [['type' => 'message_stop']],
            'message_delta stop_reason' => [['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]],
        ];

        foreach ($endings as $label => $tail) {
            $events = [...self::anthropicPrefix(), ['type' => 'content_block_stop', 'index' => 0], ...$tail];
            $chunks = self::drain(self::vertex(self::ANTHROPIC_MODEL, $events)->completeStream(self::request(self::ANTHROPIC_MODEL)));

            foreach ($chunks as $chunk) {
                $this->assertFalse($chunk->isError, $label . ' is a clean end');
            }
        }
    }

    public function testVertexAnthropicDoesNotStackACutOnTopOfAnErrorEvent(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12, 'output_tokens' => 0]]],
            ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key']],
        ];

        $errors = array_values(array_filter(
            self::drain(self::vertex(self::ANTHROPIC_MODEL, $events)->completeStream(self::request(self::ANTHROPIC_MODEL))),
            static fn (CompleteResponse $c): bool => $c->isError,
        ));

        $this->assertCount(1, $errors, 'the error event already ends the turn; a transient cut would overwrite its verdict');
        $this->assertSame('bad key', $errors[0]->errorMessage);
        $this->assertFalse($errors[0]->errorTransient);
    }

    // ---- VertexProvider: Gemini arm ---------------------------------------

    public function testVertexGeminiReportsAStreamWithoutFinishReasonAsATransientErrorChunk(): void
    {
        $events = [['candidates' => [['content' => ['parts' => [['text' => 'The fix is to chan']]]]]]];

        $chunks = self::drain(self::vertex(self::GEMINI_MODEL, $events)->completeStream(self::request(self::GEMINI_MODEL)));

        $this->assertCount(2, $chunks);
        $this->assertSame('The fix is to chan', $chunks[0]->content);
        self::assertPrematureEndChunk($chunks[1], 'Vertex streamGenerateContent: ');
    }

    public function testVertexGeminiTreatsAFinishReasonAsAClosedStream(): void
    {
        $events = [
            ['candidates' => [['content' => ['parts' => [['text' => 'The fix is ']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'done.']]], 'finishReason' => 'STOP']]],
        ];

        $chunks = self::drain(self::vertex(self::GEMINI_MODEL, $events)->completeStream(self::request(self::GEMINI_MODEL)));

        $this->assertSame(['The fix is ', 'done.'], array_map(static fn (CompleteResponse $c): string => $c->content, $chunks));
    }

    public function testVertexGeminiDoesNotStackACutOnTopOfABlockedPrompt(): void
    {
        $events = [['promptFeedback' => ['blockReason' => 'SAFETY']]];

        $chunks = self::drain(self::vertex(self::GEMINI_MODEL, $events)->completeStream(self::request(self::GEMINI_MODEL)));

        $this->assertCount(1, $chunks, 'the block already ends the turn; a transient cut would make it retryable');
        $this->assertTrue($chunks[0]->isError);
        $this->assertFalse($chunks[0]->errorTransient);
    }
}
