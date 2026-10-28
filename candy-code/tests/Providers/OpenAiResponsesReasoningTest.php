<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-PRV-2.b: the two replay legs the Responses arm unlocked.
 *
 * (1) ENCRYPTED REASONING ITEMS — the arm now asks for them
 * (`reasoning.summary:auto` + `include:["reasoning.encrypted_content"]`,
 * both inside C-PRV-2.a's effort gate), collects the item whole on both
 * arms' decode paths, and replays it as a `reasoning` item ahead of the
 * same turn's function_call records. The collect rule and the replay rule
 * are the same rule (id + encrypted_content as non-empty strings), so what
 * entered the store can always leave it — the Anthropic signature law
 * restated for this wire's opaque blob.
 *
 * (2) IMAGE TOOL OUTPUTS — `function_call_output` takes a parts list, so
 * the tool's pixels ride its own row through the same gate ladder the
 * Anthropic arm runs (error rows text, vision-gated, budgeted, re-sniffed).
 *
 * Also pinned here: the Anthropic replay filter that makes the SHARED
 * `thinkingBlocks` carrier safe — each wire replays only its own tagged
 * shapes and degrades the other's to silence, never to a 400.
 *
 * Fixtures are recorded-by-shape (no live `/v1/responses` key here — the
 * trait docblock carries the same disclosure); the vocabulary follows
 * OpenAI's published Responses docs, as in {@see OpenAiResponsesWireTest}.
 */
final class OpenAiResponsesReasoningTest extends TestCase
{
    use HomeSandboxTrait;

    /** The trait's private tag constant, restated for pins (the ONLY two
     *  spellings of it must agree — DuplicatedTestHelperDrift discipline
     *  prefers this over reflecting a private const). */
    private const ITEM_TAG = 'openai_reasoning_item';

    /** Real PNG magic; the rest is filler — FileMentions::sniffImage reads
     *  headers, and so does the API door this stands in for. */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n" . 'filler-bytes-not-a-valid-image';

    private string $reasoningSandboxHome = '';

    /** @var list<array<string, mixed>> */
    private array $reasoningWireHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->reasoningSandboxHome = sys_get_temp_dir() . '/provider_reasoning_home_' . uniqid('', true);
        $this->useHomeSandbox($this->reasoningSandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->reasoningSandboxHome);
        $this->reasoningWireHistory = [];

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    //  Helpers (names file-unique per DuplicatedTestHelperDrift discipline)
    // -------------------------------------------------------------------------

    /** @param list<GuzzleResponse> $queued */
    private function reasoningArmProvider(array $queued, string $model = 'gpt-4o-mini'): OpenAIProvider
    {
        $this->reasoningWireHistory = [];
        $stack = HandlerStack::create(new MockHandler($queued));
        $stack->push(Middleware::history($this->reasoningWireHistory));

        return new OpenAIProvider(
            $this->createMock(ClientContract::class),
            $model,
            [],
            modelsBaseUrl: 'https://gw.test/v1',
            modelsApiKey: 'sk-test',
            api: OpenAIProvider::API_RESPONSES,
            responsesClient: new GuzzleClient(['handler' => $stack]),
        );
    }

    /** @param list<\SugarCraft\Code\Messages\Message> $messages */
    private function reasoningArmRequest(array $messages, string $model = 'gpt-4o-mini', ?string $effort = null): CompleteRequest
    {
        return new CompleteRequest(
            model: $model,
            messages: $messages,
            reasoningEffort: $effort,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function reasoningEmptyDoc(array $overrides = []): array
    {
        return ['id' => 'resp_replay', 'status' => 'completed', 'output' => []] + $overrides;
    }

    private function capturedReasoningBody(): array
    {
        return json_decode((string) $this->reasoningWireHistory[0]['request']->getBody(), true);
    }

    /**
     * One canned Responses SSE line block: `event:` / blank / `data:` —
     * the recorded shape OpenAiResponsesWireTest's stream fixtures ship.
     *
     * @param array<string, mixed> $event
     */
    private static function reasoningSseEvent(string $name, array $event): string
    {
        $event['type'] ??= $name;

        return implode("\n", [
            'event: ' . $name,
            '',
            'data: ' . json_encode($event, JSON_THROW_ON_ERROR),
            '',
        ]);
    }

    // -------------------------------------------------------------------------
    //  Leg 1a — the request body gate
    // -------------------------------------------------------------------------

    public function testReasoningArmAsksForSummaryAndEncryptedContent(): void
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
            model: 'o3-mini',
        );

        $provider->complete($this->reasoningArmRequest(
            [new UserMessage('think first')],
            model: 'o3-mini',
            effort: 'high',
        ));

        $body = $this->capturedReasoningBody();

        $this->assertSame(['effort' => 'high', 'summary' => 'auto'], $body['reasoning']);
        $this->assertSame(['reasoning.encrypted_content'], $body['include']);
    }

    public function testTheIncludeLegRidesTheEffortGateAndNothingElse(): void
    {
        // Vision model with no effort: gate shut — no reasoning, no include.
        $provider = $this->reasoningArmProvider([
            new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR)),
            new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR)),
        ]);

        $provider->complete($this->reasoningArmRequest([new UserMessage('plain')]));

        $body = $this->capturedReasoningBody();
        $this->assertArrayNotHasKey('reasoning', $body);
        $this->assertArrayNotHasKey('include', $body);

        // Byte-stability law: the SAME request twice yields identical wire
        // bytes (the include list is a fixed literal, never per-turn state),
        // so a reasoning session's prompt-cache prefix cannot jitter.
        $provider->complete($this->reasoningArmRequest([new UserMessage('plain')]));
        $this->assertSame(
            json_encode($body, JSON_THROW_ON_ERROR),
            json_encode(json_decode((string) $this->reasoningWireHistory[1]['request']->getBody(), true), JSON_THROW_ON_ERROR),
        );
    }

    // -------------------------------------------------------------------------
    //  Leg 1b — batch collect + replay round trip
    // -------------------------------------------------------------------------

    public function testBatchReasoningItemWithEncryptedBlobIsCollectedWhole(): void
    {
        $provider = $this->reasoningArmProvider([new GuzzleResponse(200, [], json_encode([
            'id' => 'resp_enc',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_1',
                    'encrypted_content' => 'enc_replayable',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'weighing '],
                        ['type' => 'summary_text', 'text' => 'options'],
                        ['type' => 'mystery_part', 'text' => 'hostile'],
                        'not-even-a-part',
                    ],
                ],
                // Second reasoning item WITHOUT the blob: display fold yes,
                // protocol state no (an unreplayable half-item is not stored).
                ['type' => 'reasoning', 'id' => 'rs_2', 'summary' => [['type' => 'summary_text', 'text' => 'side note']]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'answered']]],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7],
        ], JSON_THROW_ON_ERROR))]);

        $response = $provider->complete($this->reasoningArmRequest([new UserMessage('go')]));

        $this->assertSame('answered', $response->content);
        $this->assertSame("weighing \noptions\nside note", $response->reasoning, 'the 2.a ticker fold survives the collect leg');
        $this->assertSame([[
            'type' => self::ITEM_TAG,
            'id' => 'rs_1',
            'encrypted_content' => 'enc_replayable',
            'summary' => [
                ['type' => 'summary_text', 'text' => 'weighing '],
                ['type' => 'summary_text', 'text' => 'options'],
            ],
        ]], $response->thinkingBlocks, 'hostile parts dropped, the blob-less item never stored');
    }

    public function testStoredItemsReplayAsReasoningItemsAheadOfTheCallRecords(): void
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
            model: 'o3-mini',
        );

        $provider->complete($this->reasoningArmRequest([
            new UserMessage('go'),
            new AssistantMessage(
                'answered',
                [new ToolCall('call_r1', 'Read', ['path' => '/tmp/x'])],
                thinkingBlocks: [
                    // Own tagged item — replays.
                    ['type' => self::ITEM_TAG, 'id' => 'rs_1', 'encrypted_content' => 'enc_replayable'],
                    // The OTHER family's signed shape in a shared store —
                    // skipped on this arm, not forwarded.
                    ['type' => 'thinking', 'thinking' => 'foreign', 'signature' => 'sig'],
                    // Unreplayable halves — skipped.
                    ['type' => self::ITEM_TAG, 'id' => 'rs_x'],
                    ['type' => self::ITEM_TAG, 'encrypted_content' => ''],
                ],
            ),
        ], model: 'o3-mini'));

        $input = $this->capturedReasoningBody()['input'];

        $this->assertSame('go', $input[0]['content'][0]['text'], 'the user turn ahead is untouched');
        // Generation order: the endpoint saw reasoning FIRST, then the call,
        // then the message — the replay re-presents that order as SIBLING
        // items or the encrypted reference breaks.
        $this->assertSame(['reasoning', 'function_call', 'message'], array_column(array_slice($input, 1, 3), 'type'));
        $this->assertSame([
            'type' => 'reasoning',
            'id' => 'rs_1',
            'encrypted_content' => 'enc_replayable',
        ], $input[1]);
        $this->assertSame([
            'type' => 'function_call',
            'call_id' => 'call_r1',
            'name' => 'Read',
            'arguments' => '{"path":"/tmp/x"}',
        ], $input[2]);
        $this->assertSame([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'answered']],
        ], $input[3]);
    }

    public function testReplayedSummaryPartsAreReValidatedFromHostileStores(): void
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
            model: 'o3-mini',
        );

        $provider->complete($this->reasoningArmRequest([
            new AssistantMessage(
                '',
                thinkingBlocks: [[
                    'type' => self::ITEM_TAG,
                    'id' => 'rs_s',
                    'encrypted_content' => 'enc',
                    'summary' => ['raw-string-part', ['type' => 'other', 'text' => 'x'], ['type' => 'summary_text', 'text' => 'kept']],
                ]],
            ),
        ], model: 'o3-mini'));

        $this->assertSame([[
            'type' => 'reasoning',
            'id' => 'rs_s',
            'encrypted_content' => 'enc',
            'summary' => [['type' => 'summary_text', 'text' => 'kept']],
        ]], $this->capturedReasoningBody()['input']);
    }

    public function testReplayStaysOffTheWireAfterAMidSessionSwitchToATextOnlyModel(): void
    {
        // FIX-1 (review 1, M1): the replay rides the same door as the
        // include leg. A transcript carrying a reasoning item switching
        // to a text-only family must not see it on the wire.
        $provider = $this->reasoningArmProvider([new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))]);

        $provider->complete($this->reasoningArmRequest([
            new AssistantMessage(
                'answered',
                thinkingBlocks: [[
                    'type' => self::ITEM_TAG,
                    'id' => 'rs_g',
                    'encrypted_content' => 'enc',
                ]],
            ),
        ]));

        $input = $this->capturedReasoningBody()['input'];
        $this->assertSame(['message'], array_column($input, 'type'));
        $this->assertStringNotContainsString('enc', json_encode($input));
    }

    public function testReplayReturnsWhenTheModelSwitchesBackToAnEffortFamily(): void
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
            model: 'o3-mini',
        );

        $provider->complete($this->reasoningArmRequest([
            new AssistantMessage(
                'answered',
                thinkingBlocks: [[
                    'type' => self::ITEM_TAG,
                    'id' => 'rs_g',
                    'encrypted_content' => 'enc',
                ]],
            ),
        ], model: 'o3-mini'));

        $input = $this->capturedReasoningBody()['input'];
        $this->assertSame(['reasoning', 'message'], array_column($input, 'type'));
        $this->assertSame('enc', $input[0]['encrypted_content']);
    }


    // -------------------------------------------------------------------------
    //  Leg 1c — the streaming carriers
    // -------------------------------------------------------------------------

    public function testSummaryDeltasStreamAndTheClosingItemCarriesTheBlob(): void
    {
        $sse = implode("\n", [
            self::reasoningSseEvent('response.output_item.added', ['item' => ['type' => 'reasoning', 'id' => 'rs_st']]),
            self::reasoningSseEvent('response.reasoning_summary_text.delta', ['item_id' => 'rs_st', 'summary_index' => 0, 'delta' => 'think']),
            self::reasoningSseEvent('response.reasoning_summary_text.delta', ['item_id' => 'rs_st', 'summary_index' => 0, 'delta' => 'ing']),
            self::reasoningSseEvent('response.output_item.done', ['item' => [
                'type' => 'reasoning',
                'id' => 'rs_st',
                'encrypted_content' => 'enc_stream',
                'summary' => [['type' => 'summary_text', 'text' => 'thinking']],
            ]]),
            self::reasoningSseEvent('response.output_text.delta', ['delta' => 'Hello']),
            self::reasoningSseEvent('response.completed', ['response' => [
                'usage' => ['input_tokens' => 9, 'output_tokens' => 1, 'total_tokens' => 10],
            ]]),
            '',
            'data: [DONE]',
        ]);

        $provider = $this->reasoningArmProvider([new GuzzleResponse(
            200,
            ['Content-Type' => 'text/event-stream'],
            $sse,
        )]);

        $frames = iterator_to_array($provider->completeStream($this->reasoningArmRequest([new UserMessage('go')])));

        $reasoningFrames = array_values(array_filter($frames, static fn (CompleteResponse $f): bool => is_string($f->reasoning)));
        $blockFrames = array_values(array_filter($frames, static fn (CompleteResponse $f): bool => $f->thinkingBlocks !== []));

        $this->assertSame(['think', 'ing'], array_map(static fn (CompleteResponse $f): string => (string) $f->reasoning, $reasoningFrames));
        $this->assertCount(1, $blockFrames, 'exactly the closing item.done frame carries the protocol state');
        $this->assertSame([[
            'type' => self::ITEM_TAG,
            'id' => 'rs_st',
            'encrypted_content' => 'enc_stream',
            'summary' => [['type' => 'summary_text', 'text' => 'thinking']],
        ]], $blockFrames[0]->thinkingBlocks);

        // Carrier ORDER: deltas, then the block, then content — and the
        // usage frame stays LAST (2.a's billing law, unperturbed).
        $firstContent = array_search(true, array_map(static fn (CompleteResponse $f): bool => $f->content === 'Hello', $frames), true);
        $firstBlocks = array_search(true, array_map(static fn (CompleteResponse $f): bool => $f->thinkingBlocks !== [], $frames), true);
        $last = count($frames) - 1;
        $this->assertLessThan($firstContent, $firstBlocks, 'the reasoning item closed before the answer streamed');
        $this->assertNotNull($frames[$last]->usage, 'the usage carrier remains the terminal frame');
    }

    // -------------------------------------------------------------------------
    //  Leg 2 — image tool outputs on their own row
    // -------------------------------------------------------------------------

    public function testVisionToolRowCarriesCaptionAndImageParts(): void
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
        );

        $provider->complete($this->reasoningArmRequest([
            new ToolResultMessage('call_img', 'a screenshot', imageBytes: self::PNG_BYTES),
        ]));

        $output = $this->capturedReasoningBody()['input'][0]['output'];

        $this->assertSame([
            ['type' => 'input_text', 'text' => 'a screenshot'],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . base64_encode(self::PNG_BYTES)],
        ], $output, 'the pixels ride the tool row — no follow-up-turn smuggle on this wire');
    }

    public function testAnImageOnlyToolRowShipsNoEmptyCaptionPart(): void
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
        );

        $provider->complete($this->reasoningArmRequest([
            new ToolResultMessage('call_img', '', imageBytes: self::PNG_BYTES),
        ]));

        $output = $this->capturedReasoningBody()['input'][0]['output'];

        $this->assertSame([['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . base64_encode(self::PNG_BYTES)]], $output);
    }

    public function testEveryFallbackRungKeepsThePlainStringOutput(): void
    {
        // o3-mini is a reasoning-family id (gate opens for effort) AND a
        // text-only id (vision closed) — the two doors are independent.
        $rows = [
            'text-only model' => ['o3-mini', new ToolResultMessage('c1', 'shot', imageBytes: self::PNG_BYTES)],
            'error row' => ['gpt-4o-mini', new ToolResultMessage('c2', 'failed shot', true, self::PNG_BYTES)],
            'oversized payload' => ['gpt-4o-mini', new ToolResultMessage('c3', 'huge', imageBytes: self::PNG_BYTES . str_repeat("\x00", 256 * 1024))],
            'non-image bytes' => ['gpt-4o-mini', new ToolResultMessage('c4', 'not a png', imageBytes: 'MZ-headers-only')],
            'no image carried' => ['gpt-4o-mini', new ToolResultMessage('c5', 'plain text')],
        ];

        foreach ($rows as $label => [$model, $row]) {
            $provider = $this->reasoningArmProvider(
                [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
                model: $model,
            );
            $provider->complete($this->reasoningArmRequest([$row], model: $model));

            $output = $this->capturedReasoningBody()['input'][0]['output'];
            $this->assertSame($row->content(), $output, $label . ' keeps the pre-2.b string spelling');
        }
    }

    // -------------------------------------------------------------------------
    //  The shared carrier's other door — the Anthropic-side filter
    // -------------------------------------------------------------------------

    public function testForeignReasoningItemsStayOffTheAnthropicWire(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            'claude-sonnet-4-6',
            static function (string $endpoint, array $body) use (&$captured): array {
                $captured = $body;

                return ['content' => []];
            },
            null,
            [],
            true,
            null,
            2048,
        );

        $signed = ['type' => 'thinking', 'thinking' => 'mine', 'signature' => 'sig'];
        $provider->complete(new CompleteRequest(
            model: 'claude-sonnet-4-6',
            messages: [new AssistantMessage(
                'ok',
                [new ToolCall('tu_9', 'Read', ['path' => 'a'])],
                thinkingBlocks: [
                    $signed,
                    ['type' => self::ITEM_TAG, 'id' => 'rs_x', 'encrypted_content' => 'enc'],
                    'junk-entry-not-an-array',
                ],
            )],
        ));

        $blocks = $captured['messages'][0]['content'];

        $this->assertSame($signed, $blocks[0], 'its own signed block still leads the turn');
        $this->assertSame(
            ['thinking', 'text', 'tool_use'],
            array_column($blocks, 'type'),
            'the shared carrier never forwards a foreign item or a junk entry into an Anthropic 400',
        );
    }
}
