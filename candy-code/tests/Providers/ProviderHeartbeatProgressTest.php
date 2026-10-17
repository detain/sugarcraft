<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\SglangProvider;

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
 *
 * ISS-076 extends the seam to the STREAMING path of both shared-transport
 * providers: the same wrapper rides the stream request, and one immediate
 * beat fires the moment response headers land - so a server that withholds
 * its first body frame still resets the EngineBackend idle timer from the
 * transport up. The streaming arms pin presence/absence on the request
 * options, and that exactly one header beat lands per stream without the
 * MockHandler ever ringing the callback.
 */
final class ProviderHeartbeatProgressTest extends TestCase
{
    private const BATCH_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    private const STREAM_BODY = "data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\ndata: [DONE]\n\n";

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

    /**
     * ISS-076: drive the streaming path to its FIRST yielded chunk and stop.
     * The POST has completed and the header beat has landed by then; the
     * remaining SSE frames and the [DONE] sentinel go unread on purpose -
     * stopping early is what proves the beat rides the headers, not the tail.
     */
    private function customStreamFirstChunk(?\Closure $onHeartbeat): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], self::STREAM_BODY)]));
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
        foreach ($provider->completeStream(new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('hi')],
            onHeartbeat: $onHeartbeat,
        )) as $chunk) {
            return;
        }
    }

    private function sglangStreamFirstChunk(?\Closure $onHeartbeat): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], self::STREAM_BODY)]));
        $stack->push(Middleware::history($this->history));

        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
        foreach ($provider->completeStream(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
            onHeartbeat: $onHeartbeat,
        )) as $chunk) {
            return;
        }
    }

    public function testTheStreamingPathCarriesNoProgressOptionWhenNoHeartbeatIsAskedFor(): void
    {
        $this->customStreamFirstChunk(null);
        $this->assertArrayNotHasKey('progress', $this->sentOptions());

        $this->sglangStreamFirstChunk(null);
        $this->assertArrayNotHasKey('progress', $this->sentOptions());
    }

    public function testTheStreamingPathForwardsTheHeartbeatOntoTheProgressCallback(): void
    {
        $this->customStreamFirstChunk(static function (): void {});
        $this->assertIsCallable($this->sentOptions()['progress']);

        $this->sglangStreamFirstChunk(static function (): void {});
        $this->assertIsCallable($this->sentOptions()['progress']);
    }

    public function testTheHeaderBeatLandsExactlyOnceAndNeedsNoTransferTick(): void
    {
        $customBeats = 0;
        $this->customStreamFirstChunk(static function () use (&$customBeats): void {
            ++$customBeats;
        });
        $this->assertSame(
            1,
            $customBeats,
            'the streaming path must deliver one beat when headers land - and only that one, since MockHandler never rings the wrapper',
        );

        $sglangBeats = 0;
        $this->sglangStreamFirstChunk(static function () use (&$sglangBeats): void {
            ++$sglangBeats;
        });
        $this->assertSame(1, $sglangBeats);
    }

    public function testAThrowingHeaderBeatIsSwallowedAndTheStreamStillParses(): void
    {
        $boom = static function (): void {
            throw new \LogicException('heartbeat exploded at the headers');
        };
        $chunks = [];
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], self::STREAM_BODY)]));
        $stack->push(Middleware::history($this->history));
        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
        foreach ($provider->completeStream(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
            onHeartbeat: $boom,
        )) as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotEmpty($chunks, 'a detonating header beat must cost the stream nothing');
    }
}
