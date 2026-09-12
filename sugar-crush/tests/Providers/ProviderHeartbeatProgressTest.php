<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * E493 - the batch-completion progress heartbeat.
 *
 * A batch `complete()` blocks inside the transport for as long as the server
 * thinks; E524 measured that pcntl signals cannot fire inside a blocking
 * `curl_exec()`, so the only carrier for "still alive" telemetry on that path
 * is libcurl's progress callback, surfaced by Guzzle as the `progress`
 * request option. These tests pin the three laws of the seam:
 *
 *   1. ABSENCE IS BYTE-NEUTRAL - no heartbeat asked for, no `progress` key on
 *      the outgoing request options at all.
 *   2. FAIL-SOFT - a heartbeat that throws is swallowed; the completion still
 *      parses. And the wrapper always answers libcurl "keep transferring"
 *      (false), so a consumer cannot accidentally abort its own paid request.
 *   3. THROTTLED, FIRST BEAT IMMEDIATE - at most one delivery per interval,
 *      but the first tick of a request never waits behind the throttle.
 *
 * MockHandler never INVOKES `progress` (it is a transport feature), so the
 * throttle/fail-soft arms drive the captured callable directly - that
 * callable IS the wrapper the real transport would have rung, which is
 * exactly what is under test.
 */
final class ProviderHeartbeatProgressTest extends TestCase
{
    private const BATCH_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private function sglang(?\Closure $onHeartbeat): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], self::BATCH_BODY)]));
        $stack->push(Middleware::history($this->history));

        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
            onHeartbeat: $onHeartbeat,
        ));

        return $provider;
    }

    /** @return array<string, mixed> the captured request options of the last send */
    private function sentOptions(): array
    {
        $this->assertNotEmpty($this->history, 'the provider never issued its request');

        return $this->history[\count($this->history) - 1]['options'];
    }

    public function testTheDtoDefaultsToNoHeartbeat(): void
    {
        $request = new CompleteRequest(model: 'm', messages: []);

        $this->assertNull($request->onHeartbeat);
    }

    public function testSglangCarriesNoProgressOptionWhenNoHeartbeatIsAskedFor(): void
    {
        $this->sglang(null);

        $this->assertArrayNotHasKey('progress', $this->sentOptions());
    }

    public function testSglangAttachesTheProgressOptionWhenAHeartbeatIsSupplied(): void
    {
        $this->sglang(static function (): void {});

        $this->assertArrayHasKey('progress', $this->sentOptions());
        $this->assertIsCallable($this->sentOptions()['progress']);
    }

    public function testCustomCarriesNoProgressOptionWhenNoHeartbeatIsAskedFor(): void
    {
        $this->custom(null);

        $this->assertArrayNotHasKey('progress', $this->sentOptions());
    }

    public function testCustomAttachesTheProgressOptionWhenAHeartbeatIsSupplied(): void
    {
        $this->custom(static function (): void {});

        $this->assertArrayHasKey('progress', $this->sentOptions());
        $this->assertIsCallable($this->sentOptions()['progress']);
    }

    public function testTheFirstTickDeliversAndTheNextImmediateTickIsThrottled(): void
    {
        $beats = 0;
        $this->sglang(static function () use (&$beats): void {
            ++$beats;
        });

        $progress = $this->sentOptions()['progress'];

        // (downloadTotal, downloaded, uploadTotal, uploaded) - values are
        // irrelevant to the throttle, which runs on wall-clock.
        $this->assertSame(false, $progress(0, 0, 0, 0), 'first tick must deliver');
        $this->assertSame(1, $beats);
        $this->assertSame(false, $progress(0, 1, 0, 0), 'a same-instant second tick must be throttled');
        $this->assertSame(1, $beats, 'the throttled tick must not reach the consumer');
    }

    public function testAThrowingHeartbeatIsSwallowedAndTheCompletionStillLands(): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], self::BATCH_BODY),
            new Response(200, [], self::BATCH_BODY),
        ]));
        $stack->push(Middleware::history($this->history));

        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );

        // Capture the wrapper BEFORE the send, then ring it with a consumer
        // that detonates - the transport would do exactly this mid-transfer.
        $boom = static function (): void {
            throw new \LogicException('heartbeat exploded');
        };
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
            onHeartbeat: $boom,
        ));
        $progress = $this->sentOptions()['progress'];

        $this->assertSame(false, $progress(0, 0, 0, 0), 'wrapper must never abort the transfer');

        // And the real end-to-end guarantee: a detonating heartbeat costs the
        // request nothing - a fresh send with the same closure parses fine.
        $response = $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
            onHeartbeat: $boom,
        ));

        $this->assertSame('ok', $response->content);
        $this->assertFalse($response->isError);
    }

    public function testTheWrapperAlwaysAnswersKeepTransferringEvenIfTheConsumerReturnsTruthy(): void
    {
        $this->sglang(static fn (): bool => true);

        $progress = $this->sentOptions()['progress'];

        $this->assertSame(false, $progress(0, 0, 0, 0));
    }

    private function custom(?\Closure $onHeartbeat): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], self::BATCH_BODY)]));
        $stack->push(Middleware::history($this->history));

        $provider = new CustomProvider(
            'test-provider',
            'https://api.example.com',
            'gpt-4',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
            true,
            true,
        );
        $provider->complete(new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('hi')],
            onHeartbeat: $onHeartbeat,
        ));
    }
}
