<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;

/**
 * Audit 15a A4: both OpenAI-compatible streaming providers emitted a streamed
 * tool call only from a frame where `finish_reason === "tool_calls"` AND
 * `delta` was non-null. A stream that ended on `stop` after tool-call deltas,
 * or whose `tool_calls` finish frame said `"delta": null`, lost the call
 * without a trace - the turn ended as an empty reply. Every "exactly one
 * call" assertion below yields zero calls against the pre-fix code.
 */
final class StreamedToolCallFlushTest extends TestCase
{
    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // RuntimeNoticeSink::warn() lands in error_log(); capture it the way
        // SglangProviderTruncationGuardTest does.
        $this->logFile = sys_get_temp_dir() . '/a4-flush-' . uniqid('', true) . '.log';
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

    private static function frame(mixed $delta, ?string $finishReason = null): string
    {
        return 'data: ' . json_encode([
            'model' => 'some-model',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ]) . "\n\n";
    }

    private static function readCallDelta(string $arguments = '{"path":"a.php"}'): string
    {
        return self::frame(['tool_calls' => [[
            'index' => 0,
            'id' => 'c1',
            'type' => 'function',
            'function' => ['name' => 'Read', 'arguments' => $arguments],
        ]]]);
    }

    private const DONE = "data: [DONE]\n\n";

    /** The audit's `tc-finish-stop` repro body. */
    private static function finishStopBody(): string
    {
        return self::readCallDelta() . self::frame([], 'stop') . self::DONE;
    }

    /** The audit's `tc-delta-null` repro body. */
    private static function deltaNullBody(): string
    {
        return self::readCallDelta() . self::frame(null, 'tool_calls') . self::DONE;
    }

    private function provider(string $kind, string $body, mixed $parser = null): ProviderInterface
    {
        $client = $this->createMock(Client::class);
        $client->method('post')->willReturn(new Response(200, [], $body));

        return $kind === 'sglang'
            ? new SglangProvider('https://api.example.com', 'some-model', null, $client, $parser)
            : new CustomProvider('custom', 'https://api.example.com', 'some-model', null, $client, true, true);
    }

    /**
     * @return list<CompleteResponse>
     */
    private function drain(ProviderInterface $provider): array
    {
        return iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'some-model',
            messages: [new UserMessage('read a.php')],
        )), false);
    }

    /**
     * @param list<CompleteResponse> $chunks
     * @return list<CompleteResponse>
     */
    private static function withCalls(array $chunks): array
    {
        return array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->toolCalls !== null));
    }

    /**
     * @param list<CompleteResponse> $chunks
     */
    private function assertExactlyOneReadCall(array $chunks): void
    {
        $withCalls = self::withCalls($chunks);
        $this->assertCount(1, $withCalls, 'exactly one chunk carries the call - never zero, never twice');
        $calls = array_values($withCalls[0]->toolCalls);
        $this->assertCount(1, $calls);
        $this->assertSame('Read', $calls[0]->name());
        $this->assertSame('c1', $calls[0]->id());
        $this->assertSame(['path' => 'a.php'], $calls[0]->arguments());
    }

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return ['sglang' => ['sglang'], 'custom' => ['custom']];
    }

    /** @return array<string, array{string, string}> */
    public static function reproBodies(): array
    {
        $cases = [];
        foreach (['sglang', 'custom'] as $kind) {
            $cases["$kind tc-finish-stop"] = [$kind, self::finishStopBody()];
            $cases["$kind tc-delta-null"] = [$kind, self::deltaNullBody()];
        }

        return $cases;
    }

    #[DataProvider('reproBodies')]
    public function testBothAuditReproBodiesYieldExactlyOneReadCall(string $kind, string $body): void
    {
        $this->assertExactlyOneReadCall($this->drain($this->provider($kind, $body)));
        $this->assertSame('', $this->capturedLog(), 'a complete call has nothing to warn about');
    }

    #[DataProvider('providers')]
    public function testAStopEndFlushIsNotMarkedTruncated(string $kind): void
    {
        $chunks = $this->drain($this->provider($kind, self::finishStopBody()));

        $this->assertFalse(self::withCalls($chunks)[0]->truncated, 'the stream was not cut');
        $this->assertSame([], array_filter($chunks, static fn (CompleteResponse $c): bool => $c->truncated));
    }

    #[DataProvider('providers')]
    public function testANormallyDrainedToolCallsStreamYieldsItsCallExactlyOnce(string $kind): void
    {
        $chunks = $this->drain($this->provider($kind, self::readCallDelta() . self::frame([], 'tool_calls') . self::DONE));

        $this->assertExactlyOneReadCall($chunks);
        $this->assertFalse(self::withCalls($chunks)[0]->truncated);
    }

    #[DataProvider('providers')]
    public function testADeltaNullStopFrameAddsNoChunkWhenNothingIsBuffered(string $kind): void
    {
        $withNull = $this->drain($this->provider($kind, self::frame(['content' => 'hi']) . self::frame(null, 'stop') . self::DONE));
        $withEmpty = $this->drain($this->provider($kind, self::frame(['content' => 'hi']) . self::frame([], 'stop') . self::DONE));

        $this->assertSame([], self::withCalls($withNull));
        $this->assertSame('hi', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $withNull)));
        $this->assertLessThanOrEqual(count($withEmpty), count($withNull), 'a bare finish frame yields no phantom chunk');
    }

    #[DataProvider('providers')]
    public function testTheFlushFrameRidesBeforeTheUsageCarrier(string $kind): void
    {
        $usage = 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7]]) . "\n\n";
        $chunks = $this->drain($this->provider($kind, self::readCallDelta() . self::frame([], 'stop') . $usage . self::DONE));

        $last = $chunks[count($chunks) - 1];
        $this->assertSame(7, $last->tokensUsed, 'the bill stays the stream\'s last event');
        $this->assertNull($last->toolCalls);
        $this->assertSame($chunks[count($chunks) - 2], self::withCalls($chunks)[0], 'the flushed call immediately precedes the bill');
    }

    #[DataProvider('providers')]
    public function testAStopEndWithIncompleteArgumentsDropsTheCallAndSaysWhyWithoutClaimingTruncation(string $kind): void
    {
        $chunks = $this->drain($this->provider($kind, self::readCallDelta('{"path": ') . self::frame([], 'stop') . self::DONE));

        $this->assertSame([], self::withCalls($chunks), 'never executed half-decoded');
        $log = $this->capturedLog();
        $this->assertStringContainsString('"Read"', $log);
        $this->assertStringContainsString('DROPPED, not executed', $log);
        $this->assertStringContainsString('finish_reason "stop"', $log);
        $this->assertStringNotContainsString('stream was truncated', $log);
    }

    #[DataProvider('providers')]
    public function testAStopEndWithNoArgumentDeltasEmitsAZeroArgumentCall(string $kind): void
    {
        $chunks = $this->drain($this->provider($kind, self::readCallDelta('') . self::frame([], 'stop') . self::DONE));

        $withCalls = self::withCalls($chunks);
        $this->assertCount(1, $withCalls);
        $this->assertSame('Read', $withCalls[0]->toolCalls[0]->name());
        $this->assertSame([], $withCalls[0]->toolCalls[0]->arguments());
        $this->assertSame('', $this->capturedLog());
    }

    #[DataProvider('providers')]
    public function testATruncatedEndFlushStaysFlaggedTruncated(string $kind): void
    {
        $chunks = $this->drain($this->provider($kind, self::readCallDelta() . self::frame([], 'length') . self::DONE));

        $withCalls = self::withCalls($chunks);
        $this->assertCount(1, $withCalls);
        $this->assertTrue($withCalls[0]->truncated);
        $this->assertSame(['path' => 'a.php'], $withCalls[0]->toolCalls[0]->arguments());
    }

    #[DataProvider('providers')]
    public function testAnErrorEndDoesNotFlush(string $kind): void
    {
        $chunks = $this->drain($this->provider($kind, self::readCallDelta() . self::frame([], 'error') . self::DONE));

        $this->assertSame([], self::withCalls($chunks), 'the server disowned this generation');
    }

    public function testAStopFlushedStructuredCallDisarmsTextualRecovery(): void
    {
        $d = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";
        $envelope = "<{$d}tool_calls>\n<{$d}invoke name=\"read\">\n"
            . "<{$d}parameter name=\"path\" string=\"true\">/etc/hosts</{$d}parameter>\n"
            . "</{$d}invoke>\n</{$d}tool_calls>";

        $chunks = $this->drain($this->provider(
            'sglang',
            self::frame(['content' => $envelope]) . self::readCallDelta() . self::frame([], 'stop') . self::DONE,
            DsmlToolCallParser::new(),
        ));

        $this->assertExactlyOneReadCall($chunks);
    }
}
