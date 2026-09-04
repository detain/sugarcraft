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

    /**
     * Same mock harness as {@see provider()} but with the model under the
     * caller's control, because the sampling defaults below are MODEL-KEYED
     * and a fixed 'MiniMax-M2.7' cannot exercise them.
     */
    private function providerForModel(string $model, string|float|null $reasoningEffort = null): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'),
        ]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            $model,
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
            null,
            $reasoningEffort,
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

    // -------------------------------------------------------------------------
    // Model-aware sampling + reasoning_effort.
    //
    // The default model became `deepseek-ai/DeepSeek-V4-Flash-0731` because
    // the confirmed skynet2 deployment was switched to it and MiniMax-M2.7 is
    // GONE from that server. DeepSeek-V4-Flash's card prescribes
    // temperature 1.0 always and top_p 0.95 agentic / 1.0 otherwise, and the
    // user's instruction is reasoning_effort `max` for this model.
    //
    // EVERY assertion in this block is about ONE model family. The MiniMax
    // tests immediately after it exist to prove the flip did not retune the
    // other one - which was the explicit constraint on this change, not a
    // nicety.
    // -------------------------------------------------------------------------

    private const DEEPSEEK = 'deepseek-ai/DeepSeek-V4-Flash-0731';

    /** Qwen3.8's canonical served id per qwen.md E-70 (probe-verified form). */
    private const QWEN = 'Qwen/Qwen3.8-Flash-Next';

    /** @return array<Tool> */
    private function oneTool(): array
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get weather');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);

        return [$tool];
    }

    public function testDeepSeekV4WithToolsGetsCardTemperatureAgenticTopPAndMaxEffort(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(
            model: self::DEEPSEEK,
            messages: [new UserMessage('Hi')],
            tools: $this->oneTool(),
        ));

        $sent = $this->sentBody();

        $this->assertEqualsWithDelta(1.0, $sent['temperature'], 0.0);
        // 0.95 is the card's AGENTIC figure, and "agentic" is defined here as
        // "the request offered tools" - which this one did.
        $this->assertSame(0.95, $sent['top_p']);
        $this->assertSame('max', $sent['reasoning_effort']);
        // Top level, not nested: chat_template_kwargs feeds a server-side Jinja
        // template and this model ships none, so an effort routed through it
        // would be silently dropped.
        $this->assertArrayNotHasKey('chat_template_kwargs', $sent);
    }

    public function testDeepSeekV4WithoutToolsGetsTheNonAgenticTopP(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        $this->assertEqualsWithDelta(1.0, $sent['top_p'], 0.0);
        $this->assertNotEquals(0.95, $sent['top_p']);
        // Temperature does NOT vary by scenario - the card states 1.0
        // unconditionally, so only top_p is scenario-split.
        $this->assertEqualsWithDelta(1.0, $sent['temperature'], 0.0);
    }

    public function testAnEmptyToolsArrayIsNotAgentic(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        // `tools: []` is what a caller with a tool registry that happens to be
        // empty sends. It offers the model nothing, so it must not select the
        // agentic figure - and it must not emit a `tools` key either.
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')], tools: []));

        $sent = $this->sentBody();

        $this->assertEqualsWithDelta(1.0, $sent['top_p'], 0.0);
    }

    /**
     * PHP's `json_encode()` writes the float 1.0 as the JSON integer `1` (no
     * JSON_PRESERVE_ZERO_FRACTION anywhere in Guzzle's `json` option), so this
     * is what the DeepSeek-V4 defaults actually put on the wire. Asserted on
     * the raw body rather than the decoded array because the decode hides it.
     *
     * Harmless, and that is MEASURED not assumed: POSTing
     * `{"temperature":1,"top_p":1}` to skynet2 on 2026-08-20 returned 200 -
     * SGLang's pydantic model coerces an int into its float fields. It is
     * pinned here so a future reader who sees `"temperature":1` in a capture
     * knows it is the encoder, not a lost decimal.
     */
    public function testTheOnePointZeroDefaultsGoOnTheWireAsJsonIntegers(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')]));

        $raw = (string) $this->history[0]['request']->getBody();

        $this->assertStringContainsString('"temperature":1,', $raw);
        $this->assertStringContainsString('"top_p":1', $raw);
        $this->assertStringNotContainsString('"temperature":1.0', $raw);
    }

    public function testTheFamilyTokenMatchesCaseInsensitivelyAndWithoutTheOrgPrefix(): void
    {
        $provider = $this->providerForModel('DeepSeek-V4-Flash');
        $provider->complete(new CompleteRequest(model: 'DeepSeek-V4-Flash', messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        $this->assertEqualsWithDelta(1.0, $sent['temperature'], 0.0);
        $this->assertSame('max', $sent['reasoning_effort']);
    }

    public function testADifferentDeepSeekGenerationIsNotTreatedAsV4(): void
    {
        // The negative that makes the family token meaningful. DeepSeek-V3 and
        // R1 publish DIFFERENT recommended temperatures, so matching the bare
        // vendor name `deepseek` would apply V4's 1.0 / 0.95 / max to models
        // they were never measured on.
        $provider = $this->providerForModel('deepseek-ai/DeepSeek-V3');
        $provider->complete(new CompleteRequest(model: 'deepseek-ai/DeepSeek-V3', messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        $this->assertSame(0.7, $sent['temperature']);
        $this->assertArrayNotHasKey('top_p', $sent);
        $this->assertArrayNotHasKey('reasoning_effort', $sent);
    }

    /**
     * WHERE THE FAMILY TOKEN'S LINE ACTUALLY FALLS, in both directions and in
     * one test, because the boundary is the claim - either list alone passes
     * against a token that is too broad or too narrow.
     *
     * The OVER-MATCH half is asserted deliberately, not tolerated. The
     * constant's own docblock argues per-GENERATION ("V3 and R1 publish
     * DIFFERENT temperatures"), and a substring cannot honour that: `V4.5` and
     * `V4.1-Flash` were never measured either, yet they take the V4-Flash arm.
     * That trade is accepted because a MISS costs `reasoning_effort`, measured
     * on this deployment to put the model's thinking into `content` silently.
     * Pinning it means replacing the token can never be an accident.
     *
     * The UNDER-MATCH half is the aliased-deployment hazard: an SGLang server
     * launched `--served-model-name default` reports that alias, and an
     * operator copying it in gets the legacy arm while talking to DeepSeek-V4.
     *
     * @dataProvider familyTokenBoundaryProvider
     */
    public function testTheFamilyTokenBoundaryIsExactlyThisWideInBothDirections(
        string $model,
        bool $expectedV4,
    ): void {
        $provider = $this->providerForModel($model);
        $provider->complete(new CompleteRequest(model: $model, messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        // All four behaviours, so a partial gate cannot pass: a token that
        // matched for temperature but not for effort would fail here.
        if ($expectedV4) {
            $this->assertEqualsWithDelta(1.0, $sent['temperature'], 0.0, $model);
            $this->assertArrayHasKey('top_p', $sent, $model);
            $this->assertSame('max', $sent['reasoning_effort'], $model);
            $this->assertSame(1_048_570, $provider->contextWindow(), $model);

            return;
        }

        $this->assertSame(0.7, $sent['temperature'], $model);
        $this->assertArrayNotHasKey('top_p', $sent, $model);
        $this->assertArrayNotHasKey('reasoning_effort', $sent, $model);
        $this->assertSame(196_608, $provider->contextWindow(), $model);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function familyTokenBoundaryProvider(): array
    {
        return [
            // IN, as intended.
            'the deployed id' => ['deepseek-ai/DeepSeek-V4-Flash-0731', true],
            // IN, beyond anything measured - the accepted over-match.
            'a future point release' => ['deepseek-ai/DeepSeek-V4.1-Flash', true],
            'a future minor version' => ['DeepSeek-V4.5', true],
            'a longer version number' => ['deepseek-v40', true],
            // OUT, as intended - the reason the token is not bare `deepseek`.
            'an earlier generation' => ['deepseek-ai/DeepSeek-V3', false],
            'a reasoning sibling' => ['deepseek-r1', false],
            'the legacy default' => ['MiniMax-M2.7', false],
            // OUT, and this is the aliased-deployment under-match.
            'a served-model-name alias' => ['default', false],
            'a generic local alias' => ['local-model', false],
            'an abbreviation' => ['dsv4', false],
            'an underscored family name' => ['deepseek_v4', false],
        ];
    }

    public function testMiniMaxKeepsItsHistoricalSamplingAndGetsNoReasoningEffort(): void
    {
        // THE "keep both working" PIN. MiniMax-M2.x was running on 0.7 with no
        // top_p and no reasoning_effort in any request, and no measurement of
        // what an effort level does to it exists - so sending one on the
        // strength of a DeepSeek measurement would change a working deployment
        // blind. Making DeepSeek the default must not do that.
        $provider = $this->providerForModel('MiniMax-M2.7');
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hi')],
            tools: $this->oneTool(),
        ));

        $sent = $this->sentBody();

        $this->assertSame(0.7, $sent['temperature']);
        $this->assertArrayNotHasKey('top_p', $sent);
        $this->assertArrayNotHasKey('reasoning_effort', $sent);
        // Everything MiniMax DID get still arrives.
        $this->assertTrue($sent['separate_reasoning']);
        $this->assertCount(1, $sent['tools']);
    }

    public function testQwen3NextKeepsLegacySamplingAndGetsXHighEffort(): void
    {
        // Q2's family arm for Qwen3.8-Next, pinned with the E-61 shape: this
        // model takes the LEGACY sampling defaults, NOT DeepSeek's card
        // numbers, and the only new thing it gets is a tier-3 effort default.
        $provider = $this->providerForModel(self::QWEN);
        $provider->complete(new CompleteRequest(
            model: self::QWEN,
            messages: [new UserMessage('Hi')],
            tools: $this->oneTool(),
        ));

        $sent = $this->sentBody();

        // 0.7 because that is the value the code ACTUALLY produces here:
        // defaultTemperature()'s non-V4 arm is LEGACY_DEFAULT_TEMPERATURE,
        // which IS 0.7 — so E-61's measurement and the pre-existing default
        // coincide and no sampling code had to change. Pinned against a
        // future "Qwen card" edit that would retune a measured deployment.
        $this->assertSame(0.7, $sent['temperature']);
        // top_p is a DeepSeek-card-only knob; non-V4 models keep it absent so
        // the server's launch-time default wins (E-61, defaultTopP null arm).
        $this->assertArrayNotHasKey('top_p', $sent);
        // Tier-3 model default (nothing named in request or config): 'xhigh',
        // NOT DeepSeek's 'max' — the template accepts exactly xhigh|medium|low
        // and 'max' 400s at the template whenever thinking is on (qwen.md
        // E-40/E-41). 'xhigh' doubles as the template's own default (E-40).
        $this->assertSame('xhigh', $sent['reasoning_effort']);
        // No DeepSeek-only or not-yet-wired params ride along: kwargs
        // placement is Q3/Q4 territory, today nothing may emit the key here.
        $this->assertArrayNotHasKey('chat_template_kwargs', $sent);
        // The window arm lives on the configured model; asserted beside the
        // body so the family's full request-shape lives in one leg.
        $this->assertSame(744_506, $provider->contextWindow());
    }

    public function testAnEarlierQwenGenerationIsNotTreatedAsQwen3Next(): void
    {
        // The negative that makes the `qwen3.8` token meaningful, mirroring
        // testADifferentDeepSeekGenerationIsNotTreatedAsV4(): Qwen3-235B
        // shares the vendor name but was never measured against this
        // deployment, so matching bare `qwen` would silently hand it an
        // 'xhigh' effort and the conservative window of a server it does not
        // live on (E-70/E-71). It must keep the untouched legacy behaviour.
        $provider = $this->providerForModel('Qwen3-235B');
        $provider->complete(new CompleteRequest(model: 'Qwen3-235B', messages: [new UserMessage('Hi')]));

        $sent = $this->sentBody();

        $this->assertSame(0.7, $sent['temperature']);
        $this->assertArrayNotHasKey('top_p', $sent);
        $this->assertArrayNotHasKey('reasoning_effort', $sent);
        $this->assertSame(196_608, $provider->contextWindow());
    }

    public function testCallerSuppliedSamplingBeatsTheModelDefaults(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(
            model: self::DEEPSEEK,
            messages: [new UserMessage('Hi')],
            tools: $this->oneTool(),
            temperature: 0.2,
            topP: 0.5,
        ));

        $sent = $this->sentBody();

        $this->assertSame(0.2, $sent['temperature']);
        $this->assertSame(0.5, $sent['top_p']);
    }

    public function testAnExplicitZeroTopPSurvivesTheModelDefault(): void
    {
        // The `??` in `$request->topP ?? self::defaultTopP(...)` must key off
        // NULL, not falsiness: 0.0 is a meaningful top_p and a `?:` here would
        // silently replace it with the model default.
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')], topP: 0.0));

        $this->assertEqualsWithDelta(0.0, $this->sentBody()['top_p'], 0.0);
    }

    // ---- reasoning_effort: three tiers, request > provider config > model ----

    public function testRequestLevelReasoningEffortBeatsTheModelDefault(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(
            model: self::DEEPSEEK,
            messages: [new UserMessage('Hi')],
            reasoningEffort: 'low',
        ));

        $this->assertSame('low', $this->sentBody()['reasoning_effort']);
    }

    public function testProviderConfiguredReasoningEffortIsUsedWhenTheRequestNamesNone(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK, 'medium');
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')]));

        // 'medium' is one of the four names the DeepSeek-V4-Flash CARD does not
        // list; it is accepted because the SERVER's own pydantic literal does.
        $this->assertSame('medium', $this->sentBody()['reasoning_effort']);
    }

    public function testRequestLevelReasoningEffortBeatsTheProviderConfiguredOne(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK, 'minimal');
        $provider->complete(new CompleteRequest(
            model: self::DEEPSEEK,
            messages: [new UserMessage('Hi')],
            reasoningEffort: 'xhigh',
        ));

        $this->assertSame('xhigh', $this->sentBody()['reasoning_effort']);
    }

    public function testProviderConfiguredEffortReachesAModelWithNoDefaultOfItsOwn(): void
    {
        // MiniMax gets no effort by DEFAULT, but an operator who names one in
        // config must still be able to send it - the model default is a
        // default, not a veto.
        $provider = $this->providerForModel('MiniMax-M2.7', 'high');
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]));

        $this->assertSame('high', $this->sentBody()['reasoning_effort']);
    }

    /**
     * All seven names the deployed server's pydantic literal accepts. The
     * DeepSeek-V4-Flash card names only low/high/max; measured 2026-08-20, the
     * other four return 200 (`minimal` -> 12 reasoning tokens, `medium` -> 22,
     * `none` -> 0), so narrowing to the card's three would refuse values this
     * server demonstrably serves.
     */
    #[DataProvider('serverAcceptedEffortLevels')]
    public function testEveryServerAcceptedLevelNameIsForwardedVerbatim(string $level): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(
            model: self::DEEPSEEK,
            messages: [new UserMessage('Hi')],
            reasoningEffort: $level,
        ));

        $this->assertSame($level, $this->sentBody()['reasoning_effort']);
    }

    /** @return array<string, array{string}> */
    public static function serverAcceptedEffortLevels(): array
    {
        return [
            'none' => ['none'],
            'minimal' => ['minimal'],
            'low' => ['low'],
            'medium' => ['medium'],
            'high' => ['high'],
            'xhigh' => ['xhigh'],
            'max' => ['max'],
        ];
    }

    public function testAFloatEffortIsForwardedAndNotRangeCheckedLocally(): void
    {
        // The server also accepts a constrained float, measured 2026-08-20 as
        // 0.0 through 0.99 inclusive (1.0 is rejected with `le: 0.99`). That
        // bound is SGLang's, so 5.0 is forwarded and fails at the server rather
        // than being refused here against a number that will decay.
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')], reasoningEffort: 5.0));

        // 5 not 5.0 on the decoded body: see
        // testTheOnePointZeroDefaultsGoOnTheWireAsJsonIntegers() - the encoder
        // drops a zero fraction. The value still leaves this class unclamped,
        // which is what this test is about.
        $this->assertEqualsWithDelta(5.0, $this->sentBody()['reasoning_effort'], 0.0);
    }

    public function testAZeroFloatEffortSurvivesTheOptionalKnobFilter(): void
    {
        // buildParams() drops null/[]/'' from its optional-knob loop. 0.0 is a
        // valid float effort (accepted 200 by the server) and must not be
        // mistaken for "unset" by a loose comparison.
        $provider = $this->providerForModel(self::DEEPSEEK);
        $provider->complete(new CompleteRequest(model: self::DEEPSEEK, messages: [new UserMessage('Hi')], reasoningEffort: 0.0));

        $sent = $this->sentBody();
        $this->assertArrayHasKey('reasoning_effort', $sent);
        $this->assertEqualsWithDelta(0.0, $sent['reasoning_effort'], 0.0);
    }

    public function testAnUnknownEffortLevelThrowsBeforeAnyRequestIsSent(): void
    {
        $provider = $this->providerForModel(self::DEEPSEEK);

        try {
            $provider->complete(new CompleteRequest(
                model: self::DEEPSEEK,
                messages: [new UserMessage('Hi')],
                reasoningEffort: 'maximum',
            ));
            $this->fail('an unknown reasoning_effort must not reach the wire');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'maximum'", $e->getMessage());
            $this->assertStringContainsString('xhigh', $e->getMessage());
            $this->assertStringContainsString('CompleteRequest', $e->getMessage());
        }

        // The point of throwing locally rather than letting the server 400:
        // nothing was sent, so there is no request to explain.
        $this->assertSame([], $this->history);
    }

    public function testAnUnknownConfiguredEffortThrowsWhenTheProviderIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('provider config');

        $this->providerForModel(self::DEEPSEEK, 'aggressive');
    }

    public function testTheEmptyStringIsRejectedAtTheRequestLevel(): void
    {
        // '' means "env placeholder was unset" only where env placeholders are
        // resolved, i.e. ProviderFactory. A DTO field set to '' by a caller has
        // no such origin and is a bug.
        $this->expectException(\InvalidArgumentException::class);

        $this->providerForModel(self::DEEPSEEK)->complete(new CompleteRequest(
            model: self::DEEPSEEK,
            messages: [new UserMessage('Hi')],
            reasoningEffort: '',
        ));
    }

    // -------------------------------------------------------------------------
    // P1.S1: CompleteRequest::$systemPrompt reaches the wire as the leading
    // system message. Runtime::buildSystemPrompt() assembles the seven-layer
    // prompt into that field, and this provider used to never read it - the
    // assembled prompt silently dropped on the DEFAULT provider. Both paths
    // share buildParams(), yet each is asserted independently: "complete()
    // passes" was exactly the conflation that hid the same bug in
    // OpenAIProvider::completeStream().
    // -------------------------------------------------------------------------

    public function testSystemPromptIsPrependedToTheCompletePayload(): void
    {
        // Multi-line with quotes and a leading blank line, so byte-identity
        // actually means something (assertSame on the content).
        $prompt = "\nYou are a coding assistant.\nRules:\n1. Never invent facts.\n2. Reply with \"done\" when finished.\n";

        $provider = $this->providerForModel(SglangProvider::DEFAULT_MODEL);
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: $prompt,
        ));

        $sent = $this->sentBody();

        $this->assertSame('system', $sent['messages'][0]['role']);
        $this->assertSame($prompt, $sent['messages'][0]['content']);
        // Prepended, not replacing: the original message still follows.
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
    }

    public function testSystemPromptIsPrependedToTheStreamingPayload(): void
    {
        $prompt = "You are a streaming assistant.\nDo not stall.\n";

        $provider = $this->provider("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\ndata: [DONE]\n");

        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: $prompt,
        )));

        $sent = $this->sentBody();

        $this->assertSame('system', $sent['messages'][0]['role']);
        $this->assertSame($prompt, $sent['messages'][0]['content']);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
    }

    public function testNullSystemPromptPrependsNothing(): void
    {
        $provider = $this->providerForModel(SglangProvider::DEFAULT_MODEL);
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: null,
        ));

        // Exact shape: no system element may appear, empty or otherwise.
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->sentBody()['messages']);
    }

    public function testEmptyStringSystemPromptPrependsNothing(): void
    {
        // '' is "unset" for this provider, matching the optional-knob filter
        // and VertexProvider's hoist: an empty system message is not an
        // instruction, so it must not be manufactured onto the wire.
        $provider = $this->providerForModel(SglangProvider::DEFAULT_MODEL);
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: '',
        ));

        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->sentBody()['messages']);
    }
}
