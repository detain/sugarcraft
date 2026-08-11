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
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tools\Tool;

/**
 * Regression coverage for §12 D4 of crush_feat.md: `SglangProvider`'s entire
 * outgoing request surface used to be `model`/`messages`/`temperature`/
 * `max_tokens`/`tools` (+ `stream`). None of SGLang's sampling knobs were
 * reachable, and `CompleteRequest::$jsonSchema` was silently dropped on every
 * request while `supportsJsonSchema()` returned a hardcoded `false`.
 *
 * Every assertion below that reads a `top_p`/`top_k`/`min_p`/
 * `repetition_penalty`/`stop`/`chat_template_kwargs`/`json_schema` key would
 * fail against that old body-building code, which never emitted them.
 */
final class SglangProviderRequestBuildingTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    private function provider(string $body = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    // -------------------------------------------------------------------------
    // Optional sampling knobs are absent unless the caller sets them - sending
    // an implicit value would override the server's launch-time default.
    // -------------------------------------------------------------------------

    public function testDefaultRequestOmitsEveryOptionalSamplingKnob(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        foreach (['top_p', 'top_k', 'min_p', 'repetition_penalty', 'stop'] as $key) {
            $this->assertArrayNotHasKey($key, $sent);
        }
        $this->assertArrayNotHasKey('chat_template_kwargs', $sent);
        $this->assertArrayNotHasKey('response_format', $sent);
    }

    public function testDefaultRequestStillSendsTheBaselineParams(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        $this->assertSame('MiniMax-M2.7', $sent['model']);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $sent['messages']);
        $this->assertSame(0.7, $sent['temperature']);
        $this->assertSame(4096, $sent['max_tokens']);
        $this->assertTrue($sent['separate_reasoning']);
        // `extra_body` is an OpenAI Python-SDK concept the SDK flattens before
        // sending; a literal nested object is silently ignored by SGLang, so
        // the wire body must never contain one. Verified live 2026-08-10.
        $this->assertArrayNotHasKey('extra_body', $sent);
    }

    // -------------------------------------------------------------------------
    // §12 D4: top_p / top_k / min_p / repetition_penalty / stop.
    // -------------------------------------------------------------------------

    public function testSamplingKnobsAreSentAtTopLevelWhenSet(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            temperature: 0.2,
            maxTokens: 256,
            topP: 0.95,
            topK: 40,
            minP: 0.05,
            repetitionPenalty: 1.1,
            stop: ['</done>', "\n\n"],
        ));

        $sent = $this->sentBody();

        $this->assertSame(0.2, $sent['temperature']);
        $this->assertSame(256, $sent['max_tokens']);
        $this->assertSame(0.95, $sent['top_p']);
        $this->assertSame(40, $sent['top_k']);
        $this->assertSame(0.05, $sent['min_p']);
        $this->assertSame(1.1, $sent['repetition_penalty']);
        $this->assertSame(['</done>', "\n\n"], $sent['stop']);
    }

    public function testStopAcceptsASingleString(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            stop: 'STOP',
        ));

        $this->assertSame('STOP', $this->sentBody()['stop']);
    }

    public function testZeroValuedKnobsAreStillSent(): void
    {
        // The invariant is the filter, not the value: only `null` means "defer
        // to the server", so any value the caller actually set - `0`/`0.0`
        // included - must survive and reach the wire. Whether the server then
        // accepts that particular number is its business to answer with a 400,
        // not ours to pre-empt with a falsy filter. (`top_k: -1` below is
        // SGLang's disable sentinel; `0` is out of its accepted range, which is
        // exactly why it must not be silently swallowed here.)
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            temperature: 0.0,
            topK: -1,
            minP: 0.0,
        ));

        $sent = $this->sentBody();

        // json_encode() renders 0.0 as "0", so these come back as ints - the
        // point of the assertion is that the keys are present at all.
        $this->assertEqualsWithDelta(0.0, $sent['temperature'], 0.0);
        $this->assertSame(-1, $sent['top_k']);
        $this->assertArrayHasKey('min_p', $sent);
        $this->assertEqualsWithDelta(0.0, $sent['min_p'], 0.0);
    }

    // -------------------------------------------------------------------------
    // §12 D4: chat_template_kwargs passthrough (top level - SGLang rejects a
    // bad value there with a 400, proving it is a real request field).
    // -------------------------------------------------------------------------

    public function testChatTemplateKwargsTravelAtTopLevel(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            extraTemplateKwargs: ['enable_thinking' => true],
        ));

        $sent = $this->sentBody();

        $this->assertSame(['enable_thinking' => true], $sent['chat_template_kwargs']);
        $this->assertTrue($sent['separate_reasoning']);
    }

    public function testEmptyChatTemplateKwargsAreOmitted(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            extraTemplateKwargs: [],
        ));

        $this->assertArrayNotHasKey('chat_template_kwargs', $this->sentBody());
    }

    // -------------------------------------------------------------------------
    // §12 D4: the jsonSchema DTO field finally reaches SGLang's constrained
    // decoding instead of being silently dropped. It goes through
    // `response_format` - a top-level `json_schema` string is NOT a field on
    // the OpenAI-compatible route and leaves decoding unconstrained (both
    // halves verified against the live deployment 2026-08-10).
    // -------------------------------------------------------------------------

    public function testArrayJsonSchemaBecomesAResponseFormat(): void
    {
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];

        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            jsonSchema: $schema,
        ));

        $sent = $this->sentBody();

        $this->assertSame('json_schema', $sent['response_format']['type']);
        $this->assertSame($schema, $sent['response_format']['json_schema']['schema']);
        $this->assertArrayNotHasKey('json_schema', $sent);
        $this->assertArrayNotHasKey('extra_body', $sent);
    }

    public function testStringJsonSchemaIsDecodedIntoTheResponseFormat(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            jsonSchema: '{"type":"object"}',
        ));

        // Decoded, not passed through verbatim: response_format wants an
        // object, and a JSON string there is rejected by the server.
        $this->assertSame(
            ['type' => 'object'],
            $this->sentBody()['response_format']['json_schema']['schema'],
        );
    }

    public function testMalformedPreEncodedJsonSchemaThrowsInsteadOfShippingNull(): void
    {
        $provider = $this->provider();

        $this->expectException(\JsonException::class);

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            jsonSchema: '{"type":"object"',
        ));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scalarJsonSchemaProvider(): array
    {
        return ['null' => ['null'], 'int' => ['123'], 'bool' => ['false']];
    }

    #[DataProvider('scalarJsonSchemaProvider')]
    public function testPreEncodedScalarJsonSchemaThrowsInsteadOfShippingAScalarSchema(string $encoded): void
    {
        // These are all syntactically valid JSON, so JSON_THROW_ON_ERROR lets
        // them through - `'null'` in particular would ship `schema: null`, a
        // 200 response with decoding silently unconstrained.
        $provider = $this->provider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must decode to a JSON object/');

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            jsonSchema: $encoded,
        ));
    }

    public function testUnencodableArrayJsonSchemaSurfacesAnErrorAtTheCallSite(): void
    {
        // Invalid UTF-8 makes the body unserialisable. Guzzle's `json` option
        // raises on the encode, which this provider re-wraps as its usual
        // RuntimeException - the point is that it blows up rather than quietly
        // shipping a broken/absent schema while the request still returns 200.
        $provider = $this->provider();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Malformed UTF-8/');

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            jsonSchema: ['bad' => "\xB1\x31"],
        ));
    }

    /**
     * @return array<string, array{0: string|array<mixed>}>
     */
    public static function emptyJsonSchemaProvider(): array
    {
        // Every shape a caller can express "I have no schema" in. The array and
        // pre-encoded-object forms are the same intent and must land the same
        // way - `'{}'` used to be the odd one out, decoding to `[]` and then
        // throwing where the literal `[]` was a silent no-op.
        return [
            'empty string' => [''],
            'empty array' => [[]],
            'encoded empty object' => ['{}'],
            'encoded empty object with space' => ['{ }'],
        ];
    }

    #[DataProvider('emptyJsonSchemaProvider')]
    public function testEmptyJsonSchemaIsTreatedAsUnset(string|array $schema): void
    {
        // An empty grammar is not an instruction; null and empty both mean
        // "defer to the server".
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            jsonSchema: $schema,
        ));

        $this->assertArrayNotHasKey('response_format', $this->sentBody());
    }

    public function testEmptyStopListIsOmitted(): void
    {
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            stop: [],
        ));

        $this->assertArrayNotHasKey('stop', $this->sentBody());
    }

    public function testSupportsJsonSchemaIsNowTrue(): void
    {
        $this->assertTrue($this->provider()->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // Tools keep their existing OpenAI shape alongside the new params.
    // -------------------------------------------------------------------------

    public function testToolsAreStillFormattedAlongsideTheNewParams(): void
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('read_file');
        $tool->method('description')->willReturn('Read a file');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);

        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            tools: [$tool],
            topP: 0.9,
        ));

        $sent = $this->sentBody();

        $this->assertSame('function', $sent['tools'][0]['type']);
        $this->assertSame('read_file', $sent['tools'][0]['function']['name']);
        $this->assertSame(0.9, $sent['top_p']);
    }

    // -------------------------------------------------------------------------
    // completeStream() shares the same body builder - the streaming path used
    // to duplicate the literal param array, so it could drift silently.
    // -------------------------------------------------------------------------

    public function testCompleteStreamSendsTheSameParamsPlusStream(): void
    {
        $provider = $this->provider("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\ndata: [DONE]\n");

        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            topP: 0.8,
            minP: 0.02,
            repetitionPenalty: 1.05,
            stop: 'END',
            jsonSchema: ['type' => 'object'],
            extraTemplateKwargs: ['enable_thinking' => false],
        )));

        $sent = $this->sentBody();

        $this->assertTrue($sent['stream']);
        $this->assertSame(0.8, $sent['top_p']);
        $this->assertSame(0.02, $sent['min_p']);
        $this->assertSame(1.05, $sent['repetition_penalty']);
        $this->assertSame('END', $sent['stop']);
        $this->assertSame(['type' => 'object'], $sent['response_format']['json_schema']['schema']);
        $this->assertSame(['enable_thinking' => false], $sent['chat_template_kwargs']);
        $this->assertTrue($sent['separate_reasoning']);
        $this->assertArrayNotHasKey('extra_body', $sent);
    }
}
