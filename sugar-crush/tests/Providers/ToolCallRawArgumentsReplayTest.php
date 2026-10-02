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
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit 15a A23: a replayed tool call must carry the arguments string the
 * model actually sent, byte for byte.
 *
 * The providers decode `function.arguments` with an associative
 * `json_decode()`, which maps `{}` and `[]` to the same empty PHP array, so
 * the history formatter's re-encode could not tell them apart: a call sent as
 * `{"opts":{},"paths":[]}` went back on every later step as
 * `{"opts":[],"paths":[]}` - the model shown its own call in a shape that
 * contradicts the tool's schema. The fix keeps the wire string on the
 * {@see ToolCall} and replays it verbatim; only calls with no faithful wire
 * form are re-encoded.
 *
 * Every provider path that parses OpenAI-shaped calls is driven end to end:
 * the response is parsed by the real provider, the parsed call is put in the
 * history of a second real request, and the second request's body is read
 * off the wire.
 */
final class ToolCallRawArgumentsReplayTest extends TestCase
{
    /** The audit's case: a nested empty MAP beside a nested empty LIST. */
    private const WIRE = '{"opts":{},"paths":[]}';

    private const MODEL = 'some-model';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // The flush paths warn through RuntimeNoticeSink -> error_log(); keep
        // that off the runner's stderr.
        $this->logFile = sys_get_temp_dir() . '/a23-replay-' . uniqid('', true) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    // -------------------------------------------------------------------------
    // The trait: raw wins, re-encode is the fallback.
    // -------------------------------------------------------------------------

    public function testRawArgumentsReplayByteEqual(): void
    {
        $call = new ToolCall('c1', 'Glob', ['opts' => [], 'paths' => []], null, self::WIRE);

        $this->assertSame(self::WIRE, $call->rawArguments());
        $this->assertSame(self::WIRE, self::replay($call));
    }

    /** Whitespace and key order are the model's, so they are replayed too. */
    public function testRawSpacingAndKeyOrderAreKept(): void
    {
        $raw = "{ \"paths\": [],\n  \"opts\": { } }";
        $call = new ToolCall('c1', 'Glob', ['paths' => [], 'opts' => []], null, $raw);

        $this->assertSame($raw, self::replay($call));
    }

    /** A call with no wire string still re-encodes exactly as before (A7). */
    public function testCallWithoutRawStillReencodes(): void
    {
        $this->assertSame('{}', self::replay(new ToolCall('c1', 'Glob', [])));
        $this->assertSame('{"paths":["a.php"]}', self::replay(new ToolCall('c1', 'Glob', ['paths' => ['a.php']])));
    }

    /**
     * A raw string is kept only while it says what the arguments say: a call
     * rebuilt with different arguments can never replay stale text.
     */
    public function testRawThatDisagreesWithArgumentsIsDropped(): void
    {
        $call = new ToolCall('c1', 'Read', ['path' => 'b.php'], null, '{"path":"a.php"}');

        $this->assertNull($call->rawArguments());
        $this->assertSame('{"path":"b.php"}', self::replay($call));
    }

    /**
     * Payloads with no faithful object form are never replayed: blank and
     * `null` are zero-argument calls (`{}`), broken JSON is the A11 refusal
     * case (Runtime documents `{}` as its replay), and a top-level list or
     * scalar is not an OpenAI arguments object.
     *
     * @param array<mixed> $arguments
     */
    #[DataProvider('unreplayableRaws')]
    public function testUnreplayableRawIsDropped(string $raw, array $arguments): void
    {
        $call = new ToolCall('c1', 'Read', $arguments, null, $raw);

        $this->assertNull($call->rawArguments());
        $this->assertNotSame($raw, self::replay($call));
    }

    /** @return array<string, array{string, array<mixed>}> */
    public static function unreplayableRaws(): array
    {
        return [
            'empty' => ['', []],
            'blank' => ['   ', []],
            'json null' => ['null', []],
            'truncated' => ['{"path":', []],
            'top-level list' => ['["a","b"]', ['a', 'b']],
            'empty list' => ['[]', []],
            'scalar' => ['12', []],
            'invalid utf-8' => ["{\"path\":\"bad\xB1\"}", []],
        ];
    }

    public function testRawSurvivesWithArgumentsErrorArrayRoundTripAndSerialize(): void
    {
        $call = new ToolCall('c1', 'Glob', ['opts' => [], 'paths' => []], null, self::WIRE);

        $this->assertSame(self::WIRE, $call->withArgumentsError('x')->rawArguments());
        $this->assertSame(self::WIRE, $call->withArgumentsError('x')->withArgumentsError(null)->rawArguments());

        $array = $call->toArray();
        $this->assertSame(self::WIRE, $array['rawArguments']);
        $this->assertSame(self::WIRE, ToolCall::fromArray($array)->rawArguments());

        $revived = unserialize(serialize($call));
        $this->assertInstanceOf(ToolCall::class, $revived);
        $this->assertSame(self::WIRE, $revived->rawArguments());
    }

    /** No raw, no key: the three-key shape existing readers expect. */
    public function testToArrayOmitsRawWhenAbsent(): void
    {
        $this->assertSame(
            ['id' => 'c1', 'name' => 'Read', 'arguments' => []],
            (new ToolCall('c1', 'Read', [], null, ''))->toArray(),
        );
    }

    // -------------------------------------------------------------------------
    // End to end through each OpenAI-shaped provider.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function providerPaths(): array
    {
        $batch = (string) json_encode([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'c1',
                        'type' => 'function',
                        'function' => ['name' => 'Glob', 'arguments' => self::WIRE],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['total_tokens' => 1],
        ]);

        // Split mid-object, the way a server streams argument deltas.
        $fragments = self::delta(['tool_calls' => [[
            'index' => 0, 'id' => 'c1', 'type' => 'function',
            'function' => ['name' => 'Glob', 'arguments' => '{"opts":{},'],
        ]]]) . self::delta(['tool_calls' => [[
            'index' => 0, 'function' => ['arguments' => '"paths":[]}'],
        ]]]);

        $declared = $fragments . self::delta([], 'tool_calls') . "data: [DONE]\n\n";
        // A4's undeclared end: the calls are drained by the flush path.
        $undeclared = $fragments . self::delta([], 'stop') . "data: [DONE]\n\n";

        return [
            'sglang complete' => ['sglang', false, $batch],
            'sglang stream, tool_calls finish' => ['sglang', true, $declared],
            'sglang stream, stop finish (flush)' => ['sglang', true, $undeclared],
            'custom complete' => ['custom', false, $batch],
            'custom stream, tool_calls finish' => ['custom', true, $declared],
            'custom stream, stop finish (flush)' => ['custom', true, $undeclared],
        ];
    }

    #[DataProvider('providerPaths')]
    public function testParsedCallReplaysByteEqualOnTheNextRequest(string $kind, bool $stream, string $firstBody): void
    {
        $provider = $this->provider($kind, $firstBody);

        $call = $this->firstCall($provider, $stream);
        $this->assertSame(['opts' => [], 'paths' => []], $call->arguments());
        $this->assertSame(self::WIRE, $call->rawArguments());

        $provider->complete($this->followUp($call));

        $this->assertSame(self::WIRE, $this->replayedArguments(1));
    }

    public function testOpenAIProviderParsedCallReplaysByteEqual(): void
    {
        $provider = new OpenAIProvider($this->openAiClient(self::WIRE), 'gpt-4o');

        $call = $provider->complete(new CompleteRequest(model: 'gpt-4o', messages: [new UserMessage('glob')]))
            ->toolCalls[0];
        $this->assertSame(self::WIRE, $call->rawArguments());

        $formatter = (new \ReflectionClass($provider))->getMethod('formatMessages');
        $messages = $formatter->invoke($provider, [new AssistantMessage('', [$call])]);

        $this->assertSame(self::WIRE, $messages[0]['tool_calls'][0]['function']['arguments']);
    }

    /**
     * OpenAIProvider's parse used `json_decode(...) ?? []`, so a payload that
     * decoded to a scalar reached ToolCall's `array $arguments` as an int and
     * died as a TypeError; it now carries the A11 error like the other two.
     */
    public function testOpenAIProviderScalarArgumentsCarryTheA11Error(): void
    {
        $provider = new OpenAIProvider($this->openAiClient('12'), 'gpt-4o');

        $call = $provider->complete(new CompleteRequest(model: 'gpt-4o', messages: [new UserMessage('glob')]))
            ->toolCalls[0];

        $this->assertSame([], $call->arguments());
        $this->assertNotNull($call->argumentsError());
        $this->assertNull($call->rawArguments());
    }

    // -------------------------------------------------------------------------

    private static function delta(array $delta, ?string $finishReason = null): string
    {
        return 'data: ' . json_encode([
            'model' => self::MODEL,
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ]) . "\n\n";
    }

    private function provider(string $kind, string $firstBody): ProviderInterface
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], $firstBody),
            new Response(200, [], '{"choices":[{"message":{"content":"done"}}],"usage":{"total_tokens":1}}'),
        ]));
        $stack->push(Middleware::history($this->history));
        $client = new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]);

        return $kind === 'sglang'
            ? new SglangProvider('https://api.example.com', self::MODEL, null, $client)
            : new CustomProvider('custom', 'https://api.example.com', self::MODEL, null, $client, true, true);
    }

    private function firstCall(ProviderInterface $provider, bool $stream): ToolCall
    {
        $request = new CompleteRequest(model: self::MODEL, messages: [new UserMessage('glob')]);

        if (!$stream) {
            $calls = $provider->complete($request)->toolCalls;
        } else {
            $withCalls = array_values(array_filter(
                iterator_to_array($provider->completeStream($request), false),
                static fn (CompleteResponse $c): bool => $c->toolCalls !== null,
            ));
            $this->assertCount(1, $withCalls);
            $calls = $withCalls[0]->toolCalls;
        }

        $this->assertIsArray($calls);
        $this->assertCount(1, $calls);

        return array_values($calls)[0];
    }

    private function followUp(ToolCall $call): CompleteRequest
    {
        return new CompleteRequest(model: self::MODEL, messages: [
            new UserMessage('glob'),
            new AssistantMessage('', [$call]),
            new ToolResultMessage('c1', 'no matches'),
        ]);
    }

    /** The replayed call's `arguments` string in the given request's body. */
    private function replayedArguments(int $requestIndex): string
    {
        $body = json_decode((string) $this->history[$requestIndex]['request']->getBody(), true);
        $this->assertIsArray($body);

        foreach ($body['messages'] as $message) {
            if (($message['role'] ?? null) === 'assistant' && isset($message['tool_calls'])) {
                return $message['tool_calls'][0]['function']['arguments'];
            }
        }

        $this->fail('the follow-up request carried no assistant tool call');
    }

    private function openAiClient(string $arguments): ClientContract
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);
        $chat->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'c1',
                        'type' => 'function',
                        'function' => ['name' => 'Glob', 'arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], MetaInformation::from([])));

        return $client;
    }

    private static function replay(ToolCall $call): string
    {
        $host = new class () {
            use ToolSchema;

            /**
             * @param array<mixed> $calls
             * @return array<mixed>
             */
            public function format(array $calls): array
            {
                return $this->formatToolCalls($calls);
            }
        };

        $wire = $host->format([$call]);

        return $wire[0]['function']['arguments'];
    }
}
