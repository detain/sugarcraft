<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\StreamResponse;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * X-31a: an OpenAI stream's tool calls reach Runtime. `supportsStreaming()` is
 * always true, so Runtime only ever takes the stream path - and its chunk
 * parser hard-coded `toolCalls: null`, so every streamed call was read and
 * thrown away. Each stream here is decoded by the REAL openai-php SDK
 * (`StreamResponse` over a canned SSE body), because that SDK drops the wire's
 * per-call `index` and the reassembly has to work on what it leaves.
 */
final class OpenAIProviderStreamedToolCallsTest extends TestCase
{
    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // RuntimeNoticeSink::warn() lands in error_log(); captured to read the
        // drop warnings.
        $this->logFile = sys_get_temp_dir() . '/x31a-openai-' . uniqid('', true) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    private function capturedLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * One streamed choice delta as the API sends it.
     *
     * @param array<string, mixed> $delta
     * @return array<string, mixed>
     */
    private static function choice(array $delta, ?string $finishReason = null): array
    {
        return ['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason];
    }

    /**
     * A `delta.tool_calls[]` fragment; the opener carries id, type and name.
     *
     * @return array<string, mixed>
     */
    private static function fragment(int $index, string $arguments, ?string $id = null, ?string $name = null): array
    {
        $function = ['arguments' => $arguments];
        if ($name !== null) {
            $function = ['name' => $name] + $function;
        }
        $fragment = ['index' => $index, 'function' => $function];
        if ($id !== null) {
            $fragment = ['id' => $id, 'type' => 'function'] + $fragment;
        }

        return $fragment;
    }

    /**
     * Run one stream through the provider and collect every yielded chunk.
     *
     * @param list<array<string, mixed>> $choices
     * @param array<string, mixed>|null $usage a terminal include_usage frame
     * @return list<CompleteResponse>
     */
    private function stream(array $choices, ?array $usage = null): array
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);

        $frames = array_map(static fn (array $choice): array => ['choices' => [$choice]], $choices);
        if ($usage !== null) {
            $frames[] = ['choices' => [], 'usage' => $usage];
        }
        $body = '';
        foreach ($frames as $frame) {
            $body .= 'data: ' . json_encode([
                'id' => 'chatcmpl-x31a',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
            ] + $frame) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        $chat->method('createStreamed')->willReturn(new StreamResponse(
            CreateStreamedResponse::class,
            new Response(200, [], $body),
        ));

        return array_values(iterator_to_array((new OpenAIProvider($client, 'gpt-4o'))
            ->completeStream(new CompleteRequest(model: 'gpt-4o', messages: [new UserMessage('go')]))));
    }

    /**
     * Every tool call the stream delivered, in order, the way Runtime folds
     * them (it merges `toolCalls` from whichever chunks carry them).
     *
     * @param list<CompleteResponse> $chunks
     * @return list<ToolCall>
     */
    private static function calls(array $chunks): array
    {
        $calls = [];
        foreach ($chunks as $chunk) {
            foreach ($chunk->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    public function testAFragmentedCallIsAssembledOnTheToolCallsFinish(): void
    {
        $chunks = $this->stream([
            self::choice(['role' => 'assistant', 'tool_calls' => [self::fragment(0, '', 'call_1', 'Read')]]),
            self::choice(['tool_calls' => [self::fragment(0, '{"file_')]]),
            self::choice(['tool_calls' => [self::fragment(0, 'path":"src/a.php"}')]]),
            self::choice([], 'tool_calls'),
        ]);

        $calls = self::calls($chunks);
        $this->assertCount(1, $calls);
        $this->assertSame(['call_1', 'Read', ['file_path' => 'src/a.php']], [$calls[0]->id(), $calls[0]->name(), $calls[0]->arguments()]);
        $this->assertSame('{"file_path":"src/a.php"}', $calls[0]->rawArguments(), 'the concatenated fragments replay verbatim');
        $this->assertNotNull(end($chunks)->toolCalls, 'the calls ride the chunk that closes the choice');
    }

    public function testParallelCallsStayApartEvenThoughTheSdkDropsTheirIndex(): void
    {
        $chunks = $this->stream([
            self::choice(['tool_calls' => [self::fragment(0, '', 'call_a', 'Glob')]]),
            self::choice(['tool_calls' => [self::fragment(0, '{"pattern":"*.php"}')]]),
            self::choice(['tool_calls' => [self::fragment(1, '', 'call_b', 'Grep')]]),
            self::choice(['tool_calls' => [self::fragment(1, '{"pattern":')]]),
            self::choice(['tool_calls' => [self::fragment(1, '"TODO"}')]]),
            self::choice([], 'tool_calls'),
        ]);

        $calls = self::calls($chunks);
        $this->assertSame(
            [['call_a', 'Glob', ['pattern' => '*.php']], ['call_b', 'Grep', ['pattern' => 'TODO']]],
            array_map(static fn (ToolCall $c): array => [$c->id(), $c->name(), $c->arguments()], $calls),
        );
    }

    public function testAPayloadDeclaredCompleteButBrokenIsEmittedWithItsError(): void
    {
        $calls = self::calls($this->stream([
            self::choice(['tool_calls' => [self::fragment(0, '{"command": "ls', 'call_1', 'Bash')]]),
            self::choice([], 'tool_calls'),
        ]));

        $this->assertCount(1, $calls);
        $this->assertSame([], $calls[0]->arguments());
        $this->assertNotNull($calls[0]->argumentsError(), 'Runtime refuses it instead of running Bash with []');
    }

    public function testACompleteCallLeftBufferedByAStopEndIsFlushed(): void
    {
        $chunks = $this->stream([
            self::choice(['tool_calls' => [self::fragment(0, '{"command":"ls"}', 'call_1', 'Bash')]]),
            self::choice([], 'stop'),
        ]);

        $calls = self::calls($chunks);
        $this->assertSame([['call_1', 'Bash', ['command' => 'ls']]], array_map(static fn (ToolCall $c): array => [$c->id(), $c->name(), $c->arguments()], $calls));
        $this->assertFalse(end($chunks)->truncated, 'a clean stop end is not reported as a length stop');
    }

    public function testACutOffCallIsDroppedLoudlyNotRun(): void
    {
        $chunks = $this->stream([
            self::choice(['tool_calls' => [self::fragment(0, '{"command":"rm -', 'call_1', 'Bash')]]),
            self::choice([], 'length'),
        ]);

        $this->assertSame([], self::calls($chunks));
        $log = $this->capturedLog();
        $this->assertStringContainsString('OpenAIProvider: tool call "Bash"', $log);
        $this->assertStringContainsString('DROPPED, not executed', $log);
        $this->assertStringContainsString('stream was truncated', $log);
    }

    public function testATextOnlyStreamCarriesNoToolCalls(): void
    {
        $chunks = $this->stream([
            self::choice(['role' => 'assistant', 'content' => 'Hello']),
            self::choice(['content' => ' there']),
            self::choice([], 'stop'),
        ]);

        $this->assertSame([], self::calls($chunks));
        $this->assertSame('Hello there', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));
    }

    public function testTheUsageCarrierStaysTheLastEventAfterAFlush(): void
    {
        $chunks = $this->stream(
            [
                self::choice(['tool_calls' => [self::fragment(0, '{}', 'call_1', 'Doctor')]]),
                self::choice([], 'stop'),
            ],
            ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        );

        $last = end($chunks);
        $this->assertNotNull($last->usage);
        $this->assertSame(15, $last->tokensUsed);
        $this->assertNull($last->toolCalls);
        $this->assertSame(['Doctor'], array_map(static fn (ToolCall $c): string => $c->name(), self::calls($chunks)));
    }
}
