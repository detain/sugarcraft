<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * SGLang spells a cold request's cache read as `"prompt_tokens_details":null`
 * even with `--enable-cache-report` on (live-checks.md LIVE-0.1, measured
 * 2026-10-05), and a hit as `{"cached_tokens":N}`. On its own that null is
 * indistinguishable from a server with no cache reporting, so it stays
 * UNREPORTED; once the same provider instance has parsed a populated
 * `cached_tokens`, the server has proven it reports, and the null is read as
 * the measured zero it is — what lets the status bar's cache share and
 * `CacheHealthWatch` count the cold request.
 */
final class SglangColdCacheReportTest extends TestCase
{
    private const COLD = ['prompt_tokens' => 2409, 'total_tokens' => 2420, 'completion_tokens' => 11, 'prompt_tokens_details' => null, 'reasoning_tokens' => 4];

    private const HIT = ['prompt_tokens' => 2409, 'total_tokens' => 2420, 'completion_tokens' => 11, 'prompt_tokens_details' => ['cached_tokens' => 2368], 'reasoning_tokens' => 4];

    public function testAColdNullBeforeAnyReportStaysUnreported(): void
    {
        $usage = $this->provider()->parseUsage(self::COLD);

        self::assertNull($usage->cacheReadTokens, 'no report seen yet: the server may simply not report');
        self::assertSame(2409, $usage->inputTokens);
    }

    public function testAColdNullAfterAReportIsAMeasuredZero(): void
    {
        $provider = $this->provider();

        $hit = $provider->parseUsage(self::HIT);
        self::assertSame(2368, $hit->cacheReadTokens);
        self::assertSame(41, $hit->inputTokens);

        $cold = $provider->parseUsage(self::COLD);
        self::assertSame(0, $cold->cacheReadTokens, 'a proven-reporting server\'s null is a zero');
        self::assertSame(2409, $cold->inputTokens, 'nothing was cached, so the whole prompt is fresh input');
        self::assertNull($cold->cacheCreationTokens, 'the protocol has no cache-creation field — still never invented');
    }

    public function testOnlyTheMeasuredColdShapeIsUpgraded(): void
    {
        $provider = $this->provider();
        $provider->parseUsage(self::HIT);

        $absent = self::COLD;
        unset($absent['prompt_tokens_details']);
        self::assertNull($provider->parseUsage($absent)->cacheReadTokens, 'a document without the key says nothing about the cache');

        $noPrompt = self::COLD;
        unset($noPrompt['prompt_tokens']);
        self::assertNull($provider->parseUsage($noPrompt)->cacheReadTokens, 'a document that names no prompt is not a cold request');
    }

    public function testTheProofBelongsToOneProviderInstance(): void
    {
        $this->provider()->parseUsage(self::HIT);

        self::assertNull($this->provider()->parseUsage(self::COLD)->cacheReadTokens);
    }

    /** Through the real batch path: a hit, then a cold reply, on one provider. */
    public function testTheBatchPathCarriesTheZeroOnTheResponse(): void
    {
        $body = static fn (array $usage): string => (string) json_encode([
            'id' => 'x',
            'object' => 'chat.completion',
            'model' => 'm',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ]);
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturnOnConsecutiveCalls(
            new Response(200, [], $body(self::COLD)),
            new Response(200, [], $body(self::HIT)),
            new Response(200, [], $body(self::COLD)),
        );
        $provider = new SglangProvider('https://api.example.com', 'm', null, $httpClient);
        $request = new CompleteRequest(model: 'm', messages: [new UserMessage('hi')]);

        self::assertNull($provider->complete($request)->usage?->cacheReadTokens);
        self::assertSame(2368, $provider->complete($request)->usage?->cacheReadTokens);
        self::assertSame(0, $provider->complete($request)->usage?->cacheReadTokens);
    }

    private function provider(): SglangProvider
    {
        return new SglangProvider('https://api.example.com', 'm', null, $this->createMock(Client::class));
    }
}
