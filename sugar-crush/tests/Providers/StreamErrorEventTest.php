<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderStreamException;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\TransientFailure;

/**
 * Audit 15a A2: an OpenAI-compatible server that fails after the 200 has gone
 * out (context overflow, abort, OOM) says so with an SSE error frame. Both
 * stream loops used to drop that frame, so the turn ended as a "success"
 * holding the partial text. SglangProvider now throws; CustomProvider reports
 * one `isError` chunk - each provider's own failure convention.
 */
final class StreamErrorEventTest extends TestCase
{
    private const OVERFLOW = "The input (1100000 tokens) is longer than the model's context length";

    private static function body(string $errorFrame): string
    {
        return 'data: {"choices":[{"index":0,"delta":{"content":"Hel"}}]}' . "\n\n"
            . 'data: ' . $errorFrame . "\n\n"
            // Must never be read: the error frame ends the stream.
            . 'data: {"choices":[{"index":0,"delta":{"content":"AFTER"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";
    }

    private static function nested(int $code): string
    {
        return json_encode(['error' => [
            'object' => 'error',
            'message' => self::OVERFLOW,
            'type' => 'BadRequestError',
            'code' => $code,
        ]], JSON_THROW_ON_ERROR);
    }

    private static function topLevel(int $code): string
    {
        return json_encode(
            ['object' => 'error', 'message' => self::OVERFLOW, 'type' => 'BadRequestError', 'code' => $code],
            JSON_THROW_ON_ERROR,
        );
    }

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

    /** @return iterable<string, array{string, int, bool}> */
    public static function errorFrames(): iterable
    {
        foreach ([400 => false, 500 => true, 429 => true] as $code => $transient) {
            yield "nested error, code $code" => [self::nested($code), $code, $transient];
            yield "top-level object:error, code $code" => [self::topLevel($code), $code, $transient];
        }
    }

    #[DataProvider('errorFrames')]
    public function testSglangThrowsOnAnInStreamErrorFrame(string $frame, int $code, bool $transient): void
    {
        $provider = new SglangProvider('http://provider.invalid', 'm', null, self::client(self::body($frame)));

        $contents = [];
        try {
            foreach ($provider->completeStream(self::request()) as $chunk) {
                $contents[] = $chunk->content;
            }
            $this->fail('an in-stream error frame must not end as a successful stream; got ' . json_encode($contents));
        } catch (ProviderStreamException $e) {
            $this->assertSame('SGLANG request failed: ' . self::OVERFLOW, $e->getMessage());
            $this->assertSame($code, $e->serverCode);
            $this->assertSame($transient, TransientFailure::isTransient($e));
        }

        $this->assertSame(['Hel'], $contents, 'text before the error still streamed; nothing after it was read');
    }

    #[DataProvider('errorFrames')]
    public function testCustomReportsAnInStreamErrorFrameAsOneErrorChunk(string $frame, int $code, bool $transient): void
    {
        $provider = new CustomProvider('custom', 'http://provider.invalid', 'm', null, self::client(self::body($frame)), true, true);

        /** @var list<CompleteResponse> $chunks */
        $chunks = iterator_to_array($provider->completeStream(self::request()), false);

        $this->assertCount(2, $chunks, 'Hel, then the error chunk - nothing after the error frame is read');
        $this->assertSame('Hel', $chunks[0]->content);
        $this->assertFalse($chunks[0]->isError);

        $error = $chunks[1];
        $this->assertTrue($error->isError);
        $this->assertSame('', $error->content);
        $this->assertSame(self::OVERFLOW, $error->errorMessage);
        $this->assertSame($transient, $error->errorTransient);
        $this->assertSame($transient, TransientFailure::responseIsTransient($error));
    }
}
