<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * Audit C2(b): the `</parameter>` truncation-risk warning runs from
 * buildParams() on EVERY request, so a re-issue of the same history (a
 * transient retry, any re-send of the same trailing tool-result batch) used to
 * re-log the same ~500-byte line for the same tool call - in the TUI, onto the
 * frame. It is now logged once per tool-call id per provider instance.
 *
 * The repeat-send assertions fail against the old code, which logged once per
 * request.
 */
final class SglangProviderTruncationWarningRateLimitTest extends TestCase
{
    /**
     * The DeepSeek-V4 id these cases pin, written out since audit A26 moved
     * {@see SglangProvider::DEFAULT_MODEL} to the served Qwen3.8.
     */
    private const DEEPSEEK_V4 = 'deepseek-ai/DeepSeek-V4-Flash-0731';

    private const WARNING_PHRASE = 'elevated risk of silent truncation';

    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // Same error_log capture as SglangProviderTruncationGuardTest.
        $this->logFile = sys_get_temp_dir() . '/sglang-truncation-rate-' . uniqid('', true) . '.log';
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

    private function warningCount(): int
    {
        return substr_count($this->capturedLog(), self::WARNING_PHRASE);
    }

    private function warningCountFor(string $toolCallId): int
    {
        return substr_count($this->capturedLog(), sprintf('tool result "%s" contains', $toolCallId));
    }

    /**
     * A provider whose client answers every post() with a FRESH batch
     * response - a single shared Response would hand the second call an
     * already-drained body.
     */
    private function batchProvider(): SglangProvider
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturnCallback(static fn (): Response => new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['total_tokens' => 3],
        ])));

        return new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
    }

    /**
     * @param list<ToolResultMessage> $results
     */
    private function requestEndingIn(array $results): CompleteRequest
    {
        return new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('read the files'), ...$results],
        );
    }

    public function testTheSameTrailingBatchSentTwiceIsWarnedOnce(): void
    {
        $provider = $this->batchProvider();
        $request = $this->requestEndingIn([new ToolResultMessage('call_read', '<parameter name="a">1</parameter>')]);

        $provider->complete($request);
        $provider->complete($request);

        $this->assertSame(1, $this->warningCountFor('call_read'));
        $this->assertSame(1, $this->warningCount());
    }

    /**
     * The streaming shape of the same re-issue: Runtime's transient-failure
     * retry calls completeStream() again with the identical request after the
     * first attempt failed - and the warning is logged before the post, so
     * the failed attempt already logged it.
     */
    public function testAStreamingRetryOfTheSameBatchIsWarnedOnce(): void
    {
        $sse = 'data: {"choices":[{"delta":{"content":"ok"},"finish_reason":"stop"}]}' . "\n"
            . 'data: [DONE]' . "\n";

        $attempt = 0;
        $httpClient = $this->createMock(Client::class);
        $httpClient->expects($this->exactly(2))->method('post')->willReturnCallback(
            static function () use (&$attempt, $sse): Response {
                if (++$attempt === 1) {
                    throw new ConnectException('connection reset', new Request('POST', 'chat/completions'));
                }

                return new Response(200, [], Utils::streamFor($sse));
            },
        );
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $request = $this->requestEndingIn([new ToolResultMessage('call_stream', '</parameter>')]);

        $caught = null;
        try {
            iterator_to_array($provider->completeStream($request));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the first attempt was scripted to fail');
        $this->assertStringContainsString('connection reset', $caught->getMessage());

        $chunks = iterator_to_array($provider->completeStream($request));

        $this->assertNotEmpty($chunks, 'the retry must actually have completed');
        $this->assertSame(1, $this->warningCountFor('call_stream'));
        $this->assertSame(1, $this->warningCount());
    }

    public function testANewRiskyToolCallInALaterBatchIsStillWarned(): void
    {
        $provider = $this->batchProvider();

        $provider->complete($this->requestEndingIn([new ToolResultMessage('call_first', '</parameter>')]));
        $provider->complete($this->requestEndingIn([new ToolResultMessage('call_second', '</parameter>')]));

        $this->assertSame(1, $this->warningCountFor('call_first'));
        $this->assertSame(1, $this->warningCountFor('call_second'));
        $this->assertSame(2, $this->warningCount());
    }

    public function testEachRiskyIdInARepeatedMultiToolBatchIsWarnedOnceInTotal(): void
    {
        $provider = $this->batchProvider();
        $request = $this->requestEndingIn([
            new ToolResultMessage('call_one', '</parameter>'),
            new ToolResultMessage('call_clean', 'nothing risky'),
            new ToolResultMessage('call_two', '</parameter></parameter>'),
        ]);

        $provider->complete($request);
        $provider->complete($request);
        $provider->complete($request);

        $this->assertSame(1, $this->warningCountFor('call_one'));
        $this->assertSame(1, $this->warningCountFor('call_two'));
        $this->assertSame(0, $this->warningCountFor('call_clean'));
        $this->assertSame(2, $this->warningCount());
    }

    /**
     * The memory is per INSTANCE, not process-wide: a rebuilt provider (a
     * provider switch) or another test reusing the same id is not silenced by
     * an earlier instance's history.
     */
    public function testSeparateProviderInstancesEachWarn(): void
    {
        $request = $this->requestEndingIn([new ToolResultMessage('call_read', '</parameter>')]);

        $this->batchProvider()->complete($request);
        $this->batchProvider()->complete($request);

        $this->assertSame(2, $this->warningCountFor('call_read'));
    }

    public function testAnIdLessResultIsWarnedOnceAcrossRepeats(): void
    {
        $provider = $this->batchProvider();
        $request = $this->requestEndingIn([new ToolResultMessage('', 'body with </parameter> in it')]);

        $provider->complete($request);
        $provider->complete($request);

        $this->assertSame(1, $this->warningCount());
    }

    public function testDistinctIdLessResultsAreEachWarned(): void
    {
        // The content-hash fallback must not collapse every id-less result
        // into one key.
        $provider = $this->batchProvider();

        $provider->complete($this->requestEndingIn([new ToolResultMessage('', 'first </parameter>')]));
        $provider->complete($this->requestEndingIn([new ToolResultMessage('', 'second </parameter>')]));

        $this->assertSame(2, $this->warningCount());
    }

    /**
     * The warning is model-gated with DeepSeek-V4 returning first, so a
     * skipped request must not consume the id: the same result later sent to
     * a warned model is still warned.
     */
    public function testASkippedDeepSeekRequestDoesNotConsumeTheId(): void
    {
        $provider = $this->batchProvider();
        $results = [new ToolResultMessage('call_read', '</parameter>')];

        $provider->complete(new CompleteRequest(model: self::DEEPSEEK_V4, messages: $results));
        $this->assertSame('', $this->capturedLog());

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: $results));
        $this->assertSame(1, $this->warningCountFor('call_read'));
    }

    /**
     * The remembered set is bounded: past the cap the OLDEST id is forgotten
     * (and so would be warned again), while a recent one stays remembered.
     */
    public function testTheCapEvictsTheOldestRememberedId(): void
    {
        $cap = (new \ReflectionClassConstant(SglangProvider::class, 'TRUNCATION_RISK_WARNED_CAP'))->getValue();
        $this->assertIsInt($cap);

        $provider = $this->batchProvider();

        // cap + 1 distinct ids, sent as one trailing batch: the last insert
        // evicts the first.
        $batch = [];
        for ($i = 0; $i <= $cap; $i++) {
            $batch[] = new ToolResultMessage('call_' . $i, '</parameter>');
        }
        $provider->complete($this->requestEndingIn($batch));
        $this->assertSame($cap + 1, $this->warningCount());

        @unlink($this->logFile);

        // The most recent id is still remembered...
        $provider->complete($this->requestEndingIn([new ToolResultMessage('call_' . $cap, '</parameter>')]));
        $this->assertSame('', $this->capturedLog());

        // ...the evicted oldest one is warned again.
        $provider->complete($this->requestEndingIn([new ToolResultMessage('call_0', '</parameter>')]));
        $this->assertSame(1, $this->warningCountFor('call_0'));
        $this->assertSame(1, $this->warningCount());
    }
}
